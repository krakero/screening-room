<?php

namespace App\Jobs;

use App\Actions\Tmdb\RefreshTrailer;
use App\Models\Title;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshTitleTrailer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly Title $title) {}

    public function uniqueId(): string
    {
        return (string) $this->title->id;
    }

    public function handle(RefreshTrailer $refreshTrailer): void
    {
        $refreshTrailer->handle($this->title);
    }
}
