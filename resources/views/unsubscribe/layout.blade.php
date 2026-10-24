<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ $senderName }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f4f5; --card: #ffffff; --text: #18181b; --muted: #52525b; --accent: #4f46e5; }
        @media (prefers-color-scheme: dark) { :root { --bg: #09090b; --card: #18181b; --text: #f4f4f5; --muted: #a1a1aa; --accent: #818cf8; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: min(440px, calc(100% - 32px)); background: var(--card); border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        h1 { margin: 0 0 8px; font-size: 1.375rem; line-height: 1.3; }
        p { margin: 0 0 16px; color: var(--muted); }
        strong { color: var(--text); word-break: break-all; }
        button { width: 100%; border: 0; border-radius: 8px; padding: 12px 16px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
