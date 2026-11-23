@extends('unsubscribe.layout')

@section('title', 'Unsubscribed')

@section('content')
    <div class="mx-auto mt-6 flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-600/10 ring-inset dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
    </div>

    <h1 class="mt-5 text-2xl font-semibold tracking-tight">You're unsubscribed</h1>

    <p class="mt-3 text-[0.9375rem] leading-relaxed text-pretty text-zinc-600 dark:text-zinc-400">
        <strong class="font-medium break-all text-zinc-950 dark:text-white">{{ $email }}</strong> will no longer receive marketing email from {{ $senderName }}.
    </p>
@endsection
