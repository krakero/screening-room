<?php

namespace App\Enums;

enum ShowStatus: string
{
    case Ongoing = 'ongoing';
    case Upcoming = 'upcoming';
    case Ended = 'ended';
    case Canceled = 'canceled';

    /**
     * The single source of truth for mapping TMDB's `status` string to a
     * user-facing show status. Returns null for null/unrecognized values,
     * meaning "no chip".
     */
    public static function fromTmdbStatus(?string $status): ?self
    {
        if ($status === null) {
            return null;
        }

        foreach (self::cases() as $case) {
            if (in_array($status, $case->tmdbStatuses(), true)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function tmdbStatuses(): array
    {
        return match ($this) {
            self::Ongoing => ['Returning Series'],
            self::Upcoming => ['In Production', 'Planned', 'Pilot'],
            self::Ended => ['Ended'],
            self::Canceled => ['Canceled'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ongoing => __('Ongoing'),
            self::Upcoming => __('Upcoming'),
            self::Ended => __('Ended'),
            self::Canceled => __('Canceled'),
        };
    }

    /**
     * The `<x-media.chip tone="...">` tone this status renders with.
     */
    public function chipTone(): string
    {
        return match ($this) {
            self::Ongoing => 'available',
            self::Upcoming => 'pending',
            self::Ended => 'muted',
            self::Canceled => 'danger',
        };
    }
}
