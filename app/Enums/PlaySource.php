<?php

namespace App\Enums;

enum PlaySource: string
{
    case Manual = 'manual';
    case Plex = 'plex';
    case Trakt = 'trakt';
}
