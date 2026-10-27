<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @if (config('sentry-browser.dsn'))
            {{-- Browser Sentry config, delivered at runtime so a prebuilt bundle
                 can be pointed at a DSN via env. The DSN is a public value. --}}
            <script nonce="{{ Vite::cspNonce() }}">
                window.__sentry = @js(config('sentry-browser'));
            </script>
        @endif

        {{-- Apply theme, width, and nav style before paint. `theme` matches Coolify; `appearance` is the existing Shoutrrr key. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const root = document.documentElement;
                let theme = '{{ $appearance ?? "system" }}';
                try {
                    const stored = localStorage.getItem('theme');
                    if (stored === 'purple') theme = 'custom';
                    else if (stored === 'light' || stored === 'dark' || stored === 'system' || stored === 'custom') theme = stored;
                    else {
                        const appearance = localStorage.getItem('appearance');
                        if (appearance === 'light' || appearance === 'dark' || appearance === 'system') theme = appearance;
                    }
                    root.dataset.pageWidth = localStorage.getItem('pageWidth') === 'centered' ? 'centered' : 'full';
                    root.dataset.navStyle = localStorage.getItem('navStyle') === 'floating' ? 'floating' : 'default';
                    root.dataset.navItems = localStorage.getItem('navItems') === '3d' ? '3d' : 'flat';
                } catch (e) {}

                const hex = (value) => {
                    const body = String(value || '').trim().replace(/^#/, '');
                    return /^[a-f\d]{6}$/i.test(body) ? '#' + body.toLowerCase() : null;
                };
                const darkColor = (value) => {
                    const color = hex(value);
                    if (!color) return true;
                    const channels = color.match(/[a-f\d]{2}/gi).map((part) => {
                        const channel = parseInt(part, 16) / 255;
                        return channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
                    });
                    const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
                    return 1.05 / (luminance + 0.05) >= (luminance + 0.05) / 0.2126;
                };

                let dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (theme === 'custom') {
                    let colors = null;
                    try {
                        const parts = String(localStorage.getItem('customTheme') || '').split(/[\s,]+/).map(hex);
                        if (parts.length === 4 && parts.every(Boolean)) {
                            colors = { sidebar: parts[0], selected: parts[1], accent: parts[2], background: parts[3] };
                        }
                    } catch (e) {}
                    colors = colors || { sidebar: '#1a0f2d', selected: '#1c1c1c', accent: '#6b16ed', background: '#0a0a0a' };
                    dark = darkColor(colors.background);
                    const text = (value) => darkColor(value) ? '#f4f4f5' : '#18181b';
                    root.style.setProperty('--theme-bg', colors.background);
                    root.style.setProperty('--theme-text', text(colors.background));
                    root.style.setProperty('--theme-sidebar', colors.sidebar);
                    root.style.setProperty('--theme-sidebar-text', text(colors.sidebar));
                    root.style.setProperty('--theme-selected', colors.selected);
                    root.style.setProperty('--theme-selected-text', text(colors.selected));
                    root.style.setProperty('--theme-accent', colors.accent);
                    root.style.setProperty('--theme-accent-foreground', text(colors.accent));
                    root.dataset.theme = 'custom';
                }
                root.classList.toggle('dark', dark);
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon-16x16.png" type="image/png" sizes="16x16">
        <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/favicon-48x48.png" type="image/png" sizes="48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#101010">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
