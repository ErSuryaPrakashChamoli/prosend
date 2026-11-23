<?php

namespace App\Services\Automation;

use App\Actions\Messages\QueueComposedEmails;
use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionType;
use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Enums\MessageSource;
use App\Enums\OutreachEvent;
use App\Enums\Permission;
use App\Enums\SequenceStatus;
use App\Enums\TemplateStatus;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Task;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Campaigns\SequenceProgress;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Runs a client's automation rules for one event. Must run as the client.
 *
 * A rule runs at most once per event occurrence, conditions must all hold, and actions run in order. An action that
 * fails is logged and the rest still run. Every email an action sends goes through the usual consent, suppression and
 * sending checks.
 */
class AutomationEngine
{
    public function __construct(
        private SequenceProgress $sequences,
        private QueueComposedEmails $composer,
        private OutreachEvents $events,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(OutreachEvent $event, int $clientId, ?Contact $contact, array $data, string $occurrence, int $depth): void
    {
        AutomationRule::query()
            ->where('client_id', $clientId)
            ->where('trigger', $event)
            ->where('is_active', true)
            ->orderBy('id')
            ->each(fn (AutomationRule $rule) => $this->events->atDepth($depth, fn () => $this->run($rule, $event, $contact, $data, $occurrence)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function run(AutomationRule $rule, OutreachEvent $event, ?Contact $contact, array $data, string $occurrence): ?AutomationRun
    {
        try {
            $run = AutomationRun::query()->create([
                'client_id' => $rule->client_id,
                'automation_rule_id' => $rule->getKey(),
                'contact_id' => $contact?->getKey(),
                'trigger' => $event->value,
                'dedupe_key' => Str::limit($occurrence, 100, ''),
                'status' => AutomationRun::RUNNING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            return $this->execute($run, $rule, $contact, $data);
        } catch (Throwable $exception) {
            // A retry would find this run and skip it, so the failure is recorded rather than left looking skipped.
            $run->forceFill(['status' => AutomationRun::FAILED, 'error' => Str::limit($exception->getMessage(), 500)])->save();
            report($exception);

            return $run;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function execute(AutomationRun $run, AutomationRule $rule, ?Contact $contact, array $data): AutomationRun
    {
        $unmet = $this->unmetCondition($rule, $contact, $data);

        if ($unmet !== null) {
            $run->forceFill(['status' => AutomationRun::SKIPPED, 'log' => [['action' => 'conditions', 'result' => "Not run: {$unmet}"]]])->save();

            return $run;
        }

        $log = [];
        $failed = false;

        foreach ($rule->actions ?? [] as $action) {
            $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            try {
                $log[] = ['action' => $type->value, 'result' => $this->perform($type, (array) ($action['data'] ?? []), $rule, $contact, $data)];
            } catch (Throwable $exception) {
                $failed = true;
                $log[] = ['action' => $type->value, 'result' => 'Failed: '.Str::limit($exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage(), 300)];
                report($exception);
            }
        }

        $run->forceFill(['status' => $failed ? AutomationRun::FAILED : AutomationRun::SUCCEEDED, 'log' => $log])->save();
        // A plain update: counting runs must not fire model events or clear cached rule lookups.
        AutomationRule::query()->whereKey($rule->getKey())->update(['run_count' => DB::raw('run_count + 1'), 'last_run_at' => now()]);

        return $run;
    }

    /**
     * The first condition that does not hold, described, or null when all hold.
     *
     * @param  array<string, mixed>  $data
     */
    private function unmetCondition(AutomationRule $rule, ?Contact $contact, array $data): ?string
    {
        foreach ($rule->conditions ?? [] as $condition) {
            $type = AutomationConditionType::tryFrom((string) ($condition['type'] ?? ''));
            $value = $condition['data']['value'] ?? null;

            if ($type === null) {
                continue;
            }

            $holds = match ($type) {
                AutomationConditionType::StageIs => $contact?->stage === ContactStage::tryFrom((string) $value),
                AutomationConditionType::HasTag => $contact !== null && in_array(Str::lower(trim((string) $value)), array_map(Str::lower(...), $contact->tags ?? []), true),
                AutomationConditionType::IndustryIs => $contact !== null && Str::lower(trim((string) $contact->industry)) === Str::lower(trim((string) $value)),
                AutomationConditionType::CampaignIs => (int) ($data['campaign_id'] ?? 0) === (int) $value,
                AutomationConditionType::ConsentGranted => $contact?->consent_status === ConsentStatus::Granted,
                AutomationConditionType::HasOwner => $contact?->owner_id !== null,
            };

            if (! $holds) {
                return $type->getLabel().($value !== null ? " \"{$value}\"" : '').' did not hold';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $data
     */
    private function perform(AutomationActionType $type, array $settings, AutomationRule $rule, ?Contact $contact, array $data): string
    {
        if ($type->needsProspect() && $contact === null) {
            return 'Skipped: this event is not about a prospect.';
        }

        return match ($type) {
            AutomationActionType::AddTag => $this->addTag($contact, (string) ($settings['tag'] ?? '')),
            AutomationActionType::RemoveTag => $this->removeTag($contact, (string) ($settings['tag'] ?? '')),
            AutomationActionType::SetStage => $this->setStage($contact, ContactStage::from((string) $settings['stage'])),
            AutomationActionType::AssignOwner => $this->assignOwner($contact, $rule, (int) ($settings['user_id'] ?? 0)),
            AutomationActionType::CreateTask => $this->createTask($contact, $rule, $settings),
            AutomationActionType::Notify => $this->notify($contact, $rule, $settings),
            AutomationActionType::StopSequences => $this->sequences->stopForContact($contact, SequenceStatus::Stopped, "Stopped by automation \"{$rule->name}\"").' sequence(s) stopped',
            AutomationActionType::SendEmail => $this->sendEmail($contact, $rule, $settings),
            AutomationActionType::SendWebhook => $this->sendWebhook($rule, $contact, (int) ($settings['webhook_endpoint_id'] ?? 0), $data),
        };
    }

    private function addTag(Contact $contact, string $tag): string
    {
        $tag = trim($tag);
        $contact->forceFill(['tags' => array_values(array_unique([...($contact->tags ?? []), $tag]))])->save();

        return "Tagged \"{$tag}\"";
    }

    private function removeTag(Contact $contact, string $tag): string
    {
        $contact->forceFill(['tags' => array_values(array_diff($contact->tags ?? [], [trim($tag)]))])->save();

        return "Removed tag \"{$tag}\"";
    }

    private function setStage(Contact $contact, ContactStage $stage): string
    {
        $contact->forceFill(['stage' => $stage])->save();

        return "Stage set to {$stage->getLabel()}";
    }

    private function assignOwner(Contact $contact, AutomationRule $rule, int $userId): string
    {
        $owner = User::query()->memberOf($rule->client_id)->findOrFail($userId);
        $contact->forceFill(['owner_id' => $owner->getKey()])->save();

        return "Assigned to {$owner->name}";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createTask(?Contact $contact, AutomationRule $rule, array $settings): string
    {
        $task = new Task([
            'contact_id' => $contact?->getKey(),
            'title' => Str::limit($this->fill((string) ($settings['title'] ?? 'Follow up'), $contact), 250, ''),
            'due_at' => now()->addDays(max(0, (int) ($settings['due_in_days'] ?? 1))),
            'assigned_to' => $this->recipient($settings['assign_to'] ?? 'owner', $contact, $rule)?->getKey(),
        ]);

        $task->forceFill(['client_id' => $rule->client_id, 'automation_rule_id' => $rule->getKey()])->save();

        return "Task created: {$task->title}";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function notify(?Contact $contact, AutomationRule $rule, array $settings): string
    {
        $recipient = $this->recipient($settings['user_id'] ?? 'owner', $contact, $rule);

        if ($recipient === null) {
            return 'Skipped: nobody to notify (the prospect has no owner).';
        }

        Notification::make()
            ->title(e(Str::limit($this->fill((string) ($settings['message'] ?? $rule->name), $contact), 200)))
            ->body(e($contact === null ? "Automation: {$rule->name}" : "{$contact->email} · automation: {$rule->name}"))
            ->sendToDatabase($recipient);

        return "Notified {$recipient->name}";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendEmail(Contact $contact, AutomationRule $rule, array $settings): string
    {
        // Emailing from a rule is like approving a small campaign: whoever set it up must still be allowed to.
        $creator = $rule->creator;

        if ($creator === null || ! $creator->hasPermission(Permission::ComposeEmail, $rule->client_id) || ! $creator->hasPermission(Permission::ApproveCampaigns, $rule->client_id)) {
            throw new RuntimeException('Whoever created this rule may no longer send email or approve campaigns in this client, so it sends nothing. Someone allowed to should save it again.');
        }

        $automationEmails = EmailMessage::query()->where('source', MessageSource::Automation)->where('created_at', '>=', now()->subDay());

        if ((clone $automationEmails)->where('contact_id', $contact->getKey())->count() >= config('prosend.automation.max_emails_per_prospect_per_day')) {
            return 'Skipped: this prospect already got the most automation emails allowed in a day.';
        }

        if ((clone $automationEmails)->whereJsonContains('tags', "rule:{$rule->getKey()}")->count() >= config('prosend.automation.max_emails_per_rule_per_day')) {
            return 'Skipped: this rule already sent the most emails allowed in a day.';
        }

        $template = EmailTemplate::query()->where('status', TemplateStatus::Active)->findOrFail((int) ($settings['email_template_id'] ?? 0));

        $this->composer->handle([
            'sender_identity_id' => (int) ($settings['sender_identity_id'] ?? 0),
            'purpose' => $template->purpose->value,
            'email_template_id' => $template->getKey(),
            'to' => [$contact->email],
            'subject' => $template->subject,
            'body' => $template->body,
            'text_body' => $template->text_body,
        ], $creator, ['source' => MessageSource::Automation, 'tags' => ['automation', "rule:{$rule->getKey()}"]]);

        return "Email \"{$template->name}\" queued";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendWebhook(AutomationRule $rule, ?Contact $contact, int $endpointId, array $data): string
    {
        $endpoint = WebhookEndpoint::query()->where('is_enabled', true)->findOrFail($endpointId);
        $event = $rule->trigger;

        $this->events->queueDelivery($endpoint, $event, [...OutreachEvents::payload($event, $contact, $data), 'automation' => $rule->name]);

        // Only the host: webhook URLs often carry a token.
        return 'Webhook queued to '.parse_url($endpoint->url, PHP_URL_HOST);
    }

    /**
     * "owner" means the prospect's owner; anything else is a member's id.
     */
    private function recipient(mixed $choice, ?Contact $contact, AutomationRule $rule): ?User
    {
        if ($choice === 'owner') {
            $owner = $contact?->owner;

            // Someone who left the client keeps no access to its prospects, so they hear nothing about them.
            return $owner !== null && ($owner->isSuperAdmin() || $owner->roleIn($rule->client_id) !== null) ? $owner : null;
        }

        return User::query()->memberOf($rule->client_id)->find((int) $choice);
    }

    /**
     * {{ email }}, {{ first_name }} and {{ company_name }} in task titles and notifications.
     */
    private function fill(string $text, ?Contact $contact): string
    {
        return strtr($text, [
            '{{ email }}' => (string) $contact?->email,
            '{{ first_name }}' => (string) $contact?->first_name,
            '{{ company_name }}' => (string) $contact?->company,
        ]);
    }
}
