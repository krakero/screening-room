<?php

namespace App\Services\Backup;

use App\Enums\BackupType;
use App\Models\User;
use App\Services\Discover\DiscoverFeed;
use App\Services\Stats\StatsCacheVersion;
use App\Services\Watch\WatchCacheVersion;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use ZipArchive;

class BackupRestorer
{
    private const SCHEMA = 1;

    private const VALID_APP_NAMES = ['screening-room', 'private-showing'];

    private const INSERT_CHUNK_SIZE = 500;

    private const CONFIG_ENTRY = 'config/integration_settings.json';

    private const MACHINE_LOCAL_SETTING_PREFIX = 'backup.';

    public function __construct(
        private readonly BackupManager $backups,
        private readonly IntegrationSettings $settings,
        private readonly WatchCacheVersion $watchCacheVersion,
        private readonly StatsCacheVersion $statsCacheVersion,
        private readonly DiscoverFeed $discoverFeed,
    ) {}

    /**
     * Restore a backup zip over the current install, after taking a safety backup of the
     * current state. The password defaults to the saved `backup.password`.
     *
     * @throws BackupException
     */
    public function restore(string $zipPath, ?string $password = null): RestoreResult
    {
        $password = filled($password) ? $password : $this->settings->get('backup.password');

        if (blank($password)) {
            throw new BackupException('No backup password is set.');
        }

        $zip = $this->open($zipPath, (string) $password);

        try {
            $manifest = $this->readManifest($zip);
            $type = $this->validate($manifest);

            $config = $this->readConfig($zip);
            [$restorable, $skipped] = $type === BackupType::Full
                ? $this->partitionTables($manifest['tables'])
                : [[], []];

            foreach ($restorable as $table => $rowCount) {
                if ($rowCount > 0 && $zip->locateName("data/{$table}.jsonl") === false) {
                    throw new BackupException("The backup is missing the data for [{$table}].");
                }
            }

            // Skip safety backup on fresh/wiped install (no users = nothing to protect)
            $safetyBackup = User::query()->doesntExist()
                ? null
                : $this->backups->create(BackupType::Full)->filename;

            $restored = $this->apply($zip, $restorable, $config);
        } finally {
            $zip->close();
        }

        $this->refreshCaches();

        return new RestoreResult(
            type: $type,
            tables: $restored,
            configKeys: count($this->restorableSettings($config)),
            safetyBackup: $safetyBackup,
            skippedTables: $skipped,
        );
    }

    /**
     * @throws BackupException
     */
    private function open(string $zipPath, string $password): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new BackupException('Wrong password or not a valid backup.');
        }

        $zip->setPassword($password);

        return $zip;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BackupException
     */
    private function readManifest(ZipArchive $zip): array
    {
        $manifest = $this->decode($zip->getFromName('manifest.json'));

        if ($manifest === null) {
            throw new BackupException('Wrong password or not a valid backup.');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws BackupException
     */
    private function validate(array $manifest): BackupType
    {
        $type = BackupType::tryFrom((string) ($manifest['type'] ?? ''));
        $appName = $manifest['app'] ?? null;

        if (! in_array($appName, self::VALID_APP_NAMES, true) || ($manifest['schema'] ?? null) !== self::SCHEMA || $type === null || ! is_array($manifest['tables'] ?? null)) {
            throw new BackupException('This is not a Screening Room backup this version can restore.');
        }

        if (! $this->migrationIsKnown((string) ($manifest['last_migration'] ?? ''))) {
            throw new BackupException('This backup was made by a newer version of the app. Update the app before restoring it.');
        }

        return $type;
    }

    private function migrationIsKnown(string $migration): bool
    {
        if ($migration === '') {
            return false;
        }

        return DB::table('migrations')->where('migration', $migration)->exists()
            || is_file(database_path("migrations/{$migration}.php"));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BackupException
     */
    private function readConfig(ZipArchive $zip): array
    {
        $config = $this->decode($zip->getFromName(self::CONFIG_ENTRY));

        if ($config === null) {
            throw new BackupException('The backup is missing its settings.');
        }

        return $config;
    }

    /**
     * Split the manifest's tables into those this restore will replace and those it skips
     * (excluded from backups, or no longer present in this install's schema).
     *
     * @param  array<string, mixed>  $tables
     * @return array{0: array<string, int>, 1: array<int, string>}
     */
    private function partitionTables(array $tables): array
    {
        $restorable = [];
        $skipped = [];

        foreach ($tables as $table => $rowCount) {
            $table = (string) $table;

            if (in_array($table, BackupManager::EXCLUDED_TABLES, true) || ! Schema::hasTable($table)) {
                $skipped[] = $table;

                continue;
            }

            $restorable[$table] = (int) $rowCount;
        }

        return [$restorable, $skipped];
    }

    /**
     * Replace the tables and settings in one transaction. Foreign keys are switched off outside
     * it (sqlite ignores the pragma inside a transaction) and always switched back on.
     *
     * @param  array<string, int>  $tables
     * @param  array<string, mixed>  $config
     * @return array<string, int>
     */
    private function apply(ZipArchive $zip, array $tables, array $config): array
    {
        $restored = [];

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($zip, $tables, $config, &$restored): void {
                foreach (array_keys($tables) as $table) {
                    $restored[$table] = $this->replaceTable($zip, $table);
                }

                foreach ($this->restorableSettings($config) as $key => $value) {
                    $this->settings->set($key, $value);
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $restored;
    }

    private function replaceTable(ZipArchive $zip, string $table): int
    {
        DB::table($table)->delete();

        $columns = array_flip(Schema::getColumnListing($table));
        $stream = $zip->getStream("data/{$table}.jsonl");
        $chunk = [];
        $count = 0;

        if ($stream === false) {
            return 0;
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $chunk[] = array_intersect_key($this->decodeRow($line), $columns);
                $count++;

                if (count($chunk) >= self::INSERT_CHUNK_SIZE) {
                    DB::table($table)->insert($chunk);
                    $chunk = [];
                }
            }
        } finally {
            fclose($stream);
        }

        if ($chunk !== []) {
            DB::table($table)->insert($chunk);
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BackupException
     */
    private function decodeRow(string $line): array
    {
        $row = $this->decode($line);

        if ($row === null) {
            throw new BackupException('The backup contains a corrupt data row.');
        }

        return $row;
    }

    /**
     * Settings from the backup that may be written here: the machine-local `backup.*` ones never are.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function restorableSettings(array $config): array
    {
        return array_filter(
            $config,
            fn (string $key): bool => ! str_starts_with($key, self::MACHINE_LOCAL_SETTING_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function refreshCaches(): void
    {
        $this->watchCacheVersion->bump();
        $this->statsCacheVersion->bump();
        $this->discoverFeed->forgetCached();
        Cache::forget('qbittorrent:sid');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string|false $json): ?array
    {
        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
