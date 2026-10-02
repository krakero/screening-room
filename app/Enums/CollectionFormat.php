<?php

namespace App\Enums;

enum CollectionFormat: string
{
    case Uhd4k = 'uhd_4k';
    case BluRay = 'bluray';
    case Dvd = 'dvd';
    case Digital = 'digital';

    public function label(): string
    {
        return match ($this) {
            self::Uhd4k => '4K UHD',
            self::BluRay => 'Blu-ray',
            self::Dvd => 'DVD',
            self::Digital => 'Digital',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Uhd4k => 'film',
            self::BluRay => 'circle-stack',
            self::Dvd => 'circle-stack',
            self::Digital => 'cloud',
        };
    }

    public function isPhysical(): bool
    {
        return match ($this) {
            self::Uhd4k, self::BluRay, self::Dvd => true,
            self::Digital => false,
        };
    }
}
