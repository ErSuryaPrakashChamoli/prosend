<?php

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionType;
use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\OutreachEvent;
use App\Enums\SequenceStatus;
use App\Enums\SuppressionReason;
use App\Enums\SystemRole;
use App\Enums\TemplateStatus;
use App\Jobs\SendEmailMessage;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\OutreachEvents;
use Database\Factories\UserFactory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\FakeMailProviderDriver;

/**
 * @param  list<array{type: string, data: array<string, mixed>}>  $actions
 * @param  list<array{type: string, data: array<string, mixed>}>  $conditions
 */
function automationRule(OutreachEvent $trigger, array $actions, array $conditions = []): AutomationRule
{
    return AutomationRule::factory()->create(['trigger' => $trigger, 'actions' => $actions, 'conditions' => $conditions]);
}

function ruleAction(AutomationActionType $type, array $data = []): array
{
    return ['type' => $type->value, 'data' => $data];
}

function announceEvent(OutreachEvent $event, ?Contact $contact, array $data = [], ?string $occurrence = null): void
{
    app(OutreachEvents::class)->dispatch($event, Client::query()->where('slug', 'default')->value('id'), $contact, $data, $occurrence);
}

it('runs a rule\'s actions in order when its event happens', function () {
    $owner = User::factory()->create();
    $contact = Contact::factory()->create(['owner_id' => $owner->id, 'first_name' => 'Jane']);
    automationRule(OutreachEvent::EmailReplied, [
        ruleAction(AutomationActionType::AddTag, ['tag' => 'hot']),
        ruleAction(AutomationActionType::SetStage, ['stage' => ContactStage::Interested->value]),
        ruleAction(AutomationActionType::CreateTask, ['title' => 'Call {{ first_name }}', 'due_in_days' => 2, 'assign_to' => 'owner']),
        ruleAction(AutomationActionType::Notify, ['user_id' => 'owner', 'message' => '{{ email }} replied']),
    ]);

    announceEvent(OutreachEvent::EmailReplied, $contact);

    expect($contact->refresh())->tags->toContain('hot')->stage->toBe(ContactStage::Interested);
    expect(Task::query()->sole())->title->toBe('Call Jane')->assigned_to->toBe($owner->id)->due_at->isSameDay(now()->addDays(2))->toBeTrue();
    expect(DatabaseNotification::query()->where('notifiable_id', $owner->id)->count())->toBe(1);
    expect(AutomationRun::query()->sole())->status->toBe(AutomationRun::SUCCEEDED)->log->toHaveCount(4);
});

it('skips a rule whose conditions do not hold, and logs why', function () {
    $contact = Contact::factory()->create(['tags' => ['cold']]);
    automationRule(OutreachEvent::EmailReplied, [ruleAction(AutomationActionType::AddTag, ['tag' => 'hot'])], [['type' => AutomationConditionType::HasTag->value, 'data' => ['value' => 'vip']]]);

    announceEvent(OutreachEvent::EmailReplied, $contact);

    expect($contact->refresh()->tags)->toBe(['cold'])
        ->and(AutomationRun::query()->sole())->status->toBe(AutomationRun::SKIPPED)
        ->log->{0}->result->toContain('did not hold');
});

it('runs a rule once per occurrence, even when the event is announced twice', function () {
    $contact = Contact::factory()->create();
    automationRule(OutreachEvent::EmailReplied, [ruleAction(AutomationActionType::CreateTask, ['title' => 'Call', 'assign_to' => 'owner'])]);

    announceEvent(OutreachEvent::EmailReplied, $contact, occurrence: 'reply:1');
    announceEvent(OutreachEvent::EmailReplied, $contact, occurrence: 'reply:1');

    expect(Task::query()->count())->toBe(1);
});

it('stops rules that keep triggering each other', function () {
    $contact = Contact::factory()->create();
    automationRule(OutreachEvent::ProspectStageChanged, [ruleAction(AutomationActionType::SetStage, ['stage' => ContactStage::Interested->value])], [['type' => AutomationConditionType::StageIs->value, 'data' => ['value' => ContactStage::Replied->value]]]);
    automationRule(OutreachEvent::ProspectStageChanged, [ruleAction(AutomationActionType::SetStage, ['stage' => ContactStage::Replied->value])], [['type' => AutomationConditionType::StageIs->value, 'data' => ['value' => ContactStage::Interested->value]]]);

    $contact->update(['stage' => ContactStage::Replied]);

    expect(AutomationRun::query()->count())->toBeLessThanOrEqual(OutreachEvents::MAX_DEPTH * 2);
});

it('stops the prospect\'s sequences', function () {
    $contact = Contact::factory()->create();
    $prospect = CampaignProspect::query()->create(['campaign_id' => Campaign::factory()->prepared()->create()->id, 'contact_id' => $contact->id, 'next_step_due_at' => now()->addDay()]);
    automationRule(OutreachEvent::EmailOpened, [ruleAction(AutomationActionType::StopSequences)]);

    announceEvent(OutreachEvent::EmailOpened, $contact);

    expect($prospect->refresh()->status)->toBe(SequenceStatus::Stopped);
});

it('sends an email from a template through the usual checks', function () {
    Bus::fake([SendEmailMessage::class]);
    $sender = SenderIdentity::factory()->create();
    $template = EmailTemplate::factory()->create(['purpose' => EmailPurpose::Marketing, 'status' => TemplateStatus::Active, 'subject' => 'Thanks {{ first_name }}']);
    $consented = Contact::factory()->consented()->create();
    $notConsented = Contact::factory()->create(['consent_status' => ConsentStatus::Unknown]);
    automationRule(OutreachEvent::ProspectStageChanged, [ruleAction(AutomationActionType::SendEmail, ['email_template_id' => $template->id, 'sender_identity_id' => $sender->id])])
        ->forceFill(['created_by' => User::factory()->withClientRole(SystemRole::TeamLeader)->create()->id])->save();

    $consented->update(['stage' => ContactStage::Interested]);
    $notConsented->update(['stage' => ContactStage::Interested]);

    expect(EmailMessage::query()->sole())->to_email->toBe($consented->email)->source->value->toBe('automation');
    expect(AutomationRun::query()->where('contact_id', $notConsented->id)->sole()->status)->toBe(AutomationRun::FAILED);
});

it('skips prospect actions for campaign events but still notifies', function () {
    $user = User::factory()->create();
    automationRule(OutreachEvent::CampaignStarted, [ruleAction(AutomationActionType::AddTag, ['tag' => 'x']), ruleAction(AutomationActionType::Notify, ['user_id' => $user->id, 'message' => 'Campaign started'])]);

    announceEvent(OutreachEvent::CampaignStarted, null, ['campaign_id' => 1]);

    expect(AutomationRun::query()->sole()->log)->{0}->result->toStartWith('Skipped')
        ->and(DatabaseNotification::query()->where('notifiable_id', $user->id)->count())->toBe(1);
});

it('only runs the rules of the client the event happened in', function () {
    $other = Client::factory()->create();
    asClient($other, fn () => automationRule(OutreachEvent::ProspectCreated, [ruleAction(AutomationActionType::AddTag, ['tag' => 'theirs'])]));

    $contact = Contact::factory()->create();

    expect($contact->refresh()->tags)->toBeNull()
        ->and(AutomationRun::acrossClients()->count())->toBe(0);
});

it('announces real events: a new prospect, a reply stage, an unsubscribe', function () {
    automationRule(OutreachEvent::ProspectCreated, [ruleAction(AutomationActionType::AddTag, ['tag' => 'new'])]);
    automationRule(OutreachEvent::ProspectUnsubscribed, [ruleAction(AutomationActionType::AddTag, ['tag' => 'gone'])]);

    $contact = Contact::factory()->create();
    Suppression::record($contact->email, SuppressionReason::Unsubscribed, 'test');

    expect($contact->refresh()->tags)->toBe(['new', 'gone']);
});

it('never assigns an owner from another client', function () {
    $outsider = User::factory()->withoutClient()->create();
    UserFactory::addToClient($outsider, Client::factory()->create(), SystemRole::Admin);
    $contact = Contact::factory()->create();
    automationRule(OutreachEvent::EmailReplied, [ruleAction(AutomationActionType::AssignOwner, ['user_id' => $outsider->id])]);

    announceEvent(OutreachEvent::EmailReplied, $contact);

    expect($contact->refresh()->owner_id)->toBeNull()
        ->and(AutomationRun::query()->sole()->status)->toBe(AutomationRun::FAILED);
});

it('never lets automation emails start rules again, so rules cannot email each other forever', function () {
    Bus::fake([SendEmailMessage::class]);
    FakeMailProviderDriver::install();
    $sender = SenderIdentity::factory()->create();
    $template = EmailTemplate::factory()->create(['purpose' => EmailPurpose::Transactional, 'status' => TemplateStatus::Active]);
    $contact = Contact::factory()->consented()->create();
    automationRule(OutreachEvent::EmailSent, [ruleAction(AutomationActionType::SendEmail, ['email_template_id' => $template->id, 'sender_identity_id' => $sender->id])])
        ->forceFill(['created_by' => User::factory()->withClientRole(SystemRole::Admin)->create()->id])->save();

    $first = EmailMessage::factory()->for($contact)->for($sender)->create(['to_email' => $contact->email, 'source' => MessageSource::Compose]);
    app()->call([new SendEmailMessage($first), 'handle']);
    $automated = EmailMessage::query()->where('source', MessageSource::Automation)->sole();

    // As a worker would run it: a fresh job with nothing left over from the first.
    app()->forgetScopedInstances();
    app()->call([new SendEmailMessage($automated), 'handle']);

    expect(EmailMessage::query()->where('source', MessageSource::Automation)->count())->toBe(1);
});

it('stops emailing from a rule once its creator may no longer approve campaigns', function () {
    Bus::fake([SendEmailMessage::class]);
    $sender = SenderIdentity::factory()->create();
    $template = EmailTemplate::factory()->create(['purpose' => EmailPurpose::Transactional, 'status' => TemplateStatus::Active]);
    $creator = User::factory()->withClientRole(SystemRole::CampaignManager)->create();
    automationRule(OutreachEvent::EmailReplied, [ruleAction(AutomationActionType::SendEmail, ['email_template_id' => $template->id, 'sender_identity_id' => $sender->id])])
        ->forceFill(['created_by' => $creator->id])->save();

    announceEvent(OutreachEvent::EmailReplied, Contact::factory()->consented()->create());

    expect(EmailMessage::query()->count())->toBe(0)
        ->and(AutomationRun::query()->sole())->status->toBe(AutomationRun::FAILED)->log->{0}->result->toContain('may no longer send');
});

it('caps automation emails per prospect each day', function () {
    Bus::fake([SendEmailMessage::class]);
    config(['prosend.automation.max_emails_per_prospect_per_day' => 1]);
    $sender = SenderIdentity::factory()->create();
    $template = EmailTemplate::factory()->create(['purpose' => EmailPurpose::Transactional, 'status' => TemplateStatus::Active]);
    $contact = Contact::factory()->consented()->create();
    automationRule(OutreachEvent::EmailOpened, [ruleAction(AutomationActionType::SendEmail, ['email_template_id' => $template->id, 'sender_identity_id' => $sender->id])])
        ->forceFill(['created_by' => User::factory()->admin()->create()->id])->save();

    announceEvent(OutreachEvent::EmailOpened, $contact, occurrence: 'open:1');
    announceEvent(OutreachEvent::EmailOpened, $contact, occurrence: 'open:2');

    expect(EmailMessage::query()->count())->toBe(1);
});

it('does not notify an owner who left the client', function () {
    $former = User::factory()->create();
    ClientMembership::query()->where('user_id', $former->id)->delete();
    $contact = Contact::factory()->create(['owner_id' => $former->id]);
    automationRule(OutreachEvent::EmailReplied, [ruleAction(AutomationActionType::Notify, ['user_id' => 'owner', 'message' => 'Replied'])]);

    announceEvent(OutreachEvent::EmailReplied, $contact);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('sends nothing for a deactivated client', function () {
    automationRule(OutreachEvent::EmailReplied, [ruleAction(AutomationActionType::AddTag, ['tag' => 'x'])]);
    $contact = Contact::factory()->create();
    Client::query()->where('slug', 'default')->sole()->deactivate();

    announceEvent(OutreachEvent::EmailReplied, $contact);

    expect(AutomationRun::query()->count())->toBe(0);
});
