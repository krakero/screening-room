<?php

namespace App\Enums;

enum RatingSource: string
{
    case Imdb = 'imdb';
    case RottenTomatoesCritics = 'tomatoes';
    case RottenTomatoesAudience = 'tomatoesaudience';
    case Metacritic = 'metacritic';
    case Letterboxd = 'letterboxd';
    case Trakt = 'trakt';

    /**
     * The native scale MDBList reports this source's value on.
     */
    public function maxValue(): float
    {
        return match ($this) {
            self::Imdb => 10.0,
            self::Letterboxd => 5.0,
            default => 100.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Imdb => 'IMDb',
            self::RottenTomatoesCritics => 'RT',
            self::RottenTomatoesAudience => 'RT Audience',
            self::Metacritic => 'Metacritic',
            self::Letterboxd => 'Letterboxd',
            self::Trakt => 'Trakt',
        };
    }

    public function format(float $value): string
    {
        return match ($this) {
            self::Imdb, self::Letterboxd => number_format($value, 1),
            default => (string) (int) round($value),
        };
    }
}
