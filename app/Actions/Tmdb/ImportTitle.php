<?php

namespace App\Actions\Tmdb;

use App\Enums\TitleType;
use App\Models\Title;

class ImportTitle
{
    public function __construct(
        private readonly ImportMovie $importMovie,
        private readonly ImportShow $importShow,
    ) {}

    public function handle(TitleType $type, int $tmdbId): Title
    {
        return match ($type) {
            TitleType::Movie => $this->importMovie->handle($tmdbId),
            TitleType::Show => $this->importShow->handle($tmdbId),
        };
    }
}
