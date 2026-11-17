<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class CreateStoreBackup
{
    private const FORMAT = 'spot-print-backup';

    private const VERSION = 2;

    private const DATA_TABLES = [
        'activity_logs',
        'cash_entries',
        'cashier_shifts',
        'customers',
        'product_categories',
        'product_price_histories',
        'product_stock_movements',
        'product_wholesale_groups',
        'products',
        'purchase_items',
        'purchases',
        'sale_items',
        'sales',
        'store_settings',
        'suppliers',
        'users',
    ];

    private const UPLOAD_DIRECTORIES = [
        'products',
        'purchase-receipts',
        'store',
    ];

    private const MAX_BACKUP_BYTES = 50 * 1024 * 1024;

    /**
     * @return array{path: string, filename: string}
     */
    public function handle(): array
    {
        $database = DB::transaction(function (): array {
            $database = [];

            foreach ($this->dataTables() as $table) {
                $database[$table] = array_map(
                    static fn (object $row): array => (array) $row,
                    DB::table($table)->get()->all(),
                );
            }

            return $database;
        });

        $databaseJson = json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'database' => $database,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = tempnam(sys_get_temp_dir(), 'spot-print-backup-');

        if ($path === false) {
            throw new RuntimeException('File sementara untuk backup tidak dapat dibuat.');
        }

        $archive = new ZipArchive;
        $opened = $archive->open($path, ZipArchive::OVERWRITE);

        if ($opened !== true) {
            if (! unlink($path)) {
                throw new RuntimeException('File sementara backup gagal dibersihkan.');
            }

            throw new RuntimeException('Arsip backup tidak dapat dibuat.');
        }

        try {
            if (! $archive->addFromString('database.json', $databaseJson)) {
                throw new RuntimeException('Data database gagal ditambahkan ke arsip backup.');
            }

            $disk = Storage::disk('public');
            foreach (self::UPLOAD_DIRECTORIES as $directory) {
                foreach ($disk->allFiles($directory) as $uploadPath) {
                    $contents = $disk->get($uploadPath);

                    if (! is_string($contents)) {
                        throw new RuntimeException("File unggahan {$uploadPath} gagal dibaca.");
                    }

                    if (! $archive->addFromString('uploads/'.$uploadPath, $contents)) {
                        throw new RuntimeException("File unggahan {$uploadPath} gagal ditambahkan ke backup.");
                    }
                }
            }

            if (! $archive->close()) {
                throw new RuntimeException('Arsip backup gagal diselesaikan.');
            }

            if (filesize($path) > self::MAX_BACKUP_BYTES) {
                throw ValidationException::withMessages([
                    'backup' => 'Backup melebihi batas unduhan 50 MB. Kurangi ukuran data atau file unggahan.',
                ]);
            }
        } catch (Throwable $exception) {
            if (file_exists($path) && ! unlink($path)) {
                throw new RuntimeException('Backup gagal dibuat dan file sementara tidak dapat dibersihkan.', 0, $exception);
            }

            throw $exception;
        }

        return [
            'path' => $path,
            'filename' => 'spot-print-backup-'.now()->format('Ymd-His').'.zip',
        ];
    }

    /**
     * @return list<string>
     */
    public static function dataTables(): array
    {
        foreach (self::DATA_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Tabel data toko {$table} tidak ditemukan. Periksa migrasi database.");
            }
        }

        return self::DATA_TABLES;
    }

    /**
     * @return list<string>
     */
    public static function legacyDataTables(): array
    {
        return array_values(array_filter(
            self::DATA_TABLES,
            static fn (string $table): bool => $table !== 'product_wholesale_groups',
        ));
    }
}
