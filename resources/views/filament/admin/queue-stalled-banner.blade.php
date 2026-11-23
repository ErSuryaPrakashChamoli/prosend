@php
    $stalled = app(\App\Services\Sending\QueueHealth::class)->stalled();
@endphp

@if ($stalled)
    <x-filament::callout
        color="danger"
        icon="heroicon-o-exclamation-triangle"
        heading="Emails are not being sent: no queue worker is running"
        class="mt-6"
    >
        <x-slot name="description">
            {{ trans_choice('{1} 1 job has|[2,*] :count jobs have', $stalled['waiting'], ['count' => number_format($stalled['waiting'])]) }} been waiting for {{ $stalled['waiting_since']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}.
            Start a worker on the server with
            <code class="rounded-md bg-danger-500/10 px-1.5 py-0.5 font-mono text-[0.8125rem]">php artisan queue:work</code>
            and keep it running under a process supervisor.
        </x-slot>
    </x-filament::callout>
@endif
