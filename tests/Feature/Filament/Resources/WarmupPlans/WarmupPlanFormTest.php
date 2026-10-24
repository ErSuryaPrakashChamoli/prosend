<?php

use App\Enums\WarmupStatus;
use App\Filament\Resources\WarmupPlans\Pages\CreateWarmupPlan;
use App\Filament\Resources\WarmupPlans\Pages\ViewWarmupPlan;
use App\Models\SmtpProvider;
use App\Models\User;
use App\Models\WarmupPlan;
use Livewire\Livewire;

it('refuses a target above the provider daily limit', function () {
    $this->actingAs(User::factory()->admin()->create());
    $provider = SmtpProvider::factory()->dailyLimit(300)->create();

    $component = Livewire::test(CreateWarmupPlan::class)
        ->fillForm([
            'name' => 'New domain',
            'smtp_provider_id' => $provider->id,
            'start_date' => now()->toDateString(),
            'initial_daily_volume' => 50,
            'target_daily_volume' => 1000,
        ])
        ->call('create');

    expect($component->errors()->get('data.target_daily_volume'))->toBe(["The provider allows 300 emails per day. A warm-up plan cannot go above the provider's limit."]);
    expect(WarmupPlan::query()->count())->toBe(0);
});

it('starts limiting email once an administrator approves the plan', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $plan = WarmupPlan::factory()->create(['initial_daily_volume' => 40]);

    Livewire::test(ViewWarmupPlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('approve');

    expect($plan->refresh())
        ->status->toBe(WarmupStatus::Active)
        ->approved_by->toBe($admin->id)
        ->todaysLimit()->toBe(40);
});
