<?php

use App\Enums\EmailPurpose;
use App\Models\EmailTemplate;
use App\Services\Templates\SampleTemplates;

it('adds the sample templates once', function () {
    $first = app(SampleTemplates::class)->install();
    $second = app(SampleTemplates::class)->install();

    expect($first)->toBe(7)
        ->and($second)->toBe(0);
    expect(EmailTemplate::query()->where('purpose', EmailPurpose::Marketing)->pluck('name')->sort()->values()->all())
        ->toBe(['General announcement', 'Newsletter']);
});

it('only requires variables that each sample template uses', function () {
    app(SampleTemplates::class)->install();

    EmailTemplate::query()->get()->each(function (EmailTemplate $template): void {
        expect(array_diff($template->required_variables ?? [], $template->usedVariables()))->toBe([]);
    });
});
