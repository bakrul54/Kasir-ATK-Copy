<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class RestoreStoreBackup
{
    private const FORMAT = 'spot-print-backup';

    private const LEGACY_VERSION = 1;

    private const VERSION = 2;

    private const UPLOAD_DIRECTORIES = [
        'products',
        'purchase-receipts',
        'store',
    ];

    private const UPLOAD_MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const MAX_BACKUP_BYTES = 50 * 1024 * 1024;

    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public function handle(UploadedFile $backupFile): void
    {
        [$database, $uploads] = $this->readBackup($backupFile);
        $tables = CreateStoreBackup::dataTables();
        $orderedTables = $this->orderTablesByForeignKeys($tables);
        $previousUploads = $this->currentUploads();
        $uploadsMayHaveChanged = false;

        try {
            DB::transaction(function () use ($database, $uploads, $orderedTables, &$uploadsMayHaveChanged): void {
                foreach (array_reverse($orderedTables) as $table) {
                    DB::table($table)->delete();
                }

                foreach ($orderedTables as $table) {
                    foreach (array_chunk($database[$table], 500) as $rows) {
                        DB::table($table)->insert($rows);
                    }
                }

                $uploadsMayHaveChanged = true;
                $this->replaceUploads($uploads);
            });
        } catch (Throwable $exception) {
            if (! $uploadsMayHaveChanged) {
                throw $exception;
            }

            try {
                $this->replaceUploads($previousUploads);
            } catch (Throwable $rollbackException) {
                report($exception);

                throw new RuntimeException(
                    'Restore gagal dan file unggahan sebelumnya tidak dapat dipulihkan. Hubungi administrator.',
                    0,
                    $rollbackException,
                );
            }

            throw $exception;
        }
    }

    /**
     * @return array{array<string, list<array<string, mixed>>>, array<string, string>}
     */
    private function readBackup(UploadedFile $backupFile): array
    {
        $archive = new ZipArchive;

        if ($archive->open($backupFile->getPathname()) !== true) {
            $this->rejectBackup('File backup bukan arsip ZIP yang valid.');
        }

        try {
            $totalSize = 0;
            $databaseJson = null;
            $uploads = [];
            $seenEntries = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if (! is_array($entry) || ! isset($entry['name'], $entry['size'])) {
                    $this->rejectBackup('Isi arsip backup tidak dapat dibaca.');
                }

                $entryName = $entry['name'];
                $totalSize += $entry['size'];

                if ($totalSize > self::MAX_UNCOMPRESSED_BYTES) {
                    $this->rejectBackup('Ukuran isi backup melebihi batas 100 MB.');
                }

                if (isset($seenEntries[$entryName])) {
                    $this->rejectBackup('Arsip backup berisi nama file duplikat.');
                }

                $seenEntries[$entryName] = true;

                if ($entryName === 'database.json') {
                    if ($entry['size'] > self::MAX_BACKUP_BYTES) {
                        $this->rejectBackup('Data database di dalam backup melebihi batas 50 MB.');
                    }

                    $databaseJson = $archive->getFromIndex($index);

                    if (! is_string($databaseJson)) {
                        $this->rejectBackup('Data database di dalam backup tidak dapat dibaca.');
                    }

                    continue;
                }

                $uploadPath = $this->uploadPathFromEntry($entryName);
                $contents = $archive->getFromIndex($index);

                if (! is_string($contents)) {
                    $this->rejectBackup("File unggahan {$entryName} di dalam backup tidak dapat dibaca.");
                }

                $this->validateUploadContents($uploadPath, $contents);
                $uploads[$uploadPath] = $contents;
            }

            if (! is_string($databaseJson)) {
                $this->rejectBackup('Backup tidak berisi data database.');
            }

            return [$this->decodeDatabase($databaseJson), $uploads];
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function decodeDatabase(string $databaseJson): array
    {
        try {
            $payload = json_decode($databaseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->rejectBackup('Data database di dalam backup bukan JSON yang valid.');
        }

        if (
            ! is_array($payload)
            || ($payload['format'] ?? null) !== self::FORMAT
            || ! in_array($payload['version'] ?? null, [self::LEGACY_VERSION, self::VERSION], true)
            || ! is_array($payload['database'] ?? null)
        ) {
            $this->rejectBackup('Format backup tidak didukung.');
        }

        $database = $payload['database'];
        if ($payload['version'] === self::LEGACY_VERSION) {
            $legacyTables = CreateStoreBackup::legacyDataTables();
            $backupTables = array_keys($database);
            sort($legacyTables);
            sort($backupTables);

            if ($legacyTables !== $backupTables || ! is_array($database['products'] ?? null) || ! array_is_list($database['products'])) {
                $this->rejectBackup('Skema database backup tidak cocok dengan versi aplikasi saat ini.');
            }

            foreach ($database['products'] as &$product) {
                if (! is_array($product) || array_is_list($product)) {
                    $this->rejectBackup('Data baris pada tabel products memiliki format yang tidak valid.');
                }

                $product['wholesale_group_id'] = null;
            }
            unset($product);

            $database['product_wholesale_groups'] = [];
        }

        $tables = CreateStoreBackup::dataTables();
        $backupTables = array_keys($database);
        sort($backupTables);

        if ($tables !== $backupTables) {
            $this->rejectBackup('Skema database backup tidak cocok dengan versi aplikasi saat ini.');
        }

        foreach ($tables as $table) {
            $rows = $database[$table];

            if (! is_array($rows) || ! array_is_list($rows)) {
                $this->rejectBackup("Data tabel {$table} memiliki format yang tidak valid.");
            }

            $columns = Schema::getColumnListing($table);
            sort($columns);

            foreach ($rows as $row) {
                if (! is_array($row) || array_is_list($row)) {
                    $this->rejectBackup("Data baris pada tabel {$table} memiliki format yang tidak valid.");
                }

                $rowColumns = array_keys($row);
                sort($rowColumns);

                if ($columns !== $rowColumns) {
                    $this->rejectBackup("Struktur kolom tabel {$table} pada backup tidak cocok.");
                }
            }
        }

        return $database;
    }

    private function uploadPathFromEntry(string $entryName): string
    {
        if (! str_starts_with($entryName, 'uploads/')) {
            $this->rejectBackup('Arsip backup berisi file yang tidak dikenal.');
        }

        $uploadPath = substr($entryName, strlen('uploads/'));
        $segments = explode('/', $uploadPath);

        if (
            count($segments) < 2
            || ! in_array($segments[0], self::UPLOAD_DIRECTORIES, true)
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || str_contains($uploadPath, '\\')
        ) {
            $this->rejectBackup('Arsip backup berisi jalur file unggahan yang tidak valid.');
        }

        return $uploadPath;
    }

    private function validateUploadContents(string $uploadPath, string $contents): void
    {
        $extension = strtolower(pathinfo($uploadPath, PATHINFO_EXTENSION));
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (
            ! isset(self::UPLOAD_MIME_TYPES[$extension])
            || $mimeType !== self::UPLOAD_MIME_TYPES[$extension]
        ) {
            $this->rejectBackup('File unggahan di dalam backup harus berupa gambar JPG, PNG, atau WebP yang valid.');
        }
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderTablesByForeignKeys(array $tables): array
    {
        $tableSet = array_fill_keys($tables, true);
        $orderedTables = [];
        $visited = [];
        $visit = function (string $table) use (&$visit, &$orderedTables, &$visited, $tableSet): void {
            if (isset($visited[$table])) {
                return;
            }

            $visited[$table] = true;
            $foreignKeys = Schema::getForeignKeys($table);
            usort($foreignKeys, static fn (array $left, array $right): int => $left['foreign_table'] <=> $right['foreign_table']);

            foreach ($foreignKeys as $foreignKey) {
                $parentTable = $foreignKey['foreign_table'];

                if (isset($tableSet[$parentTable])) {
                    $visit($parentTable);
                }
            }

            $orderedTables[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $orderedTables;
    }

    /**
     * @return array<string, string>
     */
    private function currentUploads(): array
    {
        $files = [];
        $disk = Storage::disk('public');

        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            foreach ($disk->allFiles($directory) as $path) {
                $contents = $disk->get($path);

                if (! is_string($contents)) {
                    throw new RuntimeException("File unggahan {$path} gagal dibaca sebelum restore.");
                }

                $files[$path] = $contents;
            }
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function replaceUploads(array $files): void
    {
        $disk = Storage::disk('public');

        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            foreach ($disk->allFiles($directory) as $path) {
                if (! $disk->delete($path)) {
                    throw new RuntimeException("File unggahan {$path} gagal diganti saat restore.");
                }
            }
        }

        foreach ($files as $path => $contents) {
            if (! $disk->put($path, $contents)) {
                throw new RuntimeException("File unggahan {$path} gagal dipulihkan.");
            }
        }
    }

    private function rejectBackup(string $message): never
    {
        throw ValidationException::withMessages([
            'backup_file' => $message,
        ]);
    }
}
