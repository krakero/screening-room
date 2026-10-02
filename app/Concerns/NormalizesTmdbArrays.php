<?php

namespace App\Concerns;

trait NormalizesTmdbArrays
{
    /**
     * Narrow a decoded TMDB response value to a list of associative arrays,
     * dropping anything that isn't itself an array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function arrayOfArrays(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
