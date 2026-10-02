<?php

use App\Actions\Plays\LogPlay;
use App\Enums\WatchedAt;
use App\Livewire\Concerns\HasCustomWatchedAt;
use App\Models\Episode;
use App\Services\CalendarCache;
use App\Services\CalendarEntry;
use App\Support\DisplayTimezone;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calendar')] class extends Component
{
    use HasCustomWatchedAt;

    #[On('episode-watched-changed')]
    public function refreshEpisodeLists(): void
    {
        unset($this->catchUp, $this->agendaGroups, $this->monthGrid, $this->selectedDayEntries);
    }

    public string $view = 'agenda';

    public string $month;

    public ?string $selectedDate = null;

    public function mount(): void
    {
        $this->month = DisplayTimezone::today()->format('Y-m');
    }

    public function switchView(string $view): void
    {
        $this->view = in_array($view, ['agenda', 'month'], true) ? $view : 'agenda';
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->subMonthNoOverflow()->format('Y-m');
        $this->selectedDate = null;
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonthNoOverflow()->format('Y-m');
        $this->selectedDate = null;
    }

    public function selectDay(string $date): void
    {
        $this->selectedDate = $this->selectedDate === $date ? null : $date;
    }

    public function markWatched(int $episodeId, string $when = 'now'): void
    {
        $this->logWatched($episodeId, WatchedAt::from($when));
    }

    public function confirmCustomDatetime(string $customDatetimeTarget, string $customDatetime): void
    {
        $customDatetime = $this->resolveCustomDatetimeUtc($customDatetime);

        $this->logWatched((int) $customDatetimeTarget, WatchedAt::Custom, $customDatetime);

        Flux::modal('custom-watched-at')->close();
    }

    private function logWatched(int $episodeId, WatchedAt $when, ?CarbonImmutable $customDatetime = null): void
    {
        $episode = Episode::query()->findOrFail($episodeId);

        if (! $episode->plays()->exists()) {
            app(LogPlay::class)->handle($episode, $when, $customDatetime);
        }

        unset($this->catchUp, $this->agendaGroups, $this->monthGrid, $this->selectedDayEntries);
    }

    /**
     * @return Collection<int, CalendarEntry>
     */
    #[Computed]
    public function catchUp(): Collection
    {
        return app(CalendarCache::class)->catchUp();
    }

    /**
     * @return Collection<string, Collection<int, CalendarEntry>>
     */
    #[Computed]
    public function agendaGroups(): Collection
    {
        $today = DisplayTimezone::today();

        return app(CalendarCache::class)
            ->forRange($today, $today->clone()->addDays(60))
            ->groupBy(fn (CalendarEntry $entry): string => $entry->date->toDateString());
    }

    /**
     * @return Collection<int, array{date: Carbon, inMonth: bool, isToday: bool, entries: Collection<int, CalendarEntry>}>
     */
    #[Computed]
    public function monthGrid(): Collection
    {
        $monthStart = Carbon::parse($this->month.'-01')->startOfMonth();
        $monthEnd = $monthStart->clone()->endOfMonth();
        $gridStart = $monthStart->clone()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $monthEnd->clone()->endOfWeek(Carbon::SUNDAY);

        $entriesByDate = app(CalendarCache::class)
            ->forRange($gridStart, $gridEnd)
            ->groupBy(fn (CalendarEntry $entry): string => $entry->date->toDateString());

        $today = DisplayTimezone::today();
        $days = collect();
        $cursor = $gridStart->clone();

        while ($cursor->lte($gridEnd)) {
            $key = $cursor->toDateString();

            $days->push([
                'date' => $cursor->clone(),
                'inMonth' => $cursor->month === $monthStart->month,
                'isToday' => $cursor->isSameDay($today),
                'entries' => $entriesByDate->get($key, collect()),
            ]);

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return Collection<int, CalendarEntry>
     */
    #[Computed]
    public function selectedDayEntries(): Collection
    {
        if ($this->selectedDate === null) {
            return collect();
        }

        $day = $this->monthGrid->firstWhere(fn (array $day): bool => $day['date']->toDateString() === $this->selectedDate);

        return $day['entries'] ?? collect();
    }

    public function dayLabel(Carbon $date): string
    {
        $today = DisplayTimezone::today();

        return match (true) {
            $date->isSameDay($today) => __('Today'),
            $date->isSameDay($today->clone()->addDay()) => __('Tomorrow'),
            default => $date->format('D, M j'),
        };
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6" x-data="{ customDatetimeTarget: '', customDatetime: '' }">
    <x-media.section-header :heading="__('Calendar')" :subheading="__('Upcoming episodes and releases for everything you follow.')">
        <x-slot:actions>
            <flux:button.group>
                <flux:button size="sm" :variant="$view === 'agenda' ? 'primary' : 'ghost'" wire:click="switchView('agenda')">
                    {{ __('Agenda') }}
                </flux:button>
                <flux:button size="sm" :variant="$view === 'month' ? 'primary' : 'ghost'" wire:click="switchView('month')">
                    {{ __('Month') }}
                </flux:button>
            </flux:button.group>
        </x-slot:actions>
    </x-media.section-header>

    @if ($view === 'agenda')
        <section class="flex flex-col gap-6">
            @if ($this->agendaGroups->isEmpty())
                <x-media.empty-state icon="calendar-days" :heading="__('Nothing coming up')">
                    {{ __('New episodes of the shows you follow, Watchlist movie releases, and season premieres will show up here.') }}
                </x-media.empty-state>
            @else
                @foreach ($this->agendaGroups as $date => $entries)
                    @php $parsedDate = \Illuminate\Support\Carbon::parse($date); @endphp

                    <div class="flex flex-col gap-2">
                        <x-media.section-header :heading="$this->dayLabel($parsedDate)" />

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach ($entries as $entry)
                                @include('pages.calendar._entry', ['entry' => $entry])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </section>
    @else
        <section class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousMonth" />
                <flux:heading size="lg">{{ \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" />
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-ink-subtle">
                @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $label)
                    <span>{{ $label }}</span>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-1">
                @foreach ($this->monthGrid as $day)
                    @php
                        $dateKey = $day['date']->toDateString();
                        $isSelected = $selectedDate === $dateKey;
                    @endphp

                    <button
                        type="button"
                        wire:click="selectDay('{{ $dateKey }}')"
                        wire:key="calendar-day-{{ $dateKey }}"
                        @class([
                            'flex min-h-20 flex-col items-start gap-1 rounded-lg border p-1.5 text-left transition',
                            'border-line bg-surface hover:bg-surface-raised' => ! $isSelected,
                            'border-accent bg-surface-raised ring-2 ring-accent' => $isSelected,
                            'opacity-40' => ! $day['inMonth'],
                        ])
                    >
                        <span @class([
                            'flex size-6 items-center justify-center rounded-full text-xs',
                            'bg-accent text-accent-foreground font-semibold' => $day['isToday'],
                            'text-ink-muted' => ! $day['isToday'],
                        ])>
                            {{ $day['date']->format('j') }}
                        </span>

                        @if ($day['entries']->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach ($day['entries']->take(4) as $entry)
                                    @php
                                        $dotColor = match ($entry->type) {
                                            \App\Enums\CalendarEntryType::Episode => 'bg-accent',
                                            \App\Enums\CalendarEntryType::MovieRelease => 'bg-status-available',
                                            \App\Enums\CalendarEntryType::SeasonPremiere => 'bg-status-requested',
                                        };
                                    @endphp
                                    <span class="size-1.5 rounded-full {{ $dotColor }}" title="{{ $entry->title->name }}"></span>
                                @endforeach
                            </div>
                        @endif
                    </button>
                @endforeach
            </div>

            @if ($selectedDate)
                <div class="flex flex-col gap-2">
                    <x-media.section-header :heading="$this->dayLabel(\Illuminate\Support\Carbon::parse($selectedDate))" />

                    @if ($this->selectedDayEntries->isEmpty())
                        <p class="text-sm text-ink-subtle">{{ __('Nothing on this day.') }}</p>
                    @else
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach ($this->selectedDayEntries as $entry)
                                @include('pages.calendar._entry', ['entry' => $entry])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @endif

    @if ($this->catchUp->isNotEmpty())
        <section class="flex flex-col gap-2">
            <x-media.section-header :heading="__('Last 7 days')" :subheading="__('Aired and unwatched — catch up.')" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($this->catchUp as $entry)
                    @include('pages.calendar._entry', ['entry' => $entry])
                @endforeach
            </div>
        </section>
    @endif

    <flux:modal name="custom-watched-at" class="md:w-96">
        <form
            x-on:submit.prevent="
                $flux.modal('custom-watched-at').close();
                window.dispatchEvent(new CustomEvent('watched-custom-flip', { detail: { target: customDatetimeTarget } }));
                $wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch(() => {
                    window.dispatchEvent(new CustomEvent('watched-custom-flip-revert', { detail: { target: customDatetimeTarget } }));
                    window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } }));
                });
            "
            class="space-y-6"
        >
            <div>
                <flux:heading size="lg">{{ __('Pick a date & time') }}</flux:heading>
            </div>

            <flux:input type="datetime-local" x-model="customDatetime" name="customDatetime" :label="__('Watched at')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
