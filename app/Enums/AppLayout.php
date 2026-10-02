<?php

namespace App\Enums;

enum AppLayout: string
{
    case Header = 'header';
    case Sidebar = 'sidebar';

    public function component(): string
    {
        return match ($this) {
            self::Header => 'layouts::app.nav',
            self::Sidebar => 'layouts::app.sidebar',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Header => __('Top navigation'),
            self::Sidebar => __('Sidebar'),
        };
    }
}
