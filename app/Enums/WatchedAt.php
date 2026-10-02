<?php

namespace App\Enums;

enum WatchedAt: string
{
    case Now = 'now';
    case ReleaseDate = 'release_date';
    case Unknown = 'unknown';
    case Custom = 'custom';
}
