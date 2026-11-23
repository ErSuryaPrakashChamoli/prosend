<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ $senderName }}</title>

    @fonts

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite('resources/css/app.css')
    @endif
</head>
<body class="min-h-dvh bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-white">
    <div class="relative isolate flex min-h-dvh items-center justify-center px-4 py-16">
        <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 h-96 bg-[radial-gradient(42rem_20rem_at_50%_-6rem,color-mix(in_oklab,var(--color-brand-500)_16%,transparent),transparent_70%)]"></div>

        <main class="w-full max-w-md rounded-3xl bg-white p-8 text-center shadow-[0_1px_2px_rgb(0_0_0/0.04),0_24px_48px_-24px_rgb(0_0_0/0.18)] ring-1 ring-zinc-950/[0.07] sm:p-10 dark:bg-zinc-900 dark:shadow-none dark:ring-white/10">
            <p class="text-sm font-semibold text-brand-600 dark:text-brand-400">{{ $senderName }}</p>

            @yield('content')
        </main>
    </div>
</body>
</html>
