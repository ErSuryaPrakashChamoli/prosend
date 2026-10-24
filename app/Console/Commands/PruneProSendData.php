<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use App\Models\ApiKeyDailyUsage;
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\IdempotencyKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Signature('prosend:prune')]
#[Description('Delete records older than the retention periods in config/prosend.php, in batches')]
class PruneProSendData extends Command
{
    public function handle(): int
    {
        $retention = config('prosend.retention');

        $this->report('API request logs', $this->deleteInBatches(ApiRequestLog::query()->where('created_at', '<', now()->subDays($retention['api_request_logs_days']))));
        $this->report('Idempotency keys', $this->deleteInBatches(IdempotencyKey::query()->where('created_at', '<', now()->subHours(IdempotencyKey::RETENTION_HOURS))));
        $this->report('API usage days', $this->deleteInBatches(ApiKeyDailyUsage::query()->where('date', '<', now()->subMonths($retention['api_usage_months'])->toDateString())));
        $this->report('Email bodies and attachments', $this->clearBodies(now()->subDays($retention['message_bodies_days'])));
        $this->report('Email records and their events', $this->deleteInBatches(EmailMessage::query()->where('created_at', '<', now()->subMonths($retention['messages_months']))));
        $this->report('Audit log entries', $this->deleteInBatches(AuditLog::query()->where('created_at', '<', now()->subMonths($retention['audit_logs_months']))));

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function deleteInBatches(Builder $query): int
    {
        $deleted = 0;

        $query->select($query->getModel()->getQualifiedKeyName())->chunkById(1000, function (Collection $records) use ($query, &$deleted): void {
            $deleted += $query->getModel()->newQuery()->whereKey($records->modelKeys())->delete();
        });

        return $deleted;
    }

    /**
     * Remove message content and attachment files, keeping the record, its status and its events.
     */
    private function clearBodies(\DateTimeInterface $before): int
    {
        $cleared = 0;
        $disk = Storage::disk(config('prosend.attachments.disk'));

        EmailMessage::query()
            ->where('created_at', '<', $before)
            ->whereNotIn('status', MessageStatus::inFlight())
            ->where(fn (Builder $messages) => $messages->whereNotNull('html_body')->orWhereNotNull('text_body')->orWhereNotNull('attachments')->orWhereNotNull('variables'))
            ->chunkById(500, function (Collection $messages) use ($disk, &$cleared): void {
                $paths = $messages->flatMap(fn (EmailMessage $message): array => array_column($message->attachments ?? [], 'path'))->all();

                if ($paths !== []) {
                    $disk->delete($paths);
                }

                $cleared += EmailMessage::query()->whereKey($messages->modelKeys())->update([
                    'html_body' => null,
                    'text_body' => null,
                    'attachments' => null,
                    'variables' => null,
                ]);
            });

        return $cleared;
    }

    private function report(string $what, int $count): void
    {
        $this->components->twoColumnDetail($what, $count > 0 ? "<fg=yellow>{$count} removed</>" : '<fg=gray>nothing to remove</>');
    }
}
