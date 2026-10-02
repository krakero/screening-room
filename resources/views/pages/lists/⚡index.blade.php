<?php

use App\Models\MediaList;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lists')] class extends Component
{
    public string $name = '';

    public ?int $editingListId = null;

    public string $editingName = '';

    public ?int $deletingListId = null;

    #[Computed]
    public function lists()
    {
        return MediaList::withCount('items')
            ->with(['titles' => fn ($query) => $query
                ->select('titles.id', 'titles.name', 'titles.poster_path')
                ->orderBy('media_list_items.position')
                ->limit(5)])
            ->orderByDesc('is_watchlist')
            ->orderBy('name')
            ->get();
    }

    public function createList(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        MediaList::create([
            'name' => $validated['name'],
            'slug' => MediaList::uniqueSlug($validated['name']),
        ]);

        $this->reset('name');
        unset($this->lists);

        Flux::modal('create-list')->close();
        Flux::toast(variant: 'success', text: __('List created.'));
    }

    public function startRenaming(int $listId): void
    {
        $mediaList = MediaList::findOrFail($listId);

        abort_if($mediaList->is_watchlist, 403);

        $this->editingListId = $mediaList->id;
        $this->editingName = $mediaList->name;

        Flux::modal('rename-list')->show();
    }

    public function renameList(): void
    {
        $mediaList = MediaList::findOrFail($this->editingListId);

        abort_if($mediaList->is_watchlist, 403);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
        ]);

        $mediaList->update(['name' => $validated['editingName']]);

        unset($this->lists);

        Flux::modal('rename-list')->close();
        Flux::toast(variant: 'success', text: __('List renamed.'));
    }

    public function confirmDelete(int $listId): void
    {
        $mediaList = MediaList::findOrFail($listId);

        abort_if($mediaList->is_watchlist, 403);

        $this->deletingListId = $mediaList->id;

        Flux::modal('delete-list')->show();
    }

    public function deleteList(): void
    {
        $mediaList = MediaList::findOrFail($this->deletingListId);

        abort_if($mediaList->is_watchlist, 403);

        $mediaList->delete();

        $this->deletingListId = null;
        unset($this->lists);

        Flux::modal('delete-list')->close();
        Flux::toast(variant: 'success', text: __('List deleted.'));
    }
}; ?>

<div class="flex flex-col gap-6 py-6">
    <x-media.section-header :heading="__('Lists')" :subheading="__('Your watchlist and custom lists.')">
        <x-slot:actions>
            <flux:modal.trigger name="create-list">
                <flux:button icon="plus" variant="primary">{{ __('New list') }}</flux:button>
            </flux:modal.trigger>
        </x-slot:actions>
    </x-media.section-header>

    @if ($this->lists->isEmpty())
        <x-media.empty-state icon="queue-list" :heading="__('No lists yet')">
            {{ __('Create a list to start organizing titles.') }}
        </x-media.empty-state>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->lists as $mediaList)
                <flux:card wire:key="list-{{ $mediaList->id }}" variant="outline" size="sm" :highlight="false" class="border-line bg-surface">
<flux:card.body class="flex h-full flex-col justify-between gap-4">
                    <a href="{{ route('lists.show', $mediaList) }}" wire:navigate class="group/list flex flex-col gap-4">
                        <div class="flex h-32 items-end rounded-lg bg-surface-raised px-3 pb-3 ring-1 ring-inset ring-line transition group-hover/list:ring-2 group-hover/list:ring-accent" aria-hidden="true">
                            @forelse ($mediaList->titles as $title)
                                <div
                                    class="relative aspect-2/3 h-24 shrink-0 overflow-hidden rounded-md bg-surface shadow-lg ring-1 ring-canvas {{ $loop->first ? '' : '-ms-6' }}"
                                    style="z-index: {{ 10 - $loop->index }}"
                                >
                                    @if ($title->posterUrl('w154'))
                                        <img src="{{ $title->posterUrl('w154') }}" alt="" loading="lazy" class="size-full object-cover" />
                                    @else
                                        <span class="flex size-full items-center justify-center bg-gradient-to-br from-surface-raised to-surface px-1 text-center text-[10px] font-semibold text-ink-muted">
                                            {{ str($title->name)->limit(20) }}
                                        </span>
                                    @endif
                                </div>
                            @empty
                                @foreach (range(1, 3) as $placeholder)
                                    <div class="aspect-2/3 h-24 shrink-0 rounded-md border border-dashed border-line {{ $loop->first ? '' : 'ms-2' }}"></div>
                                @endforeach
                            @endforelse

                            @if ($mediaList->items_count > $mediaList->titles->count())
                                <span class="ms-3 self-center text-sm font-medium text-ink-muted">
                                    +{{ $mediaList->items_count - $mediaList->titles->count() }}
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($mediaList->is_watchlist)
                                <flux:icon.bookmark variant="solid" class="size-4 text-accent" />
                            @endif

                            <flux:heading>{{ $mediaList->name }}</flux:heading>
                        </div>

                        <x-media.chip class="self-start">
                            {{ trans_choice(':count title|:count titles', $mediaList->items_count, ['count' => $mediaList->items_count]) }}
                        </x-media.chip>
                    </a>

                    @unless ($mediaList->is_watchlist)
                        <div class="flex items-center gap-2">
                            <flux:button size="sm" variant="filled" wire:click="startRenaming({{ $mediaList->id }})">
                                {{ __('Rename') }}
                            </flux:button>

                            <flux:button size="sm" variant="danger" wire:click="confirmDelete({{ $mediaList->id }})">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    @endunless
                </flux:card.body>
            </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal name="create-list" class="md:w-96">
        <form wire:submit="createList" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New list') }}</flux:heading>
                <flux:subheading>{{ __('Give your list a name.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" autofocus />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="rename-list" class="md:w-96">
        <form wire:submit="renameList" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Rename list') }}</flux:heading>
            </div>

            <flux:input wire:model="editingName" :label="__('Name')" autofocus />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-list" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete list?') }}</flux:heading>
                <flux:subheading>{{ __('This cannot be undone.') }}</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteList">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
