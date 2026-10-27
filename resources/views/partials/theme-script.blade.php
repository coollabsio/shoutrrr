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
            root.dataset.navItems = localStorage.getItem('navItems') === 'flat' ? 'flat' : '3d';
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
