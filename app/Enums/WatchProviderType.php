<?php

namespace App\Enums;

enum WatchProviderType: string
{
    case Flatrate = 'flatrate';
    case Free = 'free';
    case Ads = 'ads';

    public function label(): string
    {
        return match ($this) {
            self::Flatrate => 'Stream',
            self::Free => 'Free',
            self::Ads => 'Free with ads',
        };
    }
}
