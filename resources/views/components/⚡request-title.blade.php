<?php

use App\Enums\LibraryState;
use App\Jobs\SubmitTitleRequest;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Services\Collection\Ownership;
use App\Support\IntegrationSettings;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component
{
    public Title $title;

    /** @var array<int, int> */
    public array $selectedSeasons = [];

    public function mount(Title $title): void
    {
        $this->title = $title;

        if ($title->isShow()) {
            $this->selectedSeasons = $this->requestableSeasons->pluck('season_number')->all();
        }
    }

    /**
     * @return Collection<int, \App\Models\Season>
     */
    #[Computed]
    public function requestableSeasons(): Collection
    {
        if (! $this->title->relationLoaded('seasons')) {
            $this->title->load('seasons');
        }

        return $this->title->seasons->reject(fn ($season) => $season->season_number === 0)->values();
    }

    #[Computed]
    public function status(): ?LibraryStatus
    {
        return $this->title->libraryStatus;
    }

    #[Computed]
    public function ownership(): \App\Services\Collection\OwnershipSummary
    {
        return app(Ownership::class)->forTitle($this->title);
    }

    public function openRequestModal(): void
    {
        if ($this->ownership->isOwned()) {
            \Flux\Flux::modal('confirm-request-owned-' . $this->getId())->show();
        } else {
            \Flux\Flux::modal('request-title-' . $this->title->id)->show();
        }
    }

    public function confirmRequestOwned(): void
    {
        \Flux\Flux::modal('confirm-request-owned-' . $this->getId())->close();
        \Flux\Flux::modal('request-title-' . $this->title->id)->show();
    }

    /**
     * Non-blocking (PERF-6): marks the title "pending" and dispatches `SubmitTitleRequest`
     * instead of calling Seerr inline. The button below shows "Requested" the instant it's
     * clicked, via the Alpine `optimistic()` wrapper (OPT-1); if this call itself throws,
     * Livewire's rejected promise reverts that and shows a toast. If the job fails later, the
     * row flips to `failed` and the existing `TitleRequestFailed` notification fires — this page
     * just reflects that on its next natural render.
     */
    #[Renderless]
    public function request(): void
    {
        if (! app(IntegrationSettings::class)->configured('seerr.url', 'seerr.api_key')) {
            throw ValidationException::withMessages([
                'title' => __('Could not send the request. Check the Seerr connection in Settings.'),
            ]);
        }

        LibraryStatus::updateOrCreate(
            ['title_id' => $this->title->id],
            ['state' => LibraryState::Pending],
        );

        SubmitTitleRequest::dispatch($this->title, $this->selectedSeasons);

        unset($this->status);
    }
}; ?>

{{--
    Fills the title row's "Watch on Plex" slot when the title isn't on Plex (see the parent
    ⚡show.blade.php, which only renders this component in that branch). The initial arm below is
    chosen server-side from `$this->status` — like the rest of this page's states — so a title
    that's already requested/failed/etc. never ships the "Request" trigger markup at all. Within
    an arm, a small Alpine `optimistic()` overlay (OPT-1) gives instant feedback on click, ahead
    of the server confirming.
--}}
<div>
    @if (! app(IntegrationSettings::class)->configured('seerr.url', 'seerr.api_key'))
        {{-- Seerr isn't configured; nothing to show here. --}}
    @elseif ($this->status && $this->status->state !== null)
        @php
            $requestStatusConfig = match ($this->status->state->value) {
                'available' => ['label' => __('Available'), 'icon' => 'check-circle'],
                'requested', 'pending' => match ($this->status->seerr_status?->value) {
                    'approved' => ['label' => __('Approved'), 'icon' => 'clock'],
                    default => ['label' => __('Pending approval'), 'icon' => 'clock'],
                },
                'downloading' => ['label' => __('Downloading'), 'icon' => 'arrow-down-tray'],
                'declined' => ['label' => __('Declined · Request again'), 'icon' => 'x-circle', 'retryable' => true],
                'failed' => ['label' => __('Request failed · Retry'), 'icon' => 'exclamation-triangle', 'retryable' => true],
                default => null,
            };
        @endphp

        @if ($requestStatusConfig)
            @if ($requestStatusConfig['retryable'] ?? false)
                <div x-data="optimistic('failed')">
                    <template x-if="value === 'failed'">
                        <flux:button
                            size="sm"
                            variant="danger"
                            icon="{{ $requestStatusConfig['icon'] }}"
                            x-on:click="set('requested', () => $wire.request())"
                        >
                            {{ $requestStatusConfig['label'] }}
                        </flux:button>
                    </template>

                    <template x-if="value === 'requested'">
                        <flux:button size="sm" variant="filled" icon="clock" disabled>{{ __('Requested') }}</flux:button>
                    </template>
                </div>
            @else
                <flux:button size="sm" variant="filled" icon="{{ $requestStatusConfig['icon'] }}" disabled>
                    {{ $requestStatusConfig['label'] }}
                </flux:button>
            @endif
        @endif
    @else
        <div x-data="optimistic(null)">
            <template x-if="value === null">
                <flux:button wire:click="openRequestModal" size="sm" variant="primary" icon="plus-circle">
                    {{ $title->isShow() ? __('Request seasons…') : __('Request') }}
                </flux:button>
            </template>

            <template x-if="value === 'requested'">
                <flux:button size="sm" variant="filled" icon="clock" disabled>{{ __('Requested') }}</flux:button>
            </template>

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
                                <flux:checkbox
                                    wire:model="selectedSeasons"
                                    value="{{ $season->season_number }}"
                                    :label="__('Season :number', ['number' => $season->season_number])"
                                />
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
        </div>
    @endif
</div>
