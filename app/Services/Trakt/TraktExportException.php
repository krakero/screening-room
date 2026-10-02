<?php

namespace App\Services\Trakt;

use RuntimeException;

class TraktExportException extends RuntimeException
{
    public static function pathNotFound(string $path): self
    {
        return new self("Trakt export path [{$path}] does not exist.");
    }

    public static function invalidZip(string $path): self
    {
        return new self("Trakt export zip [{$path}] could not be opened.");
    }

    /**
     * @param  array<int, string>  $found
     */
    public static function rootNotFound(string $path, array $found = []): self
    {
        $found = $found === []
            ? 'nothing'
            : implode(', ', $found);

        return new self("Could not locate a Trakt export (expected a watched/, lists/, or ratings/ directory, or flat files like watched-history-1.json / lists-watchlist.json / ratings-movies.json) under [{$path}]. Found: {$found}.");
    }
}
