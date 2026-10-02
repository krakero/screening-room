<?php

namespace App\Enums;

enum BackupType: string
{
    case Full = 'full';
    case Config = 'config';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Config => 'Config only',
        };
    }
}
