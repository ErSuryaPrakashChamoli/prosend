<?php

use App\Enums\ConsentStatus;
use App\Enums\EmailEventType;
use App\Enums\SegmentMatch;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\Segment;

function segmentEmails(array $rules, SegmentMatch $match = SegmentMatch::All): array
{
    return Segment::factory()->rules($rules, $match)->create()->contactsQuery()->orderBy('email')->pluck('email')->all();
}

it('matches contacts by consent, tag, domain, company, and custom attribute', function (array $rule, array $expected) {
    Contact::factory()->consented()->create(['email' => 'ann@acme.test', 'company' => 'Acme Corp', 'tags' => ['vip'], 'custom_attributes' => ['plan' => 'pro']]);
    Contact::factory()->create(['email' => 'bob@other.test', 'company' => null, 'tags' => ['trial'], 'custom_attributes' => ['plan' => 'free']]);

    expect(segmentEmails([$rule]))->toBe($expected);
})->with([
    'consent granted' => [['type' => 'consent', 'data' => ['status' => ConsentStatus::Granted->value]], ['ann@acme.test']],
    'has tag' => [['type' => 'tag', 'data' => ['operator' => 'has', 'tag' => 'vip']], ['ann@acme.test']],
    'missing tag' => [['type' => 'tag', 'data' => ['operator' => 'missing', 'tag' => 'vip']], ['bob@other.test']],
    'email domain' => [['type' => 'email_domain', 'data' => ['domain' => '@Other.test']], ['bob@other.test']],
    'company contains' => [['type' => 'company', 'data' => ['operator' => 'contains', 'value' => 'acme']], ['ann@acme.test']],
    'company empty' => [['type' => 'company', 'data' => ['operator' => 'is_empty']], ['bob@other.test']],
    'attribute equals' => [['type' => 'custom_attribute', 'data' => ['key' => 'plan', 'operator' => 'equals', 'value' => 'free']], ['bob@other.test']],
    'unsafe attribute key' => [['type' => 'custom_attribute', 'data' => ['key' => 'plan") or 1=1 --', 'operator' => 'exists']], []],
    'wildcard domain' => [['type' => 'email_domain', 'data' => ['domain' => '%']], []],
    'unknown consent status' => [['type' => 'consent', 'data' => ['status' => 'maybe']], []],
]);

it('combines rules with all or any', function () {
    Contact::factory()->consented()->create(['email' => 'ann@acme.test', 'tags' => ['vip']]);
    Contact::factory()->consented()->create(['email' => 'cat@acme.test']);
    Contact::factory()->create(['email' => 'bob@other.test', 'tags' => ['vip']]);
    $rules = [
        ['type' => 'consent', 'data' => ['status' => ConsentStatus::Granted->value]],
        ['type' => 'tag', 'data' => ['operator' => 'has', 'tag' => 'vip']],
    ];

    expect(segmentEmails($rules, SegmentMatch::All))->toBe(['ann@acme.test']);
    expect(segmentEmails($rules, SegmentMatch::Any))->toBe(['ann@acme.test', 'bob@other.test', 'cat@acme.test']);
});

it('matches list members and recent engagement', function () {
    $list = ContactList::factory()->create();
    $member = Contact::factory()->create(['email' => 'member@example.com']);
    $list->contacts()->attach($member);
    $engaged = Contact::factory()->create(['email' => 'engaged@example.com']);
    $message = EmailMessage::factory()->for($engaged)->accepted()->create();
    EmailEvent::factory()->for($message)->create(['type' => EmailEventType::Opened, 'occurred_at' => now()->subDays(3)]);
    $stale = Contact::factory()->create(['email' => 'stale@example.com']);
    EmailEvent::factory()->for(EmailMessage::factory()->for($stale)->accepted()->state(['accepted_at' => now()->subDays(60)]))->create(['type' => EmailEventType::Opened, 'occurred_at' => now()->subDays(60)]);

    expect(segmentEmails([['type' => 'list', 'data' => ['operator' => 'in', 'contact_list_id' => $list->id]]]))->toBe(['member@example.com']);
    expect(segmentEmails([['type' => 'engagement', 'data' => ['event' => 'opened', 'days' => 30]]]))->toBe(['engaged@example.com']);
    expect(segmentEmails([['type' => 'received', 'data' => ['operator' => 'not_received', 'days' => 7]]]))->toBe(['member@example.com', 'stale@example.com']);
});

it('matches nobody when it has no valid rules', function () {
    Contact::factory()->create();

    expect(segmentEmails([['type' => 'made_up', 'data' => []]]))->toBe([]);
});
