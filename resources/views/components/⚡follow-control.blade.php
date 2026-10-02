<?php

use App\Actions\Follows\FollowShow;
use App\Actions\Follows\PauseShow;
use App\Actions\Follows\ResumeShow;
use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Title;
use Livewire\Component;

new class extends Component
{
    public Title $title;

    public ?int $followId = null;

    public ?FollowState $state = null;

    public function mount(Title $title): void
    {
        $this->title = $title;
        $this->syncFromFollow($title->follow);
    }

    public function follow(): void
    {
        $this->syncFromFollow(app(FollowShow::class)->handle($this->title));
    }

    public function pause(): void
    {
        if ($this->followId === null) {
            return;
        }

        $this->syncFromFollow(app(PauseShow::class)->handle(Follow::findOrFail($this->followId)));
    }

    public function resume(): void
    {
        if ($this->followId === null) {
            return;
        }

        $this->syncFromFollow(app(ResumeShow::class)->handle(Follow::findOrFail($this->followId)));
    }

    private function syncFromFollow(?Follow $follow): void
    {
        $this->followId = $follow?->id;
        $this->state = $follow?->state;
    }
};
?>

<div class="flex items-center gap-3">
    @if ($state)
        @php
            $badge = match ($state) {
                FollowState::Watching => ['label' => __('Watching'), 'icon' => 'play-circle', 'tone' => 'accent'],
                FollowState::Paused => ['label' => __('Paused'), 'icon' => 'pause-circle', 'tone' => 'neutral', 'class' => '!bg-status-paused/15 !text-status-paused'],
                FollowState::Abandoned => ['label' => __('Abandoned'), 'icon' => 'eye-slash', 'tone' => 'neutral', 'class' => '!bg-status-abandoned/15 !text-status-abandoned'],
                FollowState::Completed => ['label' => __('Completed'), 'icon' => 'check-circle', 'tone' => 'success'],
            };
        @endphp

        <x-media.badge :tone="$badge['tone']" :icon="$badge['icon']" :class="$badge['class'] ?? ''">
            {{ $badge['label'] }}
        </x-media.badge>
    @endif

    @if ($state === null)
        <flux:button wire:click="follow" icon="plus" variant="primary" size="sm">{{ __('Follow') }}</flux:button>
    @elseif ($state === FollowState::Watching)
        <flux:button wire:click="pause" icon="pause" variant="ghost" size="sm">{{ __('Pause') }}</flux:button>
    @elseif ($state === FollowState::Paused || $state === FollowState::Abandoned)
        <flux:button wire:click="resume" icon="play" variant="ghost" size="sm">{{ __('Resume') }}</flux:button>
    @endif
</div>
