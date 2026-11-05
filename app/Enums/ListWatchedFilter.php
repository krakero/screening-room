<?php

namespace App\Enums;

enum ListWatchedFilter: string
{
    case Unwatched = 'unwatched';
    case InProgress = 'in-progress';
    case Watched = 'watched';
    case NotFinished = 'not-finished';

    public function label(): string
    {
        return match ($this) {
            self::Unwatched => __('Unwatched'),
            self::InProgress => __('In progress'),
            self::Watched => __('Watched'),
            self::NotFinished => __('Not finished'),
        };
    }
}
