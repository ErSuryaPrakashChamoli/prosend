<?php

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Services\Sending\QueueHealth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->freezeTime();
});

/**
 * @param  array{available_at?: int, reserved_at?: int|null}  $attributes
 */
function queueJob(array $attributes = []): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $attributes['reserved_at'] ?? null,
        'available_at' => $attributes['available_at'] ?? now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);
}

it('reports due jobs that no worker has picked up', function () {
    queueJob(['available_at' => now()->subHours(6)->getTimestamp()]);
    queueJob(['available_at' => now()->subMinutes(10)->getTimestamp()]);

    $stalled = app(QueueHealth::class)->stalled();

    expect($stalled['waiting'])->toBe(2)
        ->and($stalled['waiting_since']->getTimestamp())->toBe(now()->subHours(6)->getTimestamp());
});

it('does not report a queue that is moving', function (array $jobs) {
    foreach ($jobs as $job) {
        queueJob($job);
    }

    expect(app(QueueHealth::class)->stalled())->toBeNull();
})->with([
    'empty' => [[]],
    'jobs that only just became due' => [[['available_at' => now()->subMinute()->getTimestamp()]]],
    'jobs delayed into the future, such as scheduled or rate-limited sends' => [[['available_at' => now()->addHour()->getTimestamp()]]],
    'a worker busy with another job' => [[
        ['available_at' => now()->subHour()->getTimestamp()],
        ['available_at' => now()->subHour()->getTimestamp(), 'reserved_at' => now()->subSeconds(30)->getTimestamp()],
    ]],
]);

it('ignores a reservation abandoned by a crashed worker', function () {
    queueJob(['available_at' => now()->subHour()->getTimestamp(), 'reserved_at' => now()->subHour()->getTimestamp()]);

    expect(app(QueueHealth::class)->stalled()['waiting'])->toBe(1);
});

it('cannot inspect queues other than the database queue', function () {
    queueJob(['available_at' => now()->subHour()->getTimestamp()]);
    config(['queue.default' => 'sync']);

    expect(app(QueueHealth::class)->stalled())->toBeNull();
});

it('warns in the panel when emails are stuck in the queue', function () {
    $this->actingAs(User::factory()->sender()->create());

    $this->get(Dashboard::getUrl())->assertDontSee('no queue worker is running');

    queueJob(['available_at' => now()->subHours(6)->getTimestamp()]);

    $this->get(Dashboard::getUrl())
        ->assertSee('no queue worker is running')
        ->assertSee('1 job has been waiting for 6 hours', escape: false)
        ->assertSee('php artisan queue:work');
});
