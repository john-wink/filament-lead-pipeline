<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $funnel->design['primary_color'] ?? '#3B82F6' }}">
    <title>{{ $funnel->name }}</title>

    @php
        $design      = $funnel->getResolvedDesign();
        $isObject    = is_object($design);
        $primary     = $isObject ? ($design->primary_color ?? '#3B82F6') : ($design['primary_color'] ?? '#3B82F6');
        $bg          = $isObject ? ($design->background_color ?? '#0f1d2b') : ($design['background_color'] ?? '#0f1d2b');
        $textColor   = $isObject ? ($design->text_color ?? '#ffffff') : ($design['text_color'] ?? '#ffffff');
        $radius      = $isObject ? ($design->border_radius ?? '12px') : ($design['border_radius'] ?? '12px');
        $maxWidth    = $isObject ? ($design->max_width ?? '640px') : ($design['max_width'] ?? '640px');
        $fontFamily  = $isObject ? ($design->font_family ?? 'Inter, system-ui, sans-serif') : ($design['font_family'] ?? 'Inter, system-ui, sans-serif');
        $bgImage     = $isObject ? ($design->background_image ?? null) : ($design['background_image'] ?? null);
        $logoUrl     = $isObject ? ($design->logo_url ?? null) : ($design['logo_url'] ?? null);
        $logoPos     = $isObject ? ($design->logo_position ?? 'center') : ($design['logo_position'] ?? 'center');
        $favicon     = $isObject ? ($design->favicon_url ?? null) : ($design['favicon_url'] ?? null);
        $customCss   = $isObject ? ($design->custom_css ?? '') : ($design['custom_css'] ?? '');

        $firstFont       = trim(explode(',', $fontFamily)[0]);
        $isSystemFont    = str_contains($fontFamily, 'system-ui')
                        || str_contains($fontFamily, '-apple-system')
                        || in_array(strtolower($firstFont), ['sans-serif', 'serif', 'monospace', 'inherit', 'initial']);
        $googleFontParam = urlencode($firstFont);

        // Compute luminance to determine light/dark theme for adaptive field styling
        $hex = ltrim($bg, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $isDark = $bgImage || $luminance < 0.5;

        // Adaptive contrast values
        $borderAlpha  = $isDark ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.15)';
        $fieldBg      = $isDark ? 'rgba(255,255,255,0.08)' : 'rgba(255,255,255,0.9)';
        $fieldHover   = $isDark ? 'rgba(255,255,255,0.14)' : 'rgba(0,0,0,0.04)';
        $subtleText   = $isDark ? 'rgba(255,255,255,0.55)' : 'rgba(0,0,0,0.45)';
        $errorColor   = $isDark ? '#fca5a5' : '#dc2626';
        $progressBg   = $isDark ? 'rgba(255,255,255,0.18)' : 'rgba(0,0,0,0.08)';
        $btnBg        = $isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.04)';
        $btnBorder    = $isDark ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.15)';
        $btnHover     = $isDark ? 'rgba(255,255,255,0.16)' : 'rgba(0,0,0,0.08)';
        $checkBorder  = $isDark ? 'rgba(255,255,255,0.4)'  : 'rgba(0,0,0,0.25)';
        $selectedBg   = $isDark ? 'rgba(255,255,255,0.12)' : 'color-mix(in srgb, var(--lp-primary) 8%, transparent)';
        $successBg    = $isDark ? 'rgba(5,150,105,0.15)'   : 'rgba(5,150,105,0.08)';
        $successBorder= $isDark ? 'rgba(5,150,105,0.3)'    : 'rgba(5,150,105,0.2)';
        $successCheck = $isDark ? '#34d399' : '#059669';
    @endphp

    @if($favicon)
        <link rel="icon" href="{{ $favicon }}">
    @endif

    @if(!$isSystemFont && $firstFont)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ $googleFontParam }}:wght@400;500;600;700&display=swap">
    @endif

    @livewireStyles

    <style>
        :root {
            --lp-primary:       {{ $primary }};
            --lp-bg:            {{ $bg }};
            --lp-text:          {{ $textColor }};
            --lp-radius:        {{ $radius }};
            --lp-max-width:     {{ $maxWidth }};
            --lp-font:          {{ $fontFamily }};
            --lp-border:        {{ $borderAlpha }};
            --lp-field-bg:      {{ $fieldBg }};
            --lp-field-hover:   {{ $fieldHover }};
            --lp-subtle:        {{ $subtleText }};
            --lp-error:         {{ $errorColor }};
            --lp-progress-bg:   {{ $progressBg }};
            --lp-btn-bg:        {{ $btnBg }};
            --lp-btn-border:    {{ $btnBorder }};
            --lp-btn-hover:     {{ $btnHover }};
            --lp-check-border:  {{ $checkBorder }};
            --lp-selected-bg:   {{ $selectedBg }};
            --lp-success-bg:    {{ $successBg }};
            --lp-success-border:{{ $successBorder }};
            --lp-success-check: {{ $successCheck }};
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--lp-font);
            background-color: var(--lp-bg);
            color: var(--lp-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        @if($bgImage)
        body {
            background-image: url('{{ $bgImage }}');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 0;
        }
        @endif

        .lp-funnel-main {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            min-height: 100vh;
        }

        .lp-funnel-wrapper {
            width: 100%;
            max-width: var(--lp-max-width);
            position: relative;
        }

        .lp-funnel-logo {
            text-align: {{ $logoPos }};
            margin-bottom: 2rem;
        }

        .lp-funnel-logo img {
            max-height: 48px;
            display: inline-block;
            object-fit: contain;
        }

        .lp-funnel-content {
            animation: lp-card-in 0.35s ease;
        }

        @keyframes lp-card-in {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 480px) {
            .lp-funnel-main {
                padding: 1.5rem 1rem;
            }
        }

        {{ $customCss }}
    </style>
</head>
<body>
    <div class="lp-funnel-main">
        <div class="lp-funnel-wrapper">
            @if($logoUrl)
                <div class="lp-funnel-logo">
                    <img src="{{ $logoUrl }}" alt="Logo">
                </div>
            @endif

            <div class="lp-funnel-content">
                @livewire('lead-pipeline::funnel-wizard', ['funnelId' => $funnel->getKey()])
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
