@props([
    'items',
])

<flux:card variant="outline" :highlight="false" class="mb-6 border-line bg-surface">
<ul class="space-y-2.5 text-sm">
    @foreach ($items as $item)
        <li class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <span class="text-ink">{{ $item['label'] }}</span>
                @if (! empty($item['note']))
                    <span class="block text-xs text-ink-subtle">{{ $item['note'] }}</span>
                @endif
            </div>

            @if ($item['ready'])
                <span class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-status-available">
                    <flux:icon.check-circle variant="micro" class="size-4" />
                    {{ __('Ready') }}
                </span>
            @else
                <span class="shrink-0 text-xs text-ink-subtle">{{ __('needs') }}: {{ $item['needs'] }}</span>
            @endif
        </li>
    @endforeach
</ul>
</flux:card>
