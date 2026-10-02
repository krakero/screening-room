<?php

namespace App\Support;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Everything is stored in UTC. This is the single place that resolves the
 * timezone dates and times are shown in, and that "today"/"this year" etc.
 * mean for the signed-in user, so display and day-bucketing logic never
 * hardcodes a timezone or reaches for the server clock directly.
 */
final class DisplayTimezone
{
    public const string DEFAULT = 'America/New_York';

    private const string FALLBACK_BINDING = 'display-timezone.fallback';

    /**
     * The signed-in user's timezone when there is a request/session, otherwise (console
     * commands, jobs) this app's single user's timezone, otherwise the configured default.
     * The fallback lookup is memoized per request/test via the container, since this is
     * called once per row when bucketing plays by local day/hour.
     */
    public static function current(): string
    {
        if ($user = Auth::user()) {
            return $user->timezone ?? config('app.display_timezone', self::DEFAULT);
        }

        if (! app()->bound(self::FALLBACK_BINDING)) {
            $fallback = User::query()->first()?->timezone ?? config('app.display_timezone', self::DEFAULT);

            app()->instance(self::FALLBACK_BINDING, $fallback);
        }

        return app()->make(self::FALLBACK_BINDING);
    }

    /**
     * The user's local calendar date, at local midnight.
     */
    public static function today(): Carbon
    {
        return Carbon::now(self::current())->startOfDay();
    }

    /**
     * Convert a stored (UTC) timestamp to the user's local timezone for display.
     */
    public static function local(DateTimeInterface|string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(self::current());
    }

    /**
     * Interpret a wall-clock string (e.g. from a `datetime-local` input) as the
     * user's local time, and return it converted to UTC for storage.
     */
    public static function parseLocalToUtc(string $value): Carbon
    {
        return Carbon::parse($value, self::current())->utc();
    }

    /**
     * PHP timezone identifiers grouped by region, for a select input.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $groups = [];

        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            [$region, $rest] = array_pad(explode('/', $identifier, 2), 2, null);

            $groups[$region][$identifier] = $rest !== null ? str_replace('_', ' ', $rest) : $identifier;
        }

        ksort($groups);

        foreach ($groups as &$options) {
            asort($options);
        }

        return $groups;
    }

    /**
     * The UTC [start, end) bounds for a whole calendar year in the user's timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function yearRangeUtc(int $year): array
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, self::current());

        return [$start->clone()->utc(), $start->clone()->addYear()->utc()];
    }

    /**
     * A raw SQL expression that shifts a UTC datetime column/expression into this app's display
     * timezone, for grouping/bucketing in the database instead of pulling every row into PHP.
     *
     * MySQL's `CONVERT_TZ(dt, 'UTC', 'Area/City')` would be the obvious tool for this, but it
     * silently returns NULL unless the server's `mysql.time_zone_name` tables are loaded — not
     * something this app can assume. Instead this builds a `CASE` over the timezone's own DST
     * transition points (from PHP's `DateTimeZone`, which needs no database setup) and adds the
     * fixed UTC offset that was in effect for each range. For a single-user tracker's realistic
     * date span (a handful of years) that's a handful of `WHEN` branches, and it's exact —
     * including across DST transitions — unlike a single fixed offset applied to every row.
     */
    public static function sqlLocal(string $column): string
    {
        $zone = new \DateTimeZone(self::current());
        $from = Carbon::create(2000, 1, 1, 0, 0, 0, 'UTC');
        $to = Carbon::now('UTC')->addYear();

        $transitions = $zone->getTransitions($from->timestamp, $to->timestamp) ?: [];

        $branches = [];

        foreach ($transitions as $i => $transition) {
            $offsetMinutes = intdiv((int) $transition['offset'], 60);
            $nextTimestamp = $transitions[$i + 1]['ts'] ?? null;

            if ($nextTimestamp === null) {
                $branches[] = "DATE_ADD({$column}, INTERVAL {$offsetMinutes} MINUTE)";

                break;
            }

            $boundary = Carbon::createFromTimestamp($nextTimestamp, 'UTC')->format('Y-m-d H:i:s');
            $branches[] = "WHEN {$column} < '{$boundary}' THEN DATE_ADD({$column}, INTERVAL {$offsetMinutes} MINUTE)";
        }

        if ($branches === []) {
            return $column;
        }

        if (count($branches) === 1) {
            return $branches[0];
        }

        $whens = implode(' ', array_slice($branches, 0, -1));
        $else = $branches[array_key_last($branches)];

        return "CASE {$whens} ELSE {$else} END";
    }
}
