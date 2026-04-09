<div style="text-align: center; animation: lp-fade-in 0.3s ease;">
    <div style="width: 72px; height: 72px; margin: 0 auto 1.5rem; background: rgba(220, 38, 38, 0.12); border: 2px solid rgba(220, 38, 38, 0.25); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--lp-error)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <path d="m15 9-6 6M9 9l6 6"/>
        </svg>
    </div>

    <h2 style="font-size: clamp(1.5rem, 4vw, 2rem); font-weight: 700; margin-bottom: 0.75rem; color: var(--lp-text);">
        {{ $funnel->rejection_config['heading'] ?? __('lead-pipeline::lead-pipeline.funnel.rejection_heading') }}
    </h2>
    <p style="color: var(--lp-text); opacity: 0.7; margin-bottom: 1.5rem; font-size: 1rem; line-height: 1.6;">
        {{ $funnel->rejection_config['text'] ?? __('lead-pipeline::lead-pipeline.funnel.rejection_text') }}
    </p>

    @if(!empty($funnel->rejection_config['redirect_url']))
        <script>setTimeout(() => window.location.href = '{{ $funnel->rejection_config['redirect_url'] }}', 5000);</script>
        <p style="font-size: 0.75rem; color: var(--lp-subtle); margin-top: 1rem;">{{ __('lead-pipeline::lead-pipeline.funnel.redirect_notice') }}</p>
    @endif
</div>
