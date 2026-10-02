<div>
    <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="{{ __('Accent color') }}">
        @foreach ($this->presets as $key => $preset)
            @php($isSelected = ! $useCustom && $accent === $key)

            <button
                type="button"
                wire:click="selectPreset('{{ $key }}')"
                wire:loading.attr="disabled"
                wire:target="selectPreset('{{ $key }}')"
                role="radio"
                aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                title="{{ $preset['label'] }}"
                class="flex size-10 items-center justify-center rounded-full ring-2 ring-offset-2 ring-offset-surface transition {{ $isSelected ? 'ring-ink' : 'ring-transparent hover:ring-line' }}"
                style="background-color: {{ $preset['accent'] }}"
            >
                @if ($isSelected)
                    <flux:icon.check class="size-4" style="color: {{ $preset['foreground'] }}" />
                @endif
                <span class="sr-only">{{ $preset['label'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap items-end gap-3">
        <flux:input
            wire:model="customHex"
            :label="__('Custom hex')"
            placeholder="#7c3aed"
            maxlength="7"
            class="w-40"
        />

        <flux:button type="button" wire:click="applyCustom" variant="{{ $useCustom ? 'primary' : 'filled' }}">
            {{ __('Use custom color') }}
        </flux:button>

        @if ($useCustom && \App\Support\AccentColor::isValidHex($customHex))
            <span
                class="inline-block size-8 shrink-0 rounded-full ring-2 ring-ink ring-offset-2 ring-offset-surface"
                style="background-color: {{ $customHex }}"
                aria-hidden="true"
            ></span>
        @endif
    </div>

    <flux:error name="accent" />

    @if ($this->customHexIsLowContrast)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Low contrast')" class="mt-3">
            <flux:callout.text>
                {{ __('This color nearly disappears against the page background. Consider a bolder shade.') }}
            </flux:callout.text>
        </flux:callout>
    @endif
</div>

@script
    <script>
        $wire.on('accent-applied', ({ preset, light, dark }) => {
            const root = document.documentElement;

            // Presets are handled entirely by app.css's :root[data-accent]/:root.dark[data-accent]
            // rules (both themes), so no inline override is needed — and any leftover custom <style>
            // from an earlier choice this page-view has to be cleared out.
            let customStyle = document.getElementById('accent-custom-override');

            if (preset) {
                root.setAttribute('data-accent', preset);

                if (customStyle) {
                    customStyle.remove();
                }
            } else {
                root.removeAttribute('data-accent');

                if (! customStyle) {
                    customStyle = document.createElement('style');
                    customStyle.id = 'accent-custom-override';
                    document.head.appendChild(customStyle);
                }

                customStyle.textContent = `
                    :root {
                        --color-accent: ${light.accent};
                        --color-accent-content: ${light.accent};
                        --color-accent-foreground: ${light.foreground};
                    }

                    :root.dark {
                        --color-accent: ${dark.accent};
                        --color-accent-content: ${dark.accent};
                        --color-accent-foreground: ${dark.foreground};
                    }
                `;
            }

            try {
                window.localStorage.setItem('accent', preset ?? light.accent);
            } catch (e) {}
        });
    </script>
@endscript
