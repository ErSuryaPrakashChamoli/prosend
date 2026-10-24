<?php

namespace App\Services\Campaigns;

use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailMessage;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;

class CampaignDispatcher
{
    /**
     * Create one queued message per eligible contact. Safe to run again: existing messages are kept.
     */
    public function createMessages(Campaign $campaign): int
    {
        $created = 0;

        $campaign->eligibleAudienceQuery()
            ->select(['id', 'email', 'first_name', 'last_name'])
            ->chunkById(config('prosend.campaigns.chunk_size'), function (Collection $contacts) use ($campaign, &$created): void {
                $created += EmailMessage::query()->fillAndInsertOrIgnore($contacts->map(fn (Contact $contact): array => [
                    'campaign_id' => $campaign->getKey(),
                    'contact_id' => $contact->getKey(),
                    'sender_identity_id' => $campaign->sender_identity_id,
                    'email_template_id' => $campaign->email_template_id,
                    'created_by' => $campaign->created_by,
                    'purpose' => $campaign->purpose,
                    'source' => MessageSource::Campaign,
                    'status' => MessageStatus::Queued,
                    'to_email' => $contact->email,
                    'to_name' => $contact->full_name,
                    'subject' => $campaign->subject,
                    'queued_at' => now(),
                ])->all());
            });

        return $created;
    }

    /**
     * Queue a send job for every waiting message in a new batch, spaced out at the campaign's rate.
     */
    public function dispatchPending(Campaign $campaign): void
    {
        $secondsBetweenMessages = 60 / max(1, $campaign->send_rate_per_minute);
        $position = 0;
        $batch = null;

        $campaign->messages()
            ->whereIn('status', [MessageStatus::Queued, MessageStatus::Deferred])
            ->chunkById(config('prosend.campaigns.chunk_size'), function (Collection $messages) use ($campaign, $secondsBetweenMessages, &$position, &$batch): void {
                $jobs = $messages->values()->map(
                    fn (EmailMessage $message, int $index): SendEmailMessage => (new SendEmailMessage($message))
                        ->delay(now()->addSeconds((int) floor(($position + $index) * $secondsBetweenMessages))),
                )->all();

                $position += $messages->count();

                if ($batch instanceof Batch) {
                    $batch->add($jobs);

                    return;
                }

                $batch = Bus::batch($jobs)
                    ->name("Campaign #{$campaign->getKey()}: {$campaign->name}")
                    ->allowFailures()
                    ->onQueue(config('prosend.sending.queue'))
                    ->dispatch();

                $campaign->forceFill(['batch_id' => $batch->id])->save();
            });
    }

    public function cancelBatch(Campaign $campaign): void
    {
        if ($campaign->batch_id !== null) {
            Bus::findBatch($campaign->batch_id)?->cancel();
        }
    }
}
