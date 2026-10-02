<?php

namespace App\Services\Backup;

use App\Enums\BackupType;
use App\Models\IntegrationSetting;
use App\Support\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * Creates, lists and deletes password-protected (AES-256) backup zips of the app's data and settings.
 */
class BackupManager
{
    public const SCHEMA_VERSION = 1;

    /** Tables never exported: transient, or covered by the config/ entry. */
    public const EXCLUDED_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'integration_settings',
    ];

    /** Machine-local settings that are never backed up or restored. */
    public const LOCAL_SETTING_KEYS = ['backup.password', 'backup.path'];

    private const FILENAME_PATTERN = '/^(private-showing|screening-room)-(full|config)-(\d{4}-\d{2}-\d{2}_\d{6})\.zip$/';

    private const CHUNK_SIZE = 500;

    public function __construct(private IntegrationSettings $settings) {}

    public function create(BackupType $type = BackupType::Full): BackupFile
    {
        $password = (string) $this->settings->get('backup.password');

        if (strlen($password) < 8) {
            throw BackupException::passwordMissing();
        }

        $directory = $this->directory();

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw BackupException::folderNotWritable($directory);
        }

        // Filenames have one-second resolution; step forward past any existing file so a backup
        // made in the same second (e.g. restore's safety backup of a just-made backup) never
        // overwrites another.
        $now = CarbonImmutable::now('UTC');

        do {
            $filename = "screening-room-{$type->value}-{$now->format('Y-m-d_His')}.zip";
            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            $now = $now->addSecond();
        } while (file_exists($path));

        $tables = $type === BackupType::Full ? $this->exportableTables() : [];
        $dataFiles = [];

        try {
            $counts = [];

            foreach ($tables as $table) {
                [$dataFiles[$table], $counts[$table]] = $this->exportTable($table);
            }

            $this->writeZip($path, $password, [
                'manifest.json' => $this->encode([
                    'schema' => self::SCHEMA_VERSION,
                    'type' => $type->value,
                    'app' => 'screening-room',
                    'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
                    'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
                    'tables' => (object) $counts,
                ]),
                'config/integration_settings.json' => $this->encode((object) $this->configValues()),
            ], $dataFiles);
        } finally {
            foreach ($dataFiles as $file) {
                @unlink($file);
            }
        }

        return $this->toBackupFile($path);
    }

    /**
     * @return Collection<int, BackupFile>
     */
    public function all(): Collection
    {
        $directory = $this->directory().DIRECTORY_SEPARATOR;
        $paths = array_merge(
            glob($directory.'private-showing-*.zip') ?: [],
            glob($directory.'screening-room-*.zip') ?: []
        );

        return collect($paths)
            ->filter(fn (string $path): bool => is_file($path) && preg_match(self::FILENAME_PATTERN, basename($path)) === 1)
            ->map(fn (string $path): BackupFile => $this->toBackupFile($path))
            ->sortByDesc(fn (BackupFile $file): int => $file->createdAt->getTimestamp())
            ->values();
    }

    public function path(string $filename): string
    {
        if ($filename !== basename($filename) || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw BackupException::invalidFilename($filename);
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            throw BackupException::notFound($filename);
        }

        return $path;
    }

    public function delete(string $filename): void
    {
        unlink($this->path($filename));
    }

    public function directory(): string
    {
        $configured = $this->settings->get('backup.path');

        return rtrim(filled($configured) ? (string) $configured : storage_path('app/backups'), '/\\');
    }

    /**
     * @return list<string>
     */
    private function exportableTables(): array
    {
        $schema = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? DB::getDatabaseName() : null;

        return collect(Schema::getTableListing($schema, schemaQualified: false))
            ->reject(fn (string $table): bool => in_array($table, self::EXCLUDED_TABLES, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Streams a table to a temp JSONL file so large tables never sit in memory.
     *
     * @return array{0: string, 1: int} The temp file path and the row count.
     */
    private function exportTable(string $table): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sr-backup-');
        $handle = fopen($file, 'w');
        $count = 0;

        $column = Schema::hasColumn($table, 'id') ? 'id' : Schema::getColumnListing($table)[0];

        DB::table($table)->orderBy($column)->chunk(self::CHUNK_SIZE, function (Collection $rows) use ($handle, &$count): void {
            foreach ($rows as $row) {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                $count++;
            }
        });

        fclose($handle);

        return [$file, $count];
    }

    /**
     * @return array<string, mixed>
     */
    private function configValues(): array
    {
        return IntegrationSetting::all()
            ->reject(fn (IntegrationSetting $setting): bool => in_array($setting->key, self::LOCAL_SETTING_KEYS, true))
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * @param  array<string, string>  $contents  Entry name => contents.
     * @param  array<string, string>  $dataFiles  Table => temp file path.
     */
    private function writeZip(string $path, string $password, array $contents, array $dataFiles): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw BackupException::writeFailed('the zip file could not be opened.');
        }

        $names = array_keys($contents);

        foreach ($contents as $name => $body) {
            $zip->addFromString($name, $body);
        }

        foreach ($dataFiles as $table => $file) {
            $name = "data/{$table}.jsonl";
            $zip->addFile($file, $name);
            $names[] = $name;
        }

        foreach ($names as $name) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        }

        if (! $zip->close()) {
            @unlink($path);

            throw BackupException::writeFailed('the zip file could not be finalised.');
        }
    }

    private function toBackupFile(string $path): BackupFile
    {
        preg_match(self::FILENAME_PATTERN, basename($path), $matches);

        return new BackupFile(
            filename: basename($path),
            type: BackupType::from($matches[2]),
            size: (int) filesize($path),
            createdAt: CarbonImmutable::createFromFormat('Y-m-d_His', $matches[3], 'UTC'),
        );
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
