<?php

use App\Enums\EmailPurpose;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Services\Sending\EmailContentRenderer;

it('escapes contact data inserted by merge tags and strips scripts from the content', function () {
    $contact = Contact::factory()->create(['first_name' => '<img src=x onerror=alert(1)>', 'company' => 'O\'Reilly & Sons']);
    $message = EmailMessage::factory()->for($contact)->create([
        'subject' => 'Hi {{ first_name }}',
        'html_body' => '<p>Hi <span data-type="mergeTag" data-id="first_name"></span> at {{ company_name }}</p><script>alert(2)</script>',
    ]);

    $rendered = app(EmailContentRenderer::class)->render($message);

    expect($rendered->html)
        ->toContain('&lt;img src')
        ->toContain('Sons')
        ->not->toContain('<img')
        ->not->toContain('<script>');
    expect($rendered->text)->toContain('Hi <img src=x onerror=alert(1)> at O\'Reilly & Sons');
    expect($rendered->subject)->toBe('Hi <img src=x onerror=alert(1)>');
});

it('uses the recipient name when the email is not linked to a contact', function () {
    $message = EmailMessage::factory()->create([
        'to_name' => 'Sam Lee',
        'subject' => 'Hello {{ first_name }} {{ last_name }}',
    ]);

    expect(app(EmailContentRenderer::class)->render($message)->subject)->toBe('Hello Sam Lee');
});

it('only includes an unsubscribe link in marketing email', function (EmailPurpose $purpose, bool $hasLink) {
    $message = EmailMessage::factory()->create(['purpose' => $purpose]);

    expect(app(EmailContentRenderer::class)->render($message)->unsubscribeUrl !== null)->toBe($hasLink);
})->with([
    'marketing' => [EmailPurpose::Marketing, true],
    'transactional' => [EmailPurpose::Transactional, false],
]);

it('fills custom variables from the contact attributes', function () {
    $contact = Contact::factory()->create(['custom_attributes' => ['order_number' => 'A-1001']]);
    $message = EmailMessage::factory()->for($contact)->create(['subject' => 'Order {{ order_number }} shipped']);

    expect(app(EmailContentRenderer::class)->render($message)->subject)->toBe('Order A-1001 shipped');
});

it('leaves out a variable the recipient has no value for', function () {
    $message = EmailMessage::factory()->create(['subject' => 'Order {{ order_number }} shipped']);

    expect(app(EmailContentRenderer::class)->render($message)->subject)->toBe('Order  shipped');
});

it('uses the hand-written plain-text version when there is one', function () {
    $contact = Contact::factory()->create(['first_name' => 'Jane']);
    $message = EmailMessage::factory()->for($contact)->create([
        'html_body' => '<p>HTML version</p>',
        'text_body' => 'Hi {{ first_name }}, plain version.',
    ]);

    expect(app(EmailContentRenderer::class)->render($message)->text)->toBe('Hi Jane, plain version.');
});

it('removes an unsafe link built from contact data', function () {
    $contact = Contact::factory()->create(['first_name' => 'javascript:alert(1)']);
    $message = EmailMessage::factory()->for($contact)->create([
        'html_body' => '<p><a href="{{first_name}}">Open</a></p>',
    ]);

    expect(app(EmailContentRenderer::class)->render($message)->html)->not->toContain('javascript:');
});

it('fills the full name and prospect details from the contact', function () {
    $contact = Contact::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'job_title' => 'Head of Operations', 'industry' => 'Logistics', 'website' => 'acme.test']);
    $message = EmailMessage::factory()->for($contact)->create(['subject' => '{{ name }}, {{ job_title }} in {{ industry }} at {{ website }}']);

    expect(app(EmailContentRenderer::class)->render($message)->subject)->toBe('Jane Doe, Head of Operations in Logistics at https://acme.test');
});

it('escapes a job title inserted into the body', function () {
    $contact = Contact::factory()->create(['job_title' => '<script>alert(1)</script>CEO']);
    $message = EmailMessage::factory()->for($contact)->create(['html_body' => '<p>Dear {{ job_title }}</p>']);

    expect(app(EmailContentRenderer::class)->render($message)->html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
});

it('falls back to a custom attribute for a newer built-in the contact has no value for', function () {
    $contact = Contact::factory()->create([
        'first_name' => null,
        'last_name' => null,
        'job_title' => null,
        'website' => 'https://acme.test',
        'custom_attributes' => ['name' => 'Dr. Jane', 'job_title' => 'CTO', 'website' => 'https://legacy.test'],
    ]);
    $message = EmailMessage::factory()->for($contact)->create(['subject' => '{{ name }} | {{ job_title }} | {{ website }}']);

    expect(app(EmailContentRenderer::class)->render($message)->subject)->toBe('Dr. Jane | CTO | https://acme.test');
});
