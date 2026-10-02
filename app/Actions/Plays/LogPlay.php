<?php

namespace App\Actions\Plays;

use App\Enums\PlaySource;
use App\Enums\WatchedAt;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Title;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class LogPlay
{
    public function handle(
        Title|Episode $playable,
        WatchedAt $when = WatchedAt::Now,
        ?CarbonImmutable $customDatetime = null,
        PlaySource $source = PlaySource::Manual,
    ): Play {
        return $playable->plays()->create([
            'watched_at' => $this->resolveWatchedAt($playable, $when, $customDatetime),
            'source' => $source,
        ]);
    }

    private function resolveWatchedAt(Title|Episode $playable, WatchedAt $when, ?CarbonImmutable $customDatetime): ?CarbonImmutable
    {
        return match ($when) {
            WatchedAt::Now => CarbonImmutable::now(),
            WatchedAt::ReleaseDate => $this->releaseDate($playable) ?? CarbonImmutable::now(),
            WatchedAt::Unknown => null,
            WatchedAt::Custom => $customDatetime ?? throw new InvalidArgumentException('A custom datetime is required when logging a play with WatchedAt::Custom.'),
        };
    }

    private function releaseDate(Title|Episode $playable): ?CarbonImmutable
    {
        $date = $playable instanceof Episode ? $playable->air_date : $playable->release_date;

        return $date ? CarbonImmutable::parse($date) : null;
    }
}
