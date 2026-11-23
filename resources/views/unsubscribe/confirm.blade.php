@extends('unsubscribe.layout')

@section('title', 'Unsubscribe')

@section('content')
    <div class="mx-auto mt-6 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-brand-600/10 ring-inset dark:bg-brand-400/10 dark:text-brand-300 dark:ring-brand-400/20">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
        </svg>
    </div>

    <h1 class="mt-5 text-2xl font-semibold tracking-tight text-balance">Unsubscribe from {{ $senderName }}?</h1>

    <p class="mt-3 text-[0.9375rem] leading-relaxed text-pretty text-zinc-600 dark:text-zinc-400">
        <strong class="font-medium break-all text-zinc-950 dark:text-white">{{ $email }}</strong> will stop receiving newsletters and other marketing email from {{ $senderName }}. Important account and service messages may still be sent.
    </p>

    <form method="POST" action="{{ $actionUrl }}" class="mt-8">
        <button type="submit" class="w-full cursor-pointer rounded-xl bg-brand-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:bg-brand-500 dark:hover:bg-brand-400">
            Unsubscribe
        </button>
    </form>
@endsection
