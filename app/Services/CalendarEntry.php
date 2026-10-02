<?php

namespace App\Services;

use App\Enums\CalendarEntryType;
use App\Models\Episode;
use App\Models\Title;
use Illuminate\Support\Carbon;

readonly class CalendarEntry
{
    public function __construct(
        public CalendarEntryType $type,
        public Carbon $date,
        public Title $title,
        public ?Episode $episode = null,
        public bool $watched = false,
    ) {}
}
