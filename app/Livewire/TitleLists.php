<?php

namespace App\Livewire;

use App\Models\MediaList;
use App\Models\Title;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * @property-read MediaList $watchlist
 * @property-read bool $inWatchlist
 * @property-read Collection<int, MediaList> $customLists
 * @property-read Collection<int, int> $memberListIds
 */
class TitleLists extends Component
{
    public Title $title;

    public bool $creatingList = false;

    public string $newListName = '';

    #[Computed]
    public function watchlist(): MediaList
    {
        return MediaList::watchlist();
    }

    #[Computed]
    public function inWatchlist(): bool
    {
        return $this->memberListIds->contains($this->watchlist->id);
    }

    /**
     * @return Collection<int, MediaList>
     */
    #[Computed]
    public function customLists(): Collection
    {
        return MediaList::where('is_watchlist', false)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, int>
     */
    #[Computed]
    public function memberListIds(): Collection
    {
        return $this->title->mediaLists()->pluck('media_lists.id');
    }

    #[Renderless]
    public function toggleWatchlist(): void
    {
        $this->toggleList($this->watchlist->id);

        $this->dispatch('watchlist-changed', titleId: $this->title->id)->to(WatchlistToggle::class);
    }

    #[On('watchlist-changed')]
    public function refreshMembership(int $titleId): void
    {
        if ($titleId === $this->title->id) {
            unset($this->inWatchlist, $this->memberListIds);
        }
    }

    #[Renderless]
    public function toggleList(int $mediaListId): void
    {
        $mediaList = MediaList::findOrFail($mediaListId);

        if ($this->memberListIds->contains($mediaList->id)) {
            $mediaList->items()->where('title_id', $this->title->id)->get()->each->delete();
        } else {
            $position = ($mediaList->items()->max('position') ?? 0) + 1;
            $mediaList->items()->create(['title_id' => $this->title->id, 'position' => $position]);
        }

        unset($this->inWatchlist, $this->memberListIds);
    }

    public function createList(): void
    {
        $validated = $this->validate([
            'newListName' => ['required', 'string', 'max:255'],
        ]);

        $mediaList = MediaList::create([
            'name' => $validated['newListName'],
            'slug' => MediaList::uniqueSlug($validated['newListName']),
        ]);

        $mediaList->items()->create(['title_id' => $this->title->id, 'position' => 0]);

        $this->reset('newListName', 'creatingList');
        unset($this->customLists, $this->memberListIds);

        Flux::toast(variant: 'success', text: __('List created and title added.'));
    }

    public function render(): View
    {
        return view('livewire.title-lists');
    }
}
