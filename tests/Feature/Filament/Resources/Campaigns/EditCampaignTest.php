<?php

use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Models\Campaign;
use App\Models\User;
use Livewire\Livewire;

it('records who last edited the draft', function () {
    $editor = User::factory()->sender()->create();
    $this->actingAs($editor);
    $campaign = Campaign::factory()->create(['created_by' => User::factory()->sender()->create()->id]);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->fillForm(['name' => 'Spring update'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($campaign->refresh())
        ->name->toBe('Spring update')
        ->updated_by->toBe($editor->id);
});
