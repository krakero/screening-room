<?php

namespace App\Livewire\Concerns;

use App\Support\DisplayTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * The "Pick date & time…" flow shared by every page that lets a user log a custom watched-at.
 * The picker itself opens client-side (see App\Support\CustomWatchedAtTrigger) with "now" seeded
 * from the user's timezone in the browser, so this only covers converting the submitted wall-clock
 * value back to UTC for storage.
 */
trait HasCustomWatchedAt
{
    protected function resolveCustomDatetimeUtc(string $customDatetime): CarbonImmutable
    {
        Validator::make(
            ['customDatetime' => $customDatetime],
            ['customDatetime' => ['required', 'date']],
        )->validate();

        return CarbonImmutable::instance(DisplayTimezone::parseLocalToUtc($customDatetime));
    }
}
