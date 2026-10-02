<?php

use App\Actions\Plays\RemovePlay;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Title as TitleModel;
use App\Support\DisplayTimezone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('History')] class extends Component
{
    use WithPagination;

    #[On('episode-watched-changed')]
    public function refreshPlays(): void
    {
        unset($this->plays, $this->groupedPlays);
    }

    /**
     * @return LengthAwarePaginator<int, Play>
     */
    #[Computed]
    public function plays(): LengthAwarePaginator
    {
        return Play::query()
            ->with(['playable' => fn ($morphTo) => $morphTo->morphWith([
                Episode::class => ['title', 'plexItem'],
                TitleModel::class => ['plexItem'],
            ])])
            ->orderByDesc('watched_at')
            ->paginate(20);
    }

    /**
     * @return Collection<string, Collection<int, Play>>
     */
    #[Computed]
    public function groupedPlays(): Collection
    {
        return $this->plays->getCollection()->groupBy(
            fn (Play $play) => $play->watched_at ? DisplayTimezone::local($play->watched_at)->toDateString() : 'unknown'
        );
    }

    public function removePlay(int $playId): void
    {
        $play = Play::query()->findOrFail($playId);

        app(RemovePlay::class)->handle($play);

        $this->resetPage();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
    <flux:heading size="xl">{{ __('History') }}</flux:heading>
    <flux:subheading>{{ __('Everything watched so far, most recent first.') }}</flux:subheading>

    @if ($this->plays->isEmpty())
        <x-media.empty-state icon="clock" :heading="__('No plays yet')">
            {{ __('Movies and episodes you mark watched will show up here.') }}
        </x-media.empty-state>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($this->groupedPlays as $date => $dayPlays)
                @php
                    $today = \App\Support\DisplayTimezone::today();
                    $heading = match (true) {
                        $date === 'unknown' => __('Unknown date'),
                        ($parsedDate = \Illuminate\Support\Carbon::parse($date))->isSameDay($today) => __('Today'),
                        $parsedDate->isSameDay($today->clone()->subDay()) => __('Yesterday'),
                        default => $parsedDate->format('l, F j, Y'),
                    };
                @endphp

                <x-media.poster-row :heading="$heading">
                    @foreach ($dayPlays as $play)
                        @include('pages.history._entry', ['play' => $play])
                    @endforeach
                </x-media.poster-row>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->plays" />
    @endif
</div>
