@props([
    'url',
    'regenerate',
    'label' => null,
])

<div class="mt-6 space-y-2">
    <flux:label>{{ $label ?? __('Webhook URL') }}</flux:label>

    {{ $slot }}

    <div
        class="flex items-center space-x-2"
        x-data="{
            copied: false,
            async copy() {
                try {
                    await navigator.clipboard.writeText('{{ $url }}');
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1500);
                } catch (e) {
                    console.warn('Could not copy to clipboard');
                }
            }
        }"
    >
        <div class="flex items-stretch w-full border rounded-xl border-line">
            <input type="text" readonly value="{{ $url }}" class="w-full p-3 bg-transparent outline-none text-ink text-sm" />
            <button type="button" @click="copy()" class="px-3 transition-colors border-l cursor-pointer border-line">
                <flux:icon.document-duplicate x-show="!copied" variant="outline" class="size-4" />
                <flux:icon.check x-show="copied" x-cloak variant="outline" class="size-4" />
            </button>
        </div>
        <flux:button variant="outline" size="sm" wire:click="{{ $regenerate }}">{{ __('Regenerate') }}</flux:button>
    </div>
</div>
