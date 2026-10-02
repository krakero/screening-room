@props([
    'step',
    'title',
    'subtitle' => null,
    'skipRoute' => null,
    'backRoute' => null,
])

@php
    $steps = \App\Support\SetupProgress::STEPS;
    $wizardSteps = array_values(array_diff($steps, ['done']));
    $currentIndex = array_search($step, $wizardSteps, true);
@endphp

<div>
    <div class="mb-8 flex flex-col items-center gap-3 text-center">
        <span class="flex aspect-square size-10 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-6 fill-current" />
        </span>
        <flux:heading size="lg">{{ config('app.name', 'Screening Room') }}</flux:heading>
    </div>

    @if ($currentIndex !== false)
        <div class="mb-3 text-center">
            <flux:text size="sm" variant="subtle">{{ __('Step :current of :total', ['current' => $currentIndex + 1, 'total' => count($wizardSteps)]) }}</flux:text>
        </div>

        <div class="mb-8 flex items-center justify-center gap-2" role="presentation" aria-hidden="true">
            @foreach ($wizardSteps as $index => $key)
                <span @class([
                    'h-1.5 rounded-full transition-all',
                    'w-8 bg-accent' => $index === $currentIndex,
                    'w-4 bg-accent/50' => $index < $currentIndex,
                    'w-4 bg-line' => $index > $currentIndex,
                ])></span>
            @endforeach
        </div>
    @endif

    <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
        <flux:card.header>
            <flux:card.heading size="xl">{{ $title }}</flux:card.heading>

            @if ($subtitle)
                <flux:card.subheading>{{ $subtitle }}</flux:card.subheading>
            @endif
        </flux:card.header>

        <flux:card.body>
            {{ $slot }}
        </flux:card.body>

        @if ($backRoute || $skipRoute)
            <div class="mt-6 flex items-center justify-center gap-4 text-sm">
                @if ($backRoute)
                    <flux:link :href="route($backRoute)" wire:navigate class="text-ink-subtle">{{ __('Back') }}</flux:link>
                @endif

                @if ($backRoute && $skipRoute)
                    <span class="text-line" aria-hidden="true">&middot;</span>
                @endif

                @if ($skipRoute)
                    <flux:link :href="route($skipRoute)" wire:navigate class="text-ink-subtle">{{ __('Skip for now') }}</flux:link>
                @endif
            </div>
        @endif
    </flux:card>

    @auth
        <div class="mt-6 text-center">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-xs text-ink-subtle hover:text-ink hover:underline">{{ __('Sign out') }}</button>
            </form>
        </div>
    @endauth
</div>
