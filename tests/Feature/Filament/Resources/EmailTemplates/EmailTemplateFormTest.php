<?php

use App\Enums\EmailPurpose;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Filament\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Models\EmailTemplate;
use App\Models\User;
use Livewire\Livewire;

function templateFormData(array $overrides = []): array
{
    return [
        'name' => 'Order shipped',
        'category' => TemplateCategory::OrderUpdate->value,
        'purpose' => EmailPurpose::Transactional->value,
        'status' => TemplateStatus::Active->value,
        'subject' => 'Order {{ order_number }} shipped',
        'body' => '<p>Your order is on its way.</p>',
        'required_variables' => ['order_number'],
        ...$overrides,
    ];
}

it('creates a template', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(CreateEmailTemplate::class)
        ->fillForm(templateFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmailTemplate::query()->sole())
        ->name->toBe('Order shipped')
        ->required_variables->toBe(['order_number']);
});

it('rejects a variable name that can never be filled', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(CreateEmailTemplate::class)
        ->fillForm(templateFormData(['subject' => 'Hi {{ First Name }}', 'required_variables' => []]))
        ->call('create')
        ->assertHasFormErrors(['subject']);

    expect(EmailTemplate::query()->count())->toBe(0);
});

it('rejects a required variable the template does not use', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(CreateEmailTemplate::class)
        ->fillForm(templateFormData(['required_variables' => ['coupon_code']]))
        ->call('create')
        ->assertHasFormErrors(['required_variables']);
});
