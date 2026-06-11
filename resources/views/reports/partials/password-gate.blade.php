<div class="flex min-h-screen items-center justify-center px-4">
    <form wire:submit="unlock" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-8 shadow">
        <h1 class="text-lg font-semibold">{{ __('lead-pipeline::reports.password_required') }}</h1>
        <input type="password" wire:model="passwordInput" class="w-full rounded-lg border-gray-300"
               placeholder="{{ __('lead-pipeline::reports.password_placeholder') }}" autofocus>
        @error('passwordInput') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <button type="submit" class="w-full rounded-lg px-4 py-2 font-medium text-white" style="background: var(--report-accent)">
            {{ __('lead-pipeline::reports.password_submit') }}
        </button>
    </form>
</div>
