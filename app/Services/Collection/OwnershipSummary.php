<?php

namespace App\Services\Collection;

use App\Enums\CollectionFormat;
use Illuminate\Support\Collection;

readonly class OwnershipSummary
{
    public function __construct(
        public Collection $copies,
        public bool $inPlex,
    ) {}

    public function isOwned(): bool
    {
        return $this->copies->isNotEmpty() || $this->inPlex;
    }

    /**
     * @return array<CollectionFormat>
     */
    public function formats(): array
    {
        return $this->copies
            ->pluck('format')
            ->unique()
            ->values()
            ->all();
    }

    public function label(): string
    {
        $parts = [];

        if ($this->copies->isNotEmpty()) {
            $formatLabels = collect($this->formats())
                ->map(fn (CollectionFormat $format) => $format->label())
                ->join(', ');

            $parts[] = $formatLabels;
        }

        if ($this->inPlex) {
            $parts[] = 'Plex';
        }

        return implode(' · ', $parts);
    }
}
