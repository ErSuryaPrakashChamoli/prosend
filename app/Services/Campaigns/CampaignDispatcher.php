<?php

namespace App\Services\Campaigns;

use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\SequenceStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignVariant;
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
        $variants = $campaign->firstEmailVariants()->get();
        $picker = app(VariantPicker::class);

        $campaign->eligibleAudienceQuery()
            ->select(['id', 'email', 'first_name', 'last_name'])
            ->chunkById(config('prosend.campaigns.chunk_size'), function (Collection $contacts) use ($campaign, $variants, $picker, &$created): void {
                // Each recipient joins the sequence; the first email goes out below, follow-ups from the SequenceRunner.
                CampaignProspect::query()->fillAndInsertOrIgnore($contacts->map(fn (Contact $contact): array => [
                    'client_id' => $campaign->client_id,
                    'campaign_id' => $campaign->getKey(),
                    'contact_id' => $contact->getKey(),
                    'status' => SequenceStatus::Active,
                    'current_step' => 0,
                ])->all());

                $created += EmailMessage::query()->fillAndInsertOrIgnore($contacts->map(fn (Contact $contact): array => [
                    ...$this->versionFor($campaign, $variants, $picker, $contact),
                    'client_id' => $campaign->client_id,
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
                    'queued_at' => now(),
                ])->all());
            });

        return $created;
    }

    /**
     * The A/B version a contact gets of the first email, and the subject that goes with it.
     *
     * @param  Collection<int, CampaignVariant>  $variants
     * @return array{campaign_variant_id: int|null, subject: string|null}
     */
    private function versionFor(Campaign $campaign, Collection $variants, VariantPicker $picker, Contact $contact): array
    {
        $variant = $picker->pick($variants, $campaign->getKey(), $contact->getKey(), 1);

        return [
            'campaign_variant_id' => $variant?->getKey(),
            'subject' => filled($variant?->subject) ? $variant->subject : $campaign->subject,
        ];
    }

    /**
     * Queue a send job for every waiting message in a new batch, spaced out at the campaign's rate.
     */
    public function dispatchPending(Campaign $campaign): void
    {
        $secondsBetweenMessages = 60 / max(1, $campaign->send_rate_per_minute);
        // With one window for everyone, send times are planned inside it. Per-prospect time zones are held back by
        // SendEmailMessage instead, when each email is due.
        $window = $campaign->use_prospect_timezone ? null : $campaign->sendingWindowFor();
        $window = $window?->isUnrestricted() ? null : $window;
        $position = 0;
        $windowCursor = now();
        $batch = null;

        $campaign->messages()
            ->whereIn('status', [MessageStatus::Queued, MessageStatus::Deferred])
            ->chunkById(config('prosend.campaigns.chunk_size'), function (Collection $messages) use ($campaign, $secondsBetweenMessages, $window, &$position, &$windowCursor, &$batch): void {
                $jobs = $messages->values()->map(function (EmailMessage $message, int $index) use ($secondsBetweenMessages, $window, $position, &$windowCursor): SendEmailMessage {
                    if ($window === null) {
                        return (new SendEmailMessage($message))->delay(now()->addSeconds((int) floor(($position + $index) * $secondsBetweenMessages)));
                    }

                    $windowCursor = $window->nextOpening($windowCursor);
                    $job = (new SendEmailMessage($message))->delay($windowCursor->copy());
                    $windowCursor = $windowCursor->copy()->addMilliseconds((int) round($secondsBetweenMessages * 1000));

                    return $job;
                })->all();

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
