<?php

namespace App\Enums;

enum CalendarEntryType: string
{
    case Episode = 'episode';
    case MovieRelease = 'movie_release';
    case SeasonPremiere = 'season_premiere';
}
