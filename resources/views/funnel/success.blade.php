<div style="text-align: center; animation: lp-fade-in 0.3s ease;">
    <div style="width: 72px; height: 72px; margin: 0 auto 1.5rem; background: var(--lp-success-bg); border: 2px solid var(--lp-success-border); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--lp-success-check)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6L9 17l-5-5"/>
        </svg>
    </div>

    <h2 style="font-size: clamp(1.5rem, 4vw, 2rem); font-weight: 700; margin-bottom: 0.75rem; color: var(--lp-text);">
        {{ $funnel->success_config['heading'] ?? __('lead-pipeline::lead-pipeline.funnel.success_heading') }}
    </h2>
    <p style="color: var(--lp-text); opacity: 0.7; margin-bottom: 1.5rem; font-size: 1rem; line-height: 1.6;">
        {{ $funnel->success_config['text'] ?? __('lead-pipeline::lead-pipeline.funnel.success_text') }}
    </p>

    @if(!empty($funnel->success_config['calendar_embed']))
        <div style="margin-top: 1.5rem; border-radius: var(--lp-radius); overflow: hidden;">
            <iframe src="{{ $funnel->success_config['calendar_embed'] }}"
                style="width: 100%; min-height: 600px; border: none;"></iframe>
        </div>
    @endif

    @if(!empty($funnel->success_config['redirect_url']))
        <script>setTimeout(() => window.location.href = '{{ $funnel->success_config['redirect_url'] }}', 5000);</script>
        <p style="font-size: 0.75rem; color: var(--lp-subtle); margin-top: 1rem;">{{ __('lead-pipeline::lead-pipeline.funnel.redirect_notice') }}</p>
    @endif
</div>
