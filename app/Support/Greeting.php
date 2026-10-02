<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The time-of-day greeting shown on the Up Next page, in the signed-in user's timezone.
 */
final class Greeting
{
    public static function current(): string
    {
        $hour = (int) Carbon::now(DisplayTimezone::current())->hour;

        return match (true) {
            $hour >= 5 && $hour < 12 => __('Good morning.'),
            $hour >= 12 && $hour < 17 => __('Good afternoon.'),
            default => __('Good evening.'),
        };
    }
}
