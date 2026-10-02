<?php

use App\Actions\Collection\AddCollectionItem;
use App\Actions\Collection\RemoveCollectionItem;
use App\Actions\Collection\UpdateCollectionItem;
use App\Enums\CollectionFormat;
use App\Http\Requests\Collection\CollectionItemRequest;
use App\Models\CollectionItem;
use App\Models\Season;
use App\Models\Title;
use App\Services\Collection\Ownership;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Title $title;

    public ?Season $season = null;

    public ?int $editingItemId = null;

    public string $format = '';

    public ?int $seasonId = null;

    public string $edition = '';

    public string $retailer = '';

    public string $barcode = '';

    public ?string $acquiredAt = null;

    public ?string $price = null;

    public string $currency = '';

    public string $location = '';

    public string $loanedTo = '';

    public ?string $loanedAt = null;

    public string $notes = '';

    public function mount(Title $title, ?Season $season = null): void
    {
        $this->title = $title;
        $this->season = $season;
    }

    #[Computed]
    public function ownership(): \App\Services\Collection\OwnershipSummary
    {
        $ownership = app(Ownership::class);

        return $this->season
            ? $ownership->forSeason($this->season)
            : $ownership->forTitle($this->title);
    }

    #[Computed]
    public function copies(): Collection
    {
        return $this->season
            ? $this->season->collectionItems()->with('season')->get()
            : $this->title->collectionItems()->with('season')->whereNull('season_id')->get();
    }

    #[Computed]
    public function availableSeasons(): Collection
    {
        if ($this->title->isMovie()) {
            return collect();
        }

        $this->title->loadMissing('seasons');

        return $this->title->seasons
            ->reject(fn (Season $s) => $s->season_number === 0)
            ->sortBy('season_number')
            ->values();
    }

    public function openAddModal(): void
    {
        $this->resetForm();

        if ($this->season) {
            $this->seasonId = $this->season->id;
        }

        \Flux\Flux::modal('collection-item-modal-' . $this->getId())->show();
    }

    public function openEditModal(int $itemId): void
    {
        $item = CollectionItem::findOrFail($itemId);

        $this->editingItemId = $item->id;
        $this->format = $item->format->value;
        $this->seasonId = $item->season_id;
        $this->edition = $item->edition ?? '';
        $this->retailer = $item->retailer ?? '';
        $this->barcode = $item->barcode ?? '';
        $this->acquiredAt = $item->acquired_at?->toDateString();
        $this->price = $item->price !== null ? (string) $item->price : null;
        $this->currency = $item->currency ?? '';
        $this->location = $item->location ?? '';
        $this->loanedTo = $item->loaned_to ?? '';
        $this->loanedAt = $item->loaned_at?->toDateString();
        $this->notes = $item->notes ?? '';

        \Flux\Flux::modal('collection-item-modal-' . $this->getId())->show();
    }

    public function save(): void
    {
        // Map component properties to validation rule keys
        $data = [
            'format' => $this->format,
            'season_id' => $this->seasonId,
            'edition' => $this->edition,
            'retailer' => $this->retailer,
            'barcode' => $this->barcode,
            'acquired_at' => $this->acquiredAt,
            'price' => $this->price,
            'currency' => $this->currency,
            'location' => $this->location,
            'loaned_to' => $this->loanedTo,
            'loaned_at' => $this->loanedAt,
            'notes' => $this->notes,
        ];

        $validated = validator($data, CollectionItemRequest::rules())->validate();

        if ($this->season) {
            $validated['season_id'] = $this->season->id;
        }

        if ($this->editingItemId) {
            $item = CollectionItem::findOrFail($this->editingItemId);
            app(UpdateCollectionItem::class)->handle($item, $validated);
        } else {
            app(AddCollectionItem::class)->handle($this->title, $validated);
        }

        $this->title->unsetRelation('collectionItems');
        $this->season?->unsetRelation('collectionItems');

        unset($this->ownership, $this->copies);

        \Flux\Flux::modal('collection-item-modal-' . $this->getId())->close();
    }

    public function confirmRemove(int $itemId): void
    {
        $this->editingItemId = $itemId;

        \Flux\Flux::modal('confirm-remove-' . $this->getId())->show();
    }

    public function remove(): void
    {
        if ($this->editingItemId === null) {
            return;
        }

        $item = CollectionItem::findOrFail($this->editingItemId);

        app(RemoveCollectionItem::class)->handle($item);

        $this->title->unsetRelation('collectionItems');
        $this->season?->unsetRelation('collectionItems');

        unset($this->ownership, $this->copies);

        \Flux\Flux::modal('confirm-remove-' . $this->getId())->close();
    }

    private function resetForm(): void
    {
        $this->editingItemId = null;
        $this->format = '';
        $this->seasonId = null;
        $this->edition = '';
        $this->retailer = '';
        $this->barcode = '';
        $this->acquiredAt = null;
        $this->price = null;
        $this->currency = '';
        $this->location = '';
        $this->loanedTo = '';
        $this->loanedAt = null;
        $this->notes = '';

        $this->resetValidation();
    }
};
?>

<div>
    @if (! app(Ownership::class)->isEnabled())
        {{-- Component must always have a root tag for Livewire --}}
    @else
        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <x-media.section-header :heading="__('In your collection')" />

                <flux:button wire:click="openAddModal" icon="plus" variant="ghost" size="sm">
                    {{ __('Add copy') }}
                </flux:button>
            </div>

        @if ($this->ownership->isOwned())
            <div class="flex flex-col gap-2">
                @foreach ($this->copies as $copy)
                    <div
                        class="flex items-center justify-between gap-4 rounded-lg border border-line bg-surface px-4 py-3"
                        wire:key="copy-{{ $copy->id }}"
                    >
                        <div class="flex flex-1 flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-media.badge :icon="$copy->format->icon()" tone="accent">
                                    {{ $copy->format->label() }}
                                </x-media.badge>

                                @if ($copy->edition)
                                    <x-media.chip>{{ $copy->edition }}</x-media.chip>
                                @endif

                                @if ($copy->season)
                                    <x-media.chip>{{ __('Season :number', ['number' => $copy->season->season_number]) }}</x-media.chip>
                                @endif

                                @if ($copy->retailer)
                                    <x-media.chip>{{ $copy->retailer }}</x-media.chip>
                                @endif

                                @if ($copy->loaned_to)
                                    <x-media.badge icon="arrow-top-right-on-square" tone="warning">
                                        {{ __('Loaned to :name', ['name' => $copy->loaned_to]) }}
                                    </x-media.badge>
                                @endif

                                @if ($copy->location)
                                    <x-media.chip icon="map-pin">{{ $copy->location }}</x-media.chip>
                                @endif
                            </div>

                            @if ($copy->notes)
                                <p class="text-xs text-ink-muted">{{ $copy->notes }}</p>
                            @endif
                        </div>

                        <div class="flex gap-1">
                            <flux:button wire:click="openEditModal({{ $copy->id }})" icon="pencil" variant="ghost" size="sm" inset="top bottom">
                                {{ __('Edit') }}
                            </flux:button>

                            <flux:button wire:click="confirmRemove({{ $copy->id }})" icon="trash" variant="ghost" size="sm" inset="top bottom">
                                {{ __('Remove') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach

                @if ($this->ownership->inPlex)
                    <div class="flex items-center gap-2 rounded-lg border border-line bg-surface px-4 py-3">
                        <x-media.badge icon="play" tone="plex">Plex</x-media.badge>
                        <span class="text-sm text-ink-muted">{{ __('Available in your Plex library') }}</span>
                    </div>
                @endif
            </div>
        @else
            <x-media.empty-state icon="film" :heading="__('No copies yet')">
                {{ __('Add physical or digital copies of :title to your collection.', ['title' => $title->name]) }}
            </x-media.empty-state>
        @endif

        <flux:modal name="collection-item-modal-{{ $this->getId() }}" class="md:max-w-lg">
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:heading size="lg">
                    {{ $editingItemId ? __('Edit copy') : __('Add copy') }}
                </flux:heading>

                <flux:field>
                    <flux:label>{{ __('Format') }}</flux:label>
                    <flux:select wire:model="format">
                        <flux:select.option value="">{{ __('Select format') }}</flux:select.option>
                        @foreach (CollectionFormat::cases() as $formatCase)
                            <flux:select.option value="{{ $formatCase->value }}">{{ $formatCase->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="format" />
                </flux:field>

                @if ($this->availableSeasons->isNotEmpty() && ! $season)
                    <flux:field>
                        <flux:label>{{ __('Season') }}</flux:label>
                        <flux:select wire:model="seasonId">
                            <flux:select.option value="">{{ __('Full series / Box set') }}</flux:select.option>
                            @foreach ($this->availableSeasons as $availableSeason)
                                <flux:select.option value="{{ $availableSeason->id }}">
                                    {{ __('Season :number', ['number' => $availableSeason->season_number]) }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="season_id" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>{{ __('Edition') }}</flux:label>
                    <flux:input wire:model="edition" :placeholder="__('e.g., Collector\'s Edition, Steelbook')" />
                    <flux:error name="edition" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Retailer') }}</flux:label>
                    <flux:input wire:model="retailer" :placeholder="__('e.g., Best Buy, Amazon')" />
                    <flux:error name="retailer" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Barcode') }}</flux:label>
                    <flux:input wire:model="barcode" />
                    <flux:error name="barcode" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Acquired') }}</flux:label>
                        <flux:input type="date" wire:model="acquiredAt" />
                        <flux:error name="acquired_at" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-2">
                        <flux:field>
                            <flux:label>{{ __('Price') }}</flux:label>
                            <flux:input type="number" step="0.01" min="0" wire:model="price" />
                            <flux:error name="price" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Currency') }}</flux:label>
                            <flux:input wire:model="currency" :placeholder="__('USD')" maxlength="3" />
                            <flux:error name="currency" />
                        </flux:field>
                    </div>
                </div>

                <flux:field>
                    <flux:label>{{ __('Location') }}</flux:label>
                    <flux:input wire:model="location" :placeholder="__('e.g., Living room shelf')" />
                    <flux:error name="location" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Loaned to') }}</flux:label>
                        <flux:input wire:model="loanedTo" />
                        <flux:error name="loaned_to" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Loaned on') }}</flux:label>
                        <flux:input type="date" wire:model="loanedAt" />
                        <flux:error name="loaned_at" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="3" />
                    <flux:error name="notes" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">
                        {{ $editingItemId ? __('Save changes') : __('Add to collection') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="confirm-remove-{{ $this->getId() }}" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Remove from collection?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('This will permanently remove this copy from your collection.') }}
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" wire:click="remove">
                        {{ __('Remove') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
        </section>
    @endif
</div>
