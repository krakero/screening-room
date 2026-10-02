<?php

namespace App\Livewire;

use App\Models\MediaList;
use App\Models\Title;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * One-click Watchlist toggle for a title. Stays in sync with the overflow menu's
 * TitleLists submenu via `watchlist-changed` events targeted at each other.
 *
 * @property-read bool $onWatchlist
 */
class WatchlistToggle extends Component
{
    public Title $title;

    #[Computed]
    public function onWatchlist(): bool
    {
        return $this->title->mediaLists()->where('media_lists.id', MediaList::watchlist()->id)->exists();
    }

    #[Renderless]
    public function toggle(): void
    {
        abort_unless(auth()->check(), 403);

        $watchlist = MediaList::watchlist();

        // Model create/delete (not attach/detach) so MediaListItemObserver refreshes Up Next and
        // created_at is set for its "Recently Added to Watchlist" ordering.
        if ($this->onWatchlist) {
            $watchlist->items()->where('title_id', $this->title->id)->get()->each->delete();
        } else {
            $watchlist->items()->create([
                'title_id' => $this->title->id,
                'position' => ($watchlist->items()->max('position') ?? 0) + 1,
            ]);
        }

        unset($this->onWatchlist);

        $this->dispatch('watchlist-changed', titleId: $this->title->id)->to(TitleLists::class);
    }

    #[On('watchlist-changed')]
    public function refreshWatchlistState(int $titleId): void
    {
        if ($titleId === $this->title->id) {
            unset($this->onWatchlist);
        }
    }

    public function render(): View
    {
        return view('livewire.watchlist-toggle');
    }
}
