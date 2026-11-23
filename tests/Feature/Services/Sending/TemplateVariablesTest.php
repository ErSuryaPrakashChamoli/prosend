<?php

use App\Models\Contact;
use App\Services\Sending\TemplateVariables;

it('finds typed placeholders and inserted merge tags', function () {
    $variables = app(TemplateVariables::class)->extract(
        'Order {{ order_number }}',
        '<p>Hi <span data-type="mergeTag" data-id="first_name"></span>, see {{action_url}}</p>',
    );

    expect($variables)->toBe(['order_number', 'first_name', 'action_url']);
});

it('only keeps contacts with a value for every required variable', function () {
    $complete = Contact::factory()->create(['first_name' => 'Jane', 'custom_attributes' => ['order_number' => 'A-1']]);
    Contact::factory()->create(['first_name' => '', 'custom_attributes' => ['order_number' => 'A-2']]);
    Contact::factory()->create(['first_name' => 'Sam', 'custom_attributes' => ['other' => 'x']]);
    Contact::factory()->create(['first_name' => 'Kim', 'custom_attributes' => null]);

    $ids = app(TemplateVariables::class)->constrain(Contact::query(), ['first_name', 'order_number', 'email'])->pluck('id')->all();

    expect($ids)->toBe([$complete->id]);
});

it('lists the required variables a contact is missing', function () {
    $contact = Contact::factory()->make(['first_name' => 'Jane', 'company' => null, 'custom_attributes' => ['action_url' => 'https://example.com']]);

    expect(app(TemplateVariables::class)->missingFor($contact, ['first_name', 'company_name', 'action_url', 'order_number', 'unsubscribe_url']))
        ->toBe(['company_name', 'order_number']);
});

it('matches nobody when a required variable name can never have a value', function () {
    Contact::factory()->create(['custom_attributes' => ['Order_Number' => 'A-1']]);

    expect(app(TemplateVariables::class)->constrain(Contact::query(), ['Order_Number'])->count())->toBe(0);
});

it('treats the full name as present when either name part has a value', function () {
    $firstOnly = Contact::factory()->create(['first_name' => 'Jane', 'last_name' => null]);
    $lastOnly = Contact::factory()->create(['first_name' => '', 'last_name' => 'Doe']);
    $nameless = Contact::factory()->create(['first_name' => null, 'last_name' => '']);
    $variables = app(TemplateVariables::class);

    $ids = $variables->constrain(Contact::query(), ['name'])->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([$firstOnly->id, $lastOnly->id])
        ->and($variables->missingFor($nameless, ['name']))->toBe(['name'])
        ->and($variables->missingFor($lastOnly, ['name', 'job_title']))->toBe(['job_title'])
        ->and($variables->customAttributes(['name', 'job_title', 'industry', 'website', 'order_number']))->toBe(['order_number']);
});

it('counts a custom attribute of the same name as a value for a newer built-in', function () {
    $legacy = Contact::factory()->create(['job_title' => null, 'custom_attributes' => ['job_title' => 'CTO']]);
    $current = Contact::factory()->create(['job_title' => 'CEO', 'custom_attributes' => null]);
    $without = Contact::factory()->create(['job_title' => null, 'custom_attributes' => ['other' => 'x']]);
    $variables = app(TemplateVariables::class);

    $ids = $variables->constrain(Contact::query(), ['job_title'])->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([$legacy->id, $current->id])
        ->and($variables->missingFor($legacy, ['job_title']))->toBe([])
        ->and($variables->missingFor($without, ['job_title']))->toBe(['job_title']);
});
