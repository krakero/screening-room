<?php

use App\Actions\Tmdb\ImportTitle;
use App\Enums\TitleType;
use App\Models\Title;
use App\Services\Tmdb\TmdbException;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app', ['fullBleed' => true])] class extends Component
{
    public string $type;

    public int $tmdbId;

    public ?string $name = null;

    public ?string $poster = null;

    public ?string $backdrop = null;

    public ?string $year = null;

    public ?string $error = null;

    public function mount(string $type, string $tmdbId): void
    {
        TitleType::from($type);

        $this->type = $type;
        $this->tmdbId = (int) $tmdbId;

        $this->name = request()->query('name');
        $this->poster = request()->query('poster');
        $this->backdrop = request()->query('backdrop');
        $this->year = request()->query('year');

        $existing = $this->findExisting();

        if ($existing !== null) {
            // Plain (non-navigate) redirect: a real HTTP 302, so it never leaves its own
            // history entry, unlike a wire:navigate redirect (see replaceWith() below).
            $this->redirect(route('titles.show', $existing));
        }
    }

    public function render()
    {
        return $this->view()->title($this->name ?? __('Adding to your library…'));
    }

    public function import(): void
    {
        $existing = $this->findExisting();

        if ($existing !== null) {
            $this->replaceWith($existing);

            return;
        }

        $this->error = null;

        $lock = Cache::lock("import-title:{$this->type}:{$this->tmdbId}", 30);

        if (! $lock->get()) {
            $existing = $this->findExisting();

            if ($existing !== null) {
                $this->replaceWith($existing);

                return;
            }

            $this->error = __('This title is already being added. Please wait a moment and try again.');

            return;
        }

        try {
            $title = app(ImportTitle::class)->handle(TitleType::from($this->type), $this->tmdbId);
        } catch (TmdbException) {
            $this->error = __('Could not add this title from TMDB. Please try again.');

            return;
        } finally {
            $lock->release();
        }

        $this->replaceWith($title);
    }

    private function findExisting(): ?Title
    {
        return Title::where('type', TitleType::from($this->type))->where('tmdb_id', $this->tmdbId)->first();
    }

    /**
     * wire:navigate's redirect() always pushes a new history entry, with no public
     * "replace" option (Livewire 4.4.6). This page is itself a transient history entry
     * (reached from search/discover/etc.), so leaving it must replace that entry rather
     * than push a new one, or Back lands back on this page and bounces forward again.
     */
    private function replaceWith(Title $title): void
    {
        $this->js('window.location.replace('.\Illuminate\Support\Js::from(route('titles.show', $title)).')');
    }
}; ?>

<div class="flex flex-1 flex-col pb-16" wire:init="import">
    <x-media.hero :backdrop="$backdrop" :poster="$poster">
        <x-slot:title>{{ $name ?? __('Adding to your library…') }}</x-slot:title>

        <x-slot:meta>
            @if ($year)
                <x-media.chip>{{ $year }}</x-media.chip>
            @endif
        </x-slot:meta>
    </x-media.hero>

    {{-- Fixed so the status is visible without scrolling on desktop, where the hero's
         title/poster row sits below the fold. z-10 (not the header/sidebar's z-40/z-20)
         so navigation chrome stays visible and usable above this overlay. --}}
    <div class="pointer-events-none fixed inset-0 z-10 flex items-center justify-center px-4">
        <div class="pointer-events-auto flex flex-col items-center gap-4 rounded-2xl bg-canvas/80 px-6 py-8 text-center backdrop-blur">
            @if ($error)
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$error">
                    <x-slot:actions>
                        <flux:button size="sm" wire:click="import">{{ __('Retry') }}</flux:button>
                    </x-slot:actions>
                </flux:callout>
            @else
                <div class="flex flex-col items-center gap-3" role="status" aria-live="polite">
                    <flux:icon.arrow-path class="size-12 animate-spin text-accent sm:size-16" />
                    <span class="text-base font-medium text-ink">{{ __('Adding to your library…') }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
