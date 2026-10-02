@props(['summary'])

@php
use App\Services\Collection\OwnershipSummary;

/** @var OwnershipSummary $summary */
@endphp

@if ($summary->isOwned())
    @php
        $formatIcons = collect($summary->formats())
            ->map(fn ($format) => $format->icon())
            ->unique()
            ->values();

        $firstIcon = $formatIcons->first() ?? 'square-3-stack-3d';
        $label = $summary->label();
    @endphp

    <x-media.badge tone="accent" :icon="$firstIcon" :title="$label">
        {{ __('Owned') }}
    </x-media.badge>
@endif
