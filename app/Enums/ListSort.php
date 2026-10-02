<?php

namespace App\Enums;

enum ListSort: string
{
    case Manual = 'manual';
    case RecentlyReleased = 'recently-released';
    case OldestRelease = 'oldest-release';
    case UpcomingFirst = 'upcoming-first';
    case RecentlyAdded = 'recently-added';
    case Title = 'title';
    case MyRating = 'my-rating';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual order'),
            self::RecentlyReleased => __('Recently released'),
            self::OldestRelease => __('Oldest release'),
            self::UpcomingFirst => __('Upcoming first'),
            self::RecentlyAdded => __('Recently added'),
            self::Title => __('Title A–Z'),
            self::MyRating => __('My rating'),
        };
    }
}
