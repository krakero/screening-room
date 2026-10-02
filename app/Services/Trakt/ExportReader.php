<?php

namespace App\Services\Trakt;

use ZipArchive;

/**
 * Reads a Trakt personal data export (either a `.zip` file or an already
 * extracted directory) and yields the raw decoded JSON structures.
 */
class ExportReader
{
    private readonly string $root;

    private ?string $extractedTo = null;

    public function __construct(string $path)
    {
        if (! file_exists($path)) {
            throw TraktExportException::pathNotFound($path);
        }

        $this->root = is_dir($path)
            ? $this->locateRoot($path)
            : $this->locateRoot($this->extractZip($path));
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Extracts a zip to a caller-managed destination directory (e.g. a
     * persistent working directory for a batched import) instead of a
     * temporary one owned by this reader. The caller is responsible for
     * deleting `$destination` when it's done with it.
     */
    public static function extractPersistent(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw TraktExportException::invalidZip($zipPath);
        }

        if (! is_dir($destination)) {
            mkdir($destination, recursive: true);
        }

        $zip->extractTo($destination);
        $zip->close();
    }

    /**
     * Resolved, naturally-ordered watch history files (one per export page),
     * for splitting play imports into short per-file jobs.
     *
     * @return array<int, string>
     */
    public function historyFiles(): array
    {
        return $this->resolveFiles('watched/history', 'watched-history');
    }

    /**
     * Decodes a single export JSON file by its resolved path (as returned by
     * `historyFiles()`), for use outside this reader's own iteration methods.
     *
     * @return array<int, array<string, mixed>>
     */
    public function decode(string $file): array
    {
        return $this->decodeFile($file);
    }

    /**
     * Removes the temporary extraction directory, if one was created. Safe to
     * call more than once; callers should invoke this from a `finally` block
     * rather than relying solely on the destructor.
     */
    public function cleanup(): void
    {
        if ($this->extractedTo !== null) {
            $this->deleteDirectory($this->extractedTo);
            $this->extractedTo = null;
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function history(): iterable
    {
        foreach ($this->decodeMerged('watched/history', 'watched-history') as $entry) {
            yield $entry;
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function ratedTitles(): iterable
    {
        foreach ([['ratings/ratings-movies', 'ratings-movies'], ['ratings/ratings-shows', 'ratings-shows']] as [$old, $flat]) {
            foreach ($this->decodeMerged($old, $flat) as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function ratedEpisodes(): iterable
    {
        return $this->decodeMerged('ratings/ratings-episodes', 'ratings-episodes');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function watchlist(): array
    {
        return iterator_to_array($this->decodeMerged('lists/watchlist', 'lists-watchlist'), preserve_keys: false);
    }

    /**
     * @return iterable<int, array{trakt_id: int, slug: string, name: string, description: ?string, items: array<int, array<string, mixed>>}>
     */
    public function customLists(): iterable
    {
        $lists = iterator_to_array($this->decodeMerged('lists/lists', 'lists-lists'), preserve_keys: false);

        foreach ($lists as $list) {
            $traktId = $list['ids']['trakt'] ?? null;
            $slug = $list['ids']['slug'] ?? null;

            if ($traktId === null || $slug === null) {
                continue;
            }

            $files = $this->resolveFiles("lists/list-{$traktId}-{$slug}", "lists-list-{$traktId}-{$slug}");

            if ($files === []) {
                continue;
            }

            yield [
                'trakt_id' => $traktId,
                'slug' => $slug,
                'name' => $list['name'],
                'description' => $list['description'] ?? null,
                'items' => iterator_to_array($this->decodeFiles($files), preserve_keys: false),
            ];
        }
    }

    private function locateRoot(string $directory): string
    {
        return $this->findExportRoot($directory, 0) ?? throw TraktExportException::rootNotFound($directory, $this->describeContents($directory));
    }

    /**
     * Recursively searches for the export root, skipping `__MACOSX` and
     * dot-prefixed entries (e.g. `.DS_Store`) added by macOS zip tools. The
     * user's real exports nest everything under a top-level username folder,
     * so this can't stop at a single level.
     */
    private function findExportRoot(string $directory, int $depth): ?string
    {
        if ($this->looksLikeExportRoot($directory)) {
            return $directory;
        }

        if ($depth >= 4) {
            return null;
        }

        foreach (glob($directory.'/*', GLOB_ONLYDIR) ?: [] as $subdirectory) {
            $name = basename($subdirectory);

            if ($name === '__MACOSX' || str_starts_with($name, '.')) {
                continue;
            }

            if ($found = $this->findExportRoot($subdirectory, $depth + 1)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Matches either the old layout (`watched/`, `lists/`, or `ratings/`
     * folders) or the new flat, prefixed layout Trakt switched to (files
     * like `watched-history-1.json` or `lists-watchlist.json` sitting
     * directly in the directory, optionally paginated with a `-N` suffix).
     */
    private function looksLikeExportRoot(string $directory): bool
    {
        if (is_dir($directory.'/watched') || is_dir($directory.'/lists') || is_dir($directory.'/ratings')) {
            return true;
        }

        foreach (['watched-history', 'lists-', 'ratings-'] as $prefix) {
            if (glob($directory.'/'.$prefix.'*.json') !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lists the top-level entries of a directory for use in an error message
     * when no export root could be found under it.
     *
     * @return array<int, string>
     */
    private function describeContents(string $directory): array
    {
        $entries = array_diff(scandir($directory) ?: [], ['.', '..']);

        sort($entries);

        return array_values($entries);
    }

    /**
     * Resolves a logical export file to the files on disk under either the
     * old (`$old`, e.g. `watched/history`) or new flat (`$flat`, e.g.
     * `watched-history`) layout, merging paginated `-N` pages (and an
     * unpaginated single file, if present) in natural numeric order.
     *
     * @return array<int, string>
     */
    private function resolveFiles(string $old, string $flat): array
    {
        $files = [];

        foreach ([$old, $flat] as $name) {
            foreach (glob($this->root.'/'.$name.'.json') ?: [] as $file) {
                $files[$file] = true;
            }

            foreach (glob($this->root.'/'.$name.'-*.json') ?: [] as $file) {
                $files[$file] = true;
            }
        }

        $files = array_keys($files);
        natsort($files);

        return array_values($files);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function decodeMerged(string $old, string $flat): iterable
    {
        return $this->decodeFiles($this->resolveFiles($old, $flat));
    }

    /**
     * @param  array<int, string>  $files
     * @return iterable<int, array<string, mixed>>
     */
    private function decodeFiles(array $files): iterable
    {
        foreach ($files as $file) {
            foreach ($this->decodeFile($file) as $entry) {
                yield $entry;
            }
        }
    }

    private function extractZip(string $zipPath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw TraktExportException::invalidZip($zipPath);
        }

        $destination = sys_get_temp_dir().'/trakt-import-'.uniqid();
        mkdir($destination, recursive: true);

        $zip->extractTo($destination);
        $zip->close();

        return $this->extractedTo = $destination;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeFile(string $path): array
    {
        return self::decodeJsonFile($path);
    }

    /**
     * Decodes a single export JSON file by its full path, without needing a
     * reader instance (e.g. for a chunked job that only knows one file).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function decodeJsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), associative: true) ?? [];
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
