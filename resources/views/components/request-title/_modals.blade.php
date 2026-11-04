<flux:modal name="confirm-request-owned-{{ $this->getId() }}" class="md:w-96">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Request anyway?') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('You already own this (:label). Request it anyway?', ['label' => $this->ownership->label()]) }}
            </flux:text>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" wire:click="confirmRequestOwned">
                {{ __('Request anyway') }}
            </flux:button>
        </div>
    </div>
</flux:modal>

<flux:modal name="request-title-{{ $title->id }}" focusable class="max-w-md">
    <div class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Request :name', ['name' => $title->name]) }}</flux:heading>

        @if ($title->isShow())
            <div class="flex flex-col gap-2">
                <flux:subheading>{{ __('Seasons') }}</flux:subheading>

                @foreach ($this->requestableSeasons as $season)
                    @if (in_array($season->season_number, $this->alreadyRequestedSeasons))
                        <flux:checkbox
                            :checked="true"
                            :disabled="true"
                            value="{{ $season->season_number }}"
                            :label="__('Season :number · Requested', ['number' => $season->season_number])"
                        />
                    @else
                        <flux:checkbox
                            wire:model="selectedSeasons"
                            value="{{ $season->season_number }}"
                            :label="__('Season :number', ['number' => $season->season_number])"
                        />
                    @endif
                @endforeach
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="primary"
                :disabled="$title->isShow() && empty($selectedSeasons)"
                x-on:click="$flux.modal('request-title-{{ $title->id }}').close(); set('requested', () => $wire.request())"
            >
                {{ __('Send request') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
