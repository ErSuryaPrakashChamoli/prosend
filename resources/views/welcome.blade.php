@php
    $features = [
        [
            'title' => 'Campaigns that respect limits',
            'body' => 'Preview eligible, skipped and duplicate recipients before launch. Sending is spaced out, and provider and warm-up limits are always enforced.',
            'icon' => 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46',
        ],
        [
            'title' => 'Consent and suppression built in',
            'body' => 'Marketing goes only to contacts who opted in. Bounces, complaints and unsubscribes are suppressed automatically and checked again at send time.',
            'icon' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
        ],
        [
            'title' => 'Deliverability you can verify',
            'body' => 'Domain authentication checks SPF, DKIM and DMARC against public DNS and your provider. Warm-up plans grow volume only on real results.',
            'icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z',
        ],
        [
            'title' => 'Every message traced',
            'body' => 'Follow each email from queued to accepted, delivered, bounced or complained, with the provider\'s own events and nothing estimated.',
            'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
        ],
        [
            'title' => 'A sending API for your apps',
            'body' => 'Scoped, hashed API keys with per-key limits and idempotent retries, so your applications never hold SMTP credentials.',
            'icon' => 'M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5',
        ],
        [
            'title' => 'Accountable by default',
            'body' => 'Role-based access, and an audit log of provider changes, launches, retries and suppression changes. Secrets are never shown or logged.',
            'icon' => 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
        ],
    ];

    $timeline = [
        ['label' => 'Queued', 'detail' => 'Waiting for the queue worker', 'dot' => 'bg-zinc-400'],
        ['label' => 'Accepted by provider', 'detail' => 'The provider took the email', 'dot' => 'bg-brand-500'],
        ['label' => 'Delivered', 'detail' => 'Reported by the provider\'s webhook', 'dot' => 'bg-emerald-500'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'ProSend') }} · Email operations</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    @fonts

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite('resources/css/app.css')
    @endif
</head>
<body class="min-h-dvh bg-white text-zinc-950 antialiased dark:bg-zinc-950 dark:text-white">
    <div class="relative isolate overflow-hidden">
        <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 h-[40rem] bg-[radial-gradient(48rem_28rem_at_70%_-8rem,color-mix(in_oklab,var(--color-brand-500)_18%,transparent),transparent_70%)]"></div>
        <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 h-[40rem] bg-[linear-gradient(to_right,rgb(0_0_0/0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgb(0_0_0/0.04)_1px,transparent_1px)] bg-size-[2.5rem_2.5rem] mask-[radial-gradient(ellipse_at_top,black_20%,transparent_70%)] dark:bg-[linear-gradient(to_right,rgb(255_255_255/0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.05)_1px,transparent_1px)]"></div>

        <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                <x-brand-mark class="size-8" />
                <span class="text-[1.0625rem] font-semibold tracking-tight">Pro<span class="text-brand-600 dark:text-brand-400">Send</span></span>
            </a>

            <a href="{{ auth()->check() ? route('filament.admin.pages.dashboard') : route('filament.admin.auth.login') }}" class="rounded-full px-4 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-950/10 transition hover:bg-zinc-950/[0.04] hover:text-zinc-950 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5 dark:hover:text-white">
                {{ auth()->check() ? 'Open dashboard' : 'Sign in' }}
            </a>
        </header>

        <main>
            <section class="mx-auto grid max-w-6xl items-center gap-16 px-6 pt-16 pb-24 lg:grid-cols-[1.1fr_0.9fr] lg:pt-24">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-600/15 ring-inset dark:bg-brand-400/10 dark:text-brand-300 dark:ring-brand-400/20">
                        <span class="size-1.5 rounded-full bg-brand-500"></span>
                        Email operations platform
                    </p>

                    <h1 class="mt-6 text-4xl font-semibold tracking-tighter text-balance sm:text-6xl">
                        Every email, <span class="bg-linear-to-r from-brand-500 to-brand-700 bg-clip-text text-transparent dark:from-brand-300 dark:to-brand-500">accounted for.</span>
                    </h1>

                    <p class="mt-6 max-w-xl text-lg leading-8 text-pretty text-zinc-600 dark:text-zinc-400">
                        Send transactional email and campaigns through your own providers. Consent and suppression rules are enforced on every send, and each message can be followed from the queue to the inbox.
                    </p>

                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <a href="{{ auth()->check() ? route('filament.admin.pages.dashboard') : route('filament.admin.auth.login') }}" class="rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-brand-600/30 transition hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:bg-brand-500 dark:hover:bg-brand-400">
                            {{ auth()->check() ? 'Open dashboard' : 'Sign in to ProSend' }}
                        </a>
                        <a href="#features" class="px-2 py-3 text-sm font-semibold text-zinc-700 transition hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
                            What it does <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>

                <div class="relative">
                    <div aria-hidden="true" class="absolute -inset-6 -z-10 rounded-[2rem] bg-linear-to-br from-brand-500/15 via-transparent to-brand-300/10 blur-2xl"></div>

                    <figure class="rounded-3xl bg-white/80 p-6 shadow-[0_1px_2px_rgb(0_0_0/0.04),0_32px_64px_-32px_rgb(0_0_0/0.25)] ring-1 ring-zinc-950/[0.07] backdrop-blur sm:p-8 dark:bg-zinc-900/80 dark:ring-white/10">
                        <figcaption class="flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold tracking-[0.06em] text-zinc-500 uppercase dark:text-zinc-400">Message timeline</p>
                                <p class="mt-1 truncate text-sm font-medium">Your order has shipped</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-600/15 ring-inset dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20">Delivered</span>
                        </figcaption>

                        <ol class="mt-8 space-y-6">
                            @foreach ($timeline as $step)
                                <li class="relative flex gap-4">
                                    @unless ($loop->last)
                                        <span aria-hidden="true" class="absolute top-5 left-[0.4375rem] h-[calc(100%+0.5rem)] w-px bg-zinc-200 dark:bg-white/10"></span>
                                    @endunless
                                    <span class="relative mt-1.5 size-3.5 shrink-0 rounded-full ring-4 ring-white {{ $step['dot'] }} dark:ring-zinc-900"></span>
                                    <div>
                                        <p class="text-sm font-semibold">{{ $step['label'] }}</p>
                                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $step['detail'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        <p class="mt-8 rounded-xl bg-zinc-50 px-4 py-3 text-xs leading-5 text-zinc-500 ring-1 ring-zinc-950/5 ring-inset dark:bg-white/[0.03] dark:text-zinc-400 dark:ring-white/5">
                            "Accepted" only means the provider took the email. ProSend shows "Delivered" when the provider confirms it.
                        </p>
                    </figure>
                </div>
            </section>
        </main>
    </div>

    <section id="features" class="border-t border-zinc-950/5 bg-zinc-50/70 dark:border-white/5 dark:bg-white/[0.02]">
        <div class="mx-auto max-w-6xl px-6 py-24">
            <h2 class="max-w-2xl text-3xl font-semibold tracking-tight text-balance">Built for teams that answer for their email.</h2>

            <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as $feature)
                    <div class="rounded-2xl bg-white p-6 shadow-[0_1px_2px_rgb(0_0_0/0.04)] ring-1 ring-zinc-950/[0.07] dark:bg-zinc-900/60 dark:ring-white/[0.08]">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-600/10 ring-inset dark:bg-brand-400/10 dark:text-brand-300 dark:ring-brand-400/20">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" />
                            </svg>
                        </div>
                        <h3 class="mt-5 text-base font-semibold tracking-tight">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $feature['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <footer class="border-t border-zinc-950/5 dark:border-white/5">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm text-zinc-500 dark:text-zinc-400">
            <p>&copy; {{ now()->year }} {{ config('app.name', 'ProSend') }}</p>
            <a href="{{ route('filament.admin.auth.login') }}" class="font-medium transition hover:text-zinc-950 dark:hover:text-white">Sign in</a>
        </div>
    </footer>
</body>
</html>
