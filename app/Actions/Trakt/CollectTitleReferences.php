<?php

namespace App\Actions\Trakt;

use App\Actions\Trakt\DataTransferObjects\SkippedReference;
use App\Actions\Trakt\DataTransferObjects\TitleReference;
use App\Enums\TitleType;
use App\Services\Trakt\ExportReader;

/**
 * Scans the requested sections of an export and returns the unique set of
 * TMDB movie/show ids referenced, plus the entries that could not be
 * resolved (no TMDB id on the Trakt entry).
 */
class CollectTitleReferences
{
    /**
     * @param  array<int, string>  $only  Subset of 'history', 'ratings', 'watchlist', 'lists'.
     * @return array{references: array<int, TitleReference>, skipped: array<int, SkippedReference>}
     */
    public function handle(ExportReader $reader, array $only): array
    {
        /** @var array<string, TitleReference> $references */
        $references = [];
        /** @var array<int, SkippedReference> $skipped */
        $skipped = [];

        $record = function (?TitleType $type, ?int $tmdbId, string $context) use (&$references, &$skipped): void {
            if ($type === null) {
                return;
            }

            if ($tmdbId === null) {
                $skipped[] = new SkippedReference($context, 'missing tmdb id');

                return;
            }

            $reference = new TitleReference($type, $tmdbId);
            $references[$reference->key()] = $reference;
        };

        if (in_array('history', $only, true)) {
            foreach ($reader->history() as $entry) {
                match ($entry['type'] ?? null) {
                    'movie' => $record(TitleType::Movie, $entry['movie']['ids']['tmdb'] ?? null, 'history movie'),
                    'episode' => $record(TitleType::Show, $entry['show']['ids']['tmdb'] ?? null, 'history episode'),
                    default => null,
                };
            }
        }

        if (in_array('ratings', $only, true)) {
            foreach ($reader->ratedTitles() as $entry) {
                $type = $entry['type'] ?? null;

                if (in_array($type, ['movie', 'show'], true)) {
                    $record(
                        $type === 'movie' ? TitleType::Movie : TitleType::Show,
                        $entry[$type]['ids']['tmdb'] ?? null,
                        "rating {$type}",
                    );
                }
            }

            foreach ($reader->ratedEpisodes() as $entry) {
                $record(TitleType::Show, $entry['show']['ids']['tmdb'] ?? null, 'rating episode');
            }
        }

        if (in_array('watchlist', $only, true)) {
            foreach ($reader->watchlist() as $entry) {
                $type = $entry['type'] ?? null;

                if (in_array($type, ['movie', 'show'], true)) {
                    $record(
                        $type === 'movie' ? TitleType::Movie : TitleType::Show,
                        $entry[$type]['ids']['tmdb'] ?? null,
                        'watchlist',
                    );
                }
            }
        }

        if (in_array('lists', $only, true)) {
            foreach ($reader->customLists() as $list) {
                foreach ($list['items'] as $entry) {
                    $type = $entry['type'] ?? null;

                    if (in_array($type, ['movie', 'show'], true)) {
                        $record(
                            $type === 'movie' ? TitleType::Movie : TitleType::Show,
                            $entry[$type]['ids']['tmdb'] ?? null,
                            "list \"{$list['name']}\"",
                        );
                    }
                }
            }
        }

        return [
            'references' => array_values($references),
            'skipped' => $skipped,
        ];
    }
}
