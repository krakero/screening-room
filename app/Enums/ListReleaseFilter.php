<?php

namespace App\Enums;

enum ListReleaseFilter: string
{
    case Released = 'released';
    case Upcoming = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::Released => __('Released'),
            self::Upcoming => __('Upcoming'),
        };
    }
}
