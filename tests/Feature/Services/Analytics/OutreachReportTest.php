<?php

use App\Enums\BounceType;
use App\Enums\ConversationStatus;
use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Filament\Pages\Reports;
use App\Filament\Widgets\OutreachOverview;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\EmailMessage;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\OutreachReport;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A campaign by the given person: 10 emails accepted, 4 opened, 2 replied (1 positive), 1 hard bounce.
 */
function campaignWithResults(User $owner, string $name): Campaign
{
    $campaign = Campaign::factory()->prepared()->create(['name' => $name, 'created_by' => $owner->id]);
    $contacts = Contact::factory()->count(10)->create();
    $messages = $contacts->map(fn (Contact $contact) => EmailMessage::factory()->for($campaign)->for($contact)->accepted()->create([
        'created_by' => $owner->id,
        'sender_identity_id' => $campaign->sender_identity_id,
    ]));

    $messages->take(4)->each(fn (EmailMessage $message) => $message->recordEvent(EmailEventType::Opened, EventSource::Tracking));
    $messages->take(2)->each(fn (EmailMessage $message) => $message->recordEvent(EmailEventType::Replied, EventSource::Inbox));
    Conversation::factory()->create(['contact_id' => $contacts[0]->id, 'campaign_id' => $campaign->id, 'status' => ConversationStatus::Interested]);
    $messages[9]->forceFill(['bounce_type' => BounceType::Hard])->save();

    return $campaign;
}

function reportFilters(array $extra = []): array
{
    return ['start_date' => now()->subDay()->toDateString(), 'end_date' => now()->toDateString(), ...$extra];
}

it('counts sends, opens, replies, positive replies and bounces per campaign, with rates', function () {
    campaignWithResults(User::factory()->sender()->create(), 'Q4 outreach');

    $row = app(OutreachReport::class)->rows('campaign', reportFilters())[0];

    expect($row)
        ->label->toBe('Q4 outreach')
        ->sent->toBe(10)
        ->opened->toBe(4)
        ->replied->toBe(2)
        ->positive->toBe(1)
        ->bounced->toBe(1)
        ->open_rate->toBe(40.0)
        ->reply_rate->toBe(20.0)
        ->positive_rate->toBe(50.0)
        ->bounce_rate->toBe(10.0);
});

it('groups by person and adds people up per team', function () {
    $ana = User::factory()->sender()->create(['name' => 'Ana']);
    $ben = User::factory()->sender()->create(['name' => 'Ben']);
    campaignWithResults($ana, 'A');
    campaignWithResults($ben, 'B');
    $team = Team::factory()->create(['name' => 'North']);
    $team->members()->attach([$ana->id, $ben->id]);

    expect(collect(app(OutreachReport::class)->rows('user', reportFilters()))->pluck('sent', 'label')->all())->toBe(['Ana' => 10, 'Ben' => 10])
        ->and(app(OutreachReport::class)->rows('team', reportFilters())[0])->label->toBe('North')->sent->toBe(20)->replied->toBe(4);
});

it('compares only the chosen campaigns', function () {
    $owner = User::factory()->sender()->create();
    $first = campaignWithResults($owner, 'First');
    campaignWithResults($owner, 'Second');

    expect(collect(app(OutreachReport::class)->rows('campaign', reportFilters(['campaign_ids' => [$first->id]])))->pluck('label')->all())->toBe(['First']);
});

it('keeps other clients out of every grouping except the Super Admin\'s client view', function () {
    $owner = User::factory()->sender()->create();
    campaignWithResults($owner, 'Mine');
    $other = Client::factory()->create(['name' => 'Other Co']);
    asClient($other, fn () => campaignWithResults(User::factory()->withoutClient()->create(), 'Theirs'));

    expect(collect(app(OutreachReport::class)->rows('campaign', reportFilters()))->pluck('label')->all())->toBe(['Mine'])
        ->and(collect(app(OutreachReport::class)->rows('client', reportFilters(), acrossClients: true))->pluck('sent', 'label')->sortKeys()->all())->toBe(['Default client' => 10, 'Other Co' => 10]);
});

it('shows the report, exports it, and prints it, for people allowed to', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Analyst)->create());
    campaignWithResults(User::factory()->sender()->create(), 'Q4 outreach');

    $page = Livewire::test(Reports::class)->assertSee('Q4 outreach');
    $page->callAction('csv')->assertFileDownloaded('outreach-campaign-'.now()->subDays(29)->toDateString().'-to-'.now()->toDateString().'.csv');

    $this->get(route('filament.admin.reports.print', ['tenant' => Filament::getTenant(), ...reportFilters(['grouping' => 'campaign'])]))
        ->assertOk()
        ->assertSee('Q4 outreach')
        ->assertSee('window.print()', escape: false);
});

it('hides the client grouping from members and refuses it when asked for directly', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());

    Livewire::test(Reports::class)
        ->set('data.grouping', 'client')
        ->assertSet('data.grouping', 'client')
        ->tap(fn ($page) => expect($page->instance()->filters()['grouping'])->toBe('campaign'));

    $this->get(route('filament.admin.reports.print', ['tenant' => Filament::getTenant(), ...reportFilters(['grouping' => 'client'])]))->assertForbidden();
});

it('keeps reports from roles without them, and exports from roles without export', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::SalesExecutive)->create());

    Livewire::test(Reports::class)->assertActionHidden('csv');
    $this->get(route('filament.admin.reports.print', ['tenant' => Filament::getTenant(), ...reportFilters(['grouping' => 'campaign'])]))->assertForbidden();

    $customRole = Role::factory()->withPermissions([Permission::ViewProspects])->create();
    $noReports = User::factory()->withoutClient()->create();
    ClientMembership::query()->create(['user_id' => $noReports->id, 'role_id' => $customRole->id]);
    $this->actingAs($noReports->refreshRoles());

    Livewire::test(Reports::class)->assertForbidden();

    $exportOnly = User::factory()->withoutClient()->create();
    ClientMembership::query()->create(['user_id' => $exportOnly->id, 'role_id' => Role::factory()->withPermissions([Permission::ExportReports])->create()->id]);
    $this->actingAs($exportOnly->refreshRoles());

    $this->get(route('filament.admin.reports.print', ['tenant' => Filament::getTenant(), ...reportFilters(['grouping' => 'campaign'])]))->assertForbidden();
});

it('measures positive replies against the prospects who replied, not the reply emails', function () {
    $campaign = campaignWithResults(User::factory()->sender()->create(), 'Q4 outreach');
    $replied = EmailMessage::query()->whereHas('events', fn ($events) => $events->where('type', EmailEventType::Replied))->get();
    // The positive prospect replies twice, to a follow-up as well.
    $followUp = EmailMessage::factory()->for($campaign)->for($replied[0]->contact)->accepted()->create(['sender_identity_id' => $campaign->sender_identity_id, 'step_number' => 2]);
    $followUp->recordEvent(EmailEventType::Replied, EventSource::Inbox);

    $row = app(OutreachReport::class)->rows('campaign', reportFilters())[0];

    expect($row)->replied->toBe(3)->replied_prospects->toBe(2)->positive->toBe(1)->positive_rate->toBe(50.0);
});

it('shows outreach figures on the dashboard', function () {
    $this->actingAs(User::factory()->sender()->create());
    campaignWithResults(User::factory()->sender()->create(), 'Q4');

    Livewire::test(OutreachOverview::class, ['pageFilters' => reportFilters()])
        ->assertSee('Reply rate')
        ->assertSee('20.0%');
});
