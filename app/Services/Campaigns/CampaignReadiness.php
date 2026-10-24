<?php

namespace App\Services\Campaigns;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Enums\ConsentStatus;
use App\Enums\EmailPurpose;
use App\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\SendingDomain;
use App\Services\Warmup\WarmupGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Everything an operator needs to know before a campaign is launched, and what prevents it.
 */
class CampaignReadiness
{
    public function __construct(private WarmupGate $warmupGate) {}

    public function assess(Campaign $campaign): ReadinessReport
    {
        $isMarketing = $campaign->purpose === EmailPurpose::Marketing;

        $audienceCount = $campaign->audienceQuery()->count();
        $permittedCount = $campaign->permittedAudienceQuery()->count();
        $eligibleCount = $campaign->eligibleAudienceQuery()->count();

        $suppressedCount = $campaign->audienceQuery()
            ->whereHas('suppression', fn (Builder $suppression) => $suppression->whereIn('reason', SuppressionReason::blocking($campaign->purpose)))
            ->count();

        $unsubscribedCount = $isMarketing ? $campaign->audienceQuery()->whereNotNull('unsubscribed_at')->count() : 0;

        $withoutConsentCount = $isMarketing
            ? $campaign->audienceQuery()->where('consent_status', '!=', ConsentStatus::Granted)->count()
            : 0;

        $provider = $campaign->senderIdentity?->smtpProvider;
        $remainingQuota = $provider?->remainingQuota();
        $estimatedMinutes = (int) ceil($eligibleCount / max(1, $campaign->send_rate_per_minute));

        $blockingIssues = $this->blockingIssues($campaign, $audienceCount, $eligibleCount);
        $warnings = [];

        if ($remainingQuota !== null && $eligibleCount > $remainingQuota) {
            $warnings[] = 'The provider has '.Number::format($remainingQuota).' sends left in its current limit. The remaining emails will wait until the limit resets.';
        }

        if ($campaign->senderIdentity && ! $campaign->senderIdentity->isVerified() && $campaign->senderIdentity->canSend()) {
            $warnings[] = 'The sender identity is not verified. This is only allowed because sender verification is not required in this environment.';
        }

        if ($campaign->senderIdentity !== null) {
            $warnings = [...$warnings, ...$this->deliverabilityWarnings($campaign, $eligibleCount)];
        }

        return new ReadinessReport(
            audienceCount: $audienceCount,
            eligibleCount: $eligibleCount,
            suppressedCount: $suppressedCount,
            unsubscribedCount: $unsubscribedCount,
            withoutConsentCount: $withoutConsentCount,
            duplicateCount: $this->duplicateCount($campaign, $audienceCount),
            missingDataCount: $permittedCount - $eligibleCount,
            remainingQuota: $remainingQuota,
            estimatedMinutes: $estimatedMinutes,
            blockingIssues: $blockingIssues,
            warnings: $warnings,
        );
    }

    /**
     * @return list<string>
     */
    private function blockingIssues(Campaign $campaign, int $audienceCount, int $eligibleCount): array
    {
        $issues = [];

        if ($campaign->status !== CampaignStatus::Draft) {
            $issues[] = 'Only draft campaigns can be launched.';
        }

        $template = $campaign->emailTemplate;

        if ($template !== null && $template->purpose !== $campaign->purpose) {
            $issues[] = "The template \"{$template->name}\" is for {$template->purpose->getLabel()} email and cannot be used in a {$campaign->purpose->getLabel()} campaign.";
        }

        if (blank($campaign->subject)) {
            $issues[] = 'Add a subject.';
        }

        if (blank(strip_tags((string) $campaign->body))) {
            $issues[] = 'Add the email content.';
        }

        $senderIdentity = $campaign->senderIdentity;

        if ($senderIdentity === null) {
            $issues[] = 'Choose a sender identity.';
        } elseif (! $senderIdentity->canSend()) {
            $issues[] = "The sender {$senderIdentity->email} must be verified with the provider before it can send.";
        } elseif (! $senderIdentity->smtpProvider->is_enabled) {
            $issues[] = "The provider {$senderIdentity->smtpProvider->name} is disabled.";
        }

        if ($audienceCount === 0) {
            $issues[] = match ($campaign->audience_type) {
                AudienceType::Lists => 'Choose at least one list that has contacts.',
                AudienceType::SelectedContacts => 'Select at least one contact.',
                AudienceType::Segment => $campaign->segment === null ? 'Choose a segment.' : 'The segment does not match any contacts.',
                AudienceType::AllContacts => 'There are no contacts yet.',
            };
        } elseif ($eligibleCount === 0) {
            $issues[] = filled($campaign->required_variables)
                ? 'None of the eligible contacts have values for all required variables ('.implode(', ', $campaign->required_variables).').'
                : 'None of the contacts in this audience are eligible to receive this email.';
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function deliverabilityWarnings(Campaign $campaign, int $eligibleCount): array
    {
        $senderIdentity = $campaign->senderIdentity;
        $domain = SendingDomain::forEmail($senderIdentity->email);
        $warnings = [];

        if ($domain === null) {
            $warnings[] = "Authentication for {$senderIdentity->domain()} has not been checked. Add it under Deliverability > Domain authentication.";
        } elseif (! $domain->isReady()) {
            $warnings[] = "{$domain->domain} is not fully authenticated: ".implode(' ', $domain->readinessIssues());
        }

        $plan = $this->warmupGate->planFor($senderIdentity->smtpProvider, $senderIdentity->email);

        if ($plan !== null && $eligibleCount > $plan->todaysLimit()) {
            $warnings[] = "The warm-up plan \"{$plan->name}\" allows {$plan->todaysLimit()} emails a day right now, so this campaign will take several days. The rest wait their turn automatically.";
        }

        return $warnings;
    }

    /**
     * Contacts that appear in more than one of the chosen lists; each still receives one email.
     */
    private function duplicateCount(Campaign $campaign, int $audienceCount): int
    {
        if ($campaign->audience_type !== AudienceType::Lists) {
            return 0;
        }

        $memberships = DB::table('contact_contact_list')
            ->whereIn('contact_list_id', $campaign->lists()->pluck('contact_lists.id'))
            ->count();

        return max(0, $memberships - $audienceCount);
    }
}
