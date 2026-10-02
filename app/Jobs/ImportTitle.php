<?php

namespace App\Jobs;

use App\Actions\Tmdb\ImportTitle as ImportTitleAction;
use App\Enums\TitleType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportTitle implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly TitleType $type,
        public readonly int $tmdbId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->type->value}:{$this->tmdbId}";
    }

    public function handle(ImportTitleAction $importTitle): void
    {
        $importTitle->handle($this->type, $this->tmdbId);
    }
}
