<?php

use App\Enums\CampaignStatus;
use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Jobs\PrepareCampaignMessages;
use App\Jobs\SendEmailMessage;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\CampaignVariant;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Campaigns\AbTestResults;
use App\Services\Campaigns\CampaignApprovalNotifier;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignReadiness;
use App\Services\Campaigns\SequenceRunner;
use App\Services\Campaigns\VariantPicker;
use App\Services\Sending\EmailContentRenderer;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Fakes\FakeMailProviderDriver;

function prepareCampaign(Campaign $campaign): void
{
    (new PrepareCampaignMessages($campaign))->handle(app(CampaignDispatcher::class), app(CampaignApprovalNotifier::class), app(AuditLogger::class));
}

it('splits prospects between versions by their shares, and always gives a prospect the same version', function () {
    $variant = CampaignVariant::factory()->make(['id' => 7, 'weight' => 30]);
    $picker = app(VariantPicker::class);

    $toB = collect(range(1, 2000))->filter(fn (int $contactId): bool => $picker->pick(collect([$variant]), 1, $contactId, 1) !== null)->count();

    expect($toB)->toBeGreaterThan(500)->toBeLessThan(700)
        ->and($picker->pick(collect([$variant]), 1, 42, 1))->toBe($picker->pick(collect([$variant]), 1, 42, 1));
});

it('never picks a stopped version', function () {
    $stopped = CampaignVariant::factory()->make(['weight' => 99, 'is_active' => false]);

    expect(collect(range(1, 200))->map(fn (int $id) => app(VariantPicker::class)->pick(collect([$stopped]), 1, $id, 1))->filter()->count())->toBe(0);
});

it('sends each prospect the content and subject of their version', function () {
    Bus::fake([SendEmailMessage::class]);
    $driver = FakeMailProviderDriver::install();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create(['subject' => 'Version A subject', 'body' => '<p>Version A body</p>']);
    CampaignVariant::factory()->for($campaign)->create(['weight' => 50, 'subject' => 'Version B subject', 'body' => '<p>Version B body</p>']);
    Contact::factory()->consented()->count(40)->create();

    prepareCampaign($campaign);
    EmailMessage::query()->each(fn (EmailMessage $message) => app()->call([new SendEmailMessage($message), 'handle']));

    $bySubject = collect($driver->sent)->map(fn ($sent) => $sent->getOriginalMessage())->groupBy(fn ($email): string => $email->getSubject());
    expect($bySubject->keys()->sort()->values()->all())->toBe(['Version A subject', 'Version B subject'])
        ->and($bySubject['Version B subject']->first()->getHtmlBody())->toContain('Version B body')
        ->and($bySubject['Version A subject']->first()->getHtmlBody())->toContain('Version A body');
    expect(EmailMessage::query()->whereNotNull('campaign_variant_id')->count())->toBe($bySubject['Version B subject']->count());
});

it('tests follow-ups too, replying in the thread of the first email the prospect actually got', function () {
    Bus::fake([SendEmailMessage::class]);
    FakeMailProviderDriver::install();
    $contact = Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->status(CampaignStatus::Queued)->create(['subject' => 'Original']);
    CampaignVariant::factory()->for($campaign)->create(['weight' => 99, 'subject' => 'Variant subject']);
    $step = CampaignStep::factory()->for($campaign)->create(['wait_days' => 1]);
    CampaignVariant::factory()->create(['campaign_step_id' => $step->id, 'position' => 1, 'weight' => 99, 'subject' => null, 'body' => '<p>Follow-up B</p>']);
    $this->travelTo(now());

    prepareCampaign($campaign);
    $first = EmailMessage::query()->sole();
    app()->call([new SendEmailMessage($first), 'handle']);
    $this->travel(1)->days();
    app(SequenceRunner::class)->run();

    $followUp = EmailMessage::query()->where('step_number', 2)->sole();
    expect($first->subject)->toBe('Variant subject')
        ->and($followUp->subject)->toBe('Re: Variant subject')
        ->and($followUp->campaignVariant->campaign_id)->toBe($campaign->id)
        ->and(app(EmailContentRenderer::class)->render($followUp, track: false)->html)->toContain('Follow-up B');
});

it('names a winner only with enough data and a significant difference', function () {
    $campaign = Campaign::factory()->prepared()->create();
    $variant = CampaignVariant::factory()->for($campaign)->create(['weight' => 50]);
    $send = function (?CampaignVariant $version, int $count, int $replies) use ($campaign): void {
        EmailMessage::factory()->for($campaign)->accepted()->count($count)->create(['campaign_variant_id' => $version?->id, 'sender_identity_id' => $campaign->sender_identity_id])
            ->take($replies)
            ->each(fn (EmailMessage $message) => $message->recordEvent(EmailEventType::Replied, EventSource::Inbox));
    };

    $send(null, 50, 2);
    $send($variant, 50, 10);
    expect(app(AbTestResults::class)->for($campaign)[0])->winner->toBeNull()->verdict->toStartWith('Still collecting data');

    $send(null, 150, 4);
    $send($variant, 150, 25);
    $result = app(AbTestResults::class)->for($campaign)[0];

    expect($result['winner'])->toBe('B')
        ->and($result['metric'])->toBe('reply rate')
        ->and($result['versions'][1])->sent->toBe(200)->replied->toBe(35)->reply_rate->toBe(17.5);
});

it('reports no clear winner when the difference could be chance', function () {
    $campaign = Campaign::factory()->prepared()->create();
    $variant = CampaignVariant::factory()->for($campaign)->create();
    EmailMessage::factory()->for($campaign)->accepted()->count(120)->create(['sender_identity_id' => $campaign->sender_identity_id])->take(10)->each(fn ($m) => $m->recordEvent(EmailEventType::Replied, EventSource::Inbox));
    EmailMessage::factory()->for($campaign)->accepted()->count(120)->create(['campaign_variant_id' => $variant->id, 'sender_identity_id' => $campaign->sender_identity_id])->take(12)->each(fn ($m) => $m->recordEvent(EmailEventType::Replied, EventSource::Inbox));

    expect(app(AbTestResults::class)->for($campaign)[0])->winner->toBeNull()->verdict->toStartWith('No clear winner');
});

it('stops a version for emails not created yet', function () {
    $this->actingAs(User::factory()->sender()->create());
    $campaign = Campaign::factory()->prepared()->create();
    $variant = CampaignVariant::factory()->for($campaign)->create();

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('stopVersion', data: ['variant' => $variant->id])
        ->assertNotified('Version B stopped');

    expect($variant->refresh()->is_active)->toBeFalse();
});

it('saves versions from the campaign form, and refuses shares that leave nothing for version A', function () {
    $this->actingAs(User::factory()->sender()->create());
    $sender = SenderIdentity::factory()->create();
    $base = ['name' => 'Test', 'sender_identity_id' => $sender->id, 'subject' => 'A', 'body' => '<p>A</p>'];

    Livewire::test(CreateCampaign::class)
        ->fillForm([...$base, 'firstEmailVariants' => [['weight' => 60, 'subject' => 'B', 'body' => '<p>B</p>'], ['weight' => 40, 'subject' => 'C', 'body' => '<p>C</p>']]])
        ->call('create')
        ->assertHasFormErrors(['firstEmailVariants']);

    Livewire::test(CreateCampaign::class)
        ->fillForm([...$base, 'firstEmailVariants' => [['weight' => 30, 'subject' => 'B', 'body' => '<p>B</p>']]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CampaignVariant::query()->sole())->campaign_step_id->toBeNull()->weight->toBe(30)->label()->toBe('B');
});

it('blocks submission while a version has no content', function () {
    $campaign = Campaign::factory()->create();
    CampaignVariant::factory()->for($campaign)->create(['body' => '<p></p>']);

    expect(app(CampaignReadiness::class)->assess($campaign)->blockingIssues)->toContain('Add content to version B of the first email.');
});

it('leaves stopped versions out of the verdict', function () {
    $campaign = Campaign::factory()->prepared()->create();
    $b = CampaignVariant::factory()->for($campaign)->create(['position' => 1]);
    CampaignVariant::factory()->for($campaign)->create(['position' => 2, 'is_active' => false]);
    EmailMessage::factory()->for($campaign)->accepted()->count(120)->create(['sender_identity_id' => $campaign->sender_identity_id])->take(2)->each(fn ($m) => $m->recordEvent(EmailEventType::Replied, EventSource::Inbox));
    EmailMessage::factory()->for($campaign)->accepted()->count(120)->create(['campaign_variant_id' => $b->id, 'sender_identity_id' => $campaign->sender_identity_id])->take(25)->each(fn ($m) => $m->recordEvent(EmailEventType::Replied, EventSource::Inbox));

    expect(app(AbTestResults::class)->for($campaign)[0]['winner'])->toBe('B');
});

it('raises the bar when several versions compete', function () {
    expect(AbTestResults::threshold(1))->toBe(1.96)
        ->and(AbTestResults::threshold(3))->toBeGreaterThan(2.3);
});
