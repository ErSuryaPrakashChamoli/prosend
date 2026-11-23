<?php

use App\Enums\SystemRole;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('creates a task and completes it', function () {
    $this->actingAs($user = User::factory()->sender()->create());

    Livewire::test(ManageTasks::class)
        ->callAction('create', data: ['title' => 'Call Jane', 'assigned_to' => $user->id, 'due_at' => now()->addDay()])
        ->assertHasNoActionErrors();

    $task = Task::query()->sole();
    expect($task->created_by)->toBe($user->id);

    Livewire::test(ManageTasks::class)->callAction(TestAction::make('complete')->table($task));

    expect($task->refresh()->isDone())->toBeTrue();
});

it('shows a Sales Executive only their own tasks', function () {
    $rep = User::factory()->withClientRole(SystemRole::SalesExecutive)->create();
    $this->actingAs($rep);
    $mine = Task::factory()->create(['assigned_to' => $rep->id]);
    $theirs = Task::factory()->create(['assigned_to' => User::factory()->create()->id]);

    Livewire::test(ManageTasks::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('only lets tasks go to people who work in the client', function () {
    $this->actingAs(User::factory()->sender()->create());
    $outsider = User::factory()->withoutClient()->create();

    Livewire::test(ManageTasks::class)
        ->callAction('create', data: ['title' => 'Call', 'assigned_to' => $outsider->id])
        ->assertHasActionErrors(['assigned_to']);
});
