<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use App\Models\ApiKeyDailyUsage;
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\AutomationRun;
use App\Models\EmailMessage;
use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
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
        $this->report('Email records and their events', $this->deleteInBatches(EmailMessage::query()->where('created_at', '<', now()->subMonths($retention['messages_months']))->whereNotIn('status', MessageStatus::inFlight())));
        $this->report('Automation runs', $this->deleteInBatches(AutomationRun::acrossClients()->where('created_at', '<', now()->subDays($retention['automation_runs_days']))));
        $this->report('Webhook deliveries', $this->deleteInBatches(WebhookDelivery::acrossClients()->where('created_at', '<', now()->subDays($retention['webhook_deliveries_days']))->where('status', '!=', WebhookDelivery::PENDING)));
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
                $cleared += EmailMessage::query()->whereKey($messages->modelKeys())->update([
                    'html_body' => null,
                    'text_body' => null,
                    'attachments' => null,
                    'variables' => null,
                ]);

                $this->deleteUnreferencedAttachments($disk, $messages->flatMap(fn (EmailMessage $message): array => array_column($message->attachments ?? [], 'path'))->unique()->values()->all());
            });

        return $cleared;
    }

    /**
     * Compose and API sends store one copy of an attachment for all their recipients, and some of those
     * messages may still be waiting to send. A file is only deleted once no message refers to it.
     *
     * @param  list<string>  $paths
     */
    private function deleteUnreferencedAttachments(Filesystem $disk, array $paths): void
    {
        $unreferenced = array_values(array_filter($paths, function (string $path): bool {
            // Stored names start with a unique ULID or UUID, which JSON writes the same way on every database.
            preg_match('/^[A-Za-z0-9._-]+/', basename($path), $match);

            if (strlen($match[0] ?? '') < 6) {
                return false;
            }

            return ! EmailMessage::query()->where('attachments', 'like', '%'.$match[0].'%')->exists();
        }));

        if ($unreferenced !== []) {
            $disk->delete($unreferenced);
        }
    }

    private function report(string $what, int $count): void
    {
        $this->components->twoColumnDetail($what, $count > 0 ? "<fg=yellow>{$count} removed</>" : '<fg=gray>nothing to remove</>');
    }
}
