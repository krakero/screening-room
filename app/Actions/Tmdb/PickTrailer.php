<?php

namespace App\Actions\Tmdb;

class PickTrailer
{
    /**
     * Picks the best trailer (falling back to a teaser) from a TMDB `videos.results` array:
     * official over unofficial, the preferred language over English over any other, newest first.
     *
     * @param  array<int, array<string, mixed>>  $videos
     * @return array{site: string, key: string, name: ?string}|null
     */
    public function handle(array $videos, ?string $preferredLanguage = null): ?array
    {
        $preferredLanguage ??= config('app.locale', 'en');

        $best = collect($videos)
            ->filter(fn (array $video): bool => in_array($video['site'] ?? null, ['YouTube', 'Vimeo'], true))
            ->filter(fn (array $video): bool => in_array($video['type'] ?? null, ['Trailer', 'Teaser'], true))
            ->sortBy(fn (array $video): string => sprintf(
                '%d-%d-%d-%019d',
                ($video['type'] ?? null) === 'Trailer' ? 0 : 1,
                ($video['official'] ?? false) ? 0 : 1,
                $this->languageRank($video['iso_639_1'] ?? null, $preferredLanguage),
                PHP_INT_MAX - max(strtotime((string) ($video['published_at'] ?? '')), 0),
            ))
            ->first();

        if ($best === null) {
            return null;
        }

        return [
            'site' => $best['site'],
            'key' => $best['key'],
            'name' => $best['name'] ?? null,
        ];
    }

    private function languageRank(?string $language, string $preferredLanguage): int
    {
        return match (true) {
            $language === $preferredLanguage => 0,
            $language === 'en' => 1,
            blank($language) => 2,
            default => 3,
        };
    }
}
