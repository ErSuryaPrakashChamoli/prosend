<?php

use App\Enums\AutomationActionType;
use App\Enums\OutreachEvent;
use App\Enums\SystemRole;
use App\Filament\Resources\AutomationRules\Pages\CreateAutomationRule;
use App\Models\AutomationRule;
use App\Models\User;
use Livewire\Livewire;

it('creates a rule from the builder', function () {
    $this->actingAs($user = User::factory()->sender()->create());

    Livewire::test(CreateAutomationRule::class)
        ->fillForm([
            'name' => 'Tag repliers',
            'trigger' => OutreachEvent::EmailReplied->value,
            'actions' => [['type' => AutomationActionType::AddTag->value, 'data' => ['tag' => 'replied']]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AutomationRule::query()->sole())
        ->trigger->toBe(OutreachEvent::EmailReplied)
        ->created_by->toBe($user->id)
        ->actions->{0}->type->toBe('add_tag');
});

it('keeps automation for people who may manage it', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::SalesExecutive)->create());

    Livewire::test(CreateAutomationRule::class)->assertForbidden();
});

it('only lets people who may approve campaigns add an email action', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(CreateAutomationRule::class)
        ->fillForm([
            'name' => 'Welcome',
            'trigger' => OutreachEvent::ProspectCreated->value,
            'actions' => [['type' => AutomationActionType::SendEmail->value, 'data' => ['email_template_id' => 1, 'sender_identity_id' => 1]]],
        ])
        ->call('create')
        ->assertHasFormErrors(['actions']);
});
