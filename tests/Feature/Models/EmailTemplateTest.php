<?php

use App\Enums\TemplateStatus;
use App\Models\EmailTemplate;
use App\Models\User;

it('saves the first version when the template is created', function () {
    $user = User::factory()->sender()->create();
    $this->actingAs($user);

    $template = EmailTemplate::factory()->create(['subject' => 'Hello']);

    expect($template->version)->toBe(1);
    expect($template->versions()->sole())
        ->version->toBe(1)
        ->subject->toBe('Hello')
        ->created_by->toBe($user->id);
    expect($template->created_by)->toBe($user->id);
});

it('saves a new version when the content changes', function () {
    $template = EmailTemplate::factory()->create(['subject' => 'First']);

    $template->update(['subject' => 'Second']);

    expect($template->refresh()->version)->toBe(2);
    expect($template->versions()->pluck('subject', 'version')->all())->toBe([2 => 'Second', 1 => 'First']);
});

it('does not create a version when only the name or status changes', function () {
    $template = EmailTemplate::factory()->create();

    $template->update(['name' => 'Renamed', 'status' => TemplateStatus::Archived]);

    expect($template->refresh()->version)->toBe(1);
    expect($template->versions()->count())->toBe(1);
});

it('restores an earlier version as a new version', function () {
    $template = EmailTemplate::factory()->create(['subject' => 'Original']);
    $template->update(['subject' => 'Changed']);

    $template->restore($template->versions()->where('version', 1)->sole());

    expect($template->refresh())
        ->subject->toBe('Original')
        ->version->toBe(3);
    expect($template->versions()->count())->toBe(3);
});

it('duplicates the template as a new draft at version one', function () {
    $template = EmailTemplate::factory()->create(['name' => 'Welcome']);
    $template->update(['subject' => 'Updated']);

    $copy = $template->duplicate();

    expect($copy)
        ->name->toBe('Copy of Welcome')
        ->status->toBe(TemplateStatus::Draft)
        ->version->toBe(1)
        ->subject->toBe('Updated');
});
