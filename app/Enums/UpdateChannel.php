<?php

namespace App\Enums;

enum UpdateChannel: string
{
    case Stable = 'stable';
    case Develop = 'develop';

    public function tag(): string
    {
        return match ($this) {
            self::Stable => 'latest',
            self::Develop => 'develop',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Stable => 'Stable',
            self::Develop => 'Develop',
        };
    }
}
