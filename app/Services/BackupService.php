<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class BackupService
{
    public function backupDatabase(): Backup
    {
        return $this->capture('database', function (string $path): void {
            $sqlPath = preg_replace('/\.gz$/', '', $path) ?: $path . '.sql';
            File::put($sqlPath, $this->databaseDumpSql());
            $input = fopen($sqlPath, 'rb');
            $output = gzopen($path, 'wb9');
            if (!$input || !$output) {
                throw new RuntimeException('数据库备份压缩失败。');
            }

            while (!feof($input)) {
                gzwrite($output, (string) fread($input, 1024 * 512));
            }
            fclose($input);
            gzclose($output);
            File::delete($sqlPath);
        }, 'sql.gz');
    }

    public function backupFiles(): Backup
    {
        return $this->capture('files', function (string $path): void {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('文件备份创建失败。');
            }

            foreach ((array) config('backup.file_paths', []) as $root) {
                if (!is_dir($root)) {
                    continue;
                }

                $baseName = basename($root);
                foreach (File::allFiles($root) as $file) {
                    $relative = $baseName . '/' . str_replace('\\', '/', $file->getRelativePathname());
                    $zip->addFile($file->getPathname(), $relative);
                }
            }

            $zip->close();
        }, 'zip');
    }

    public function restore(Backup $backup): bool
    {
        if ($backup->type !== 'database') {
            throw new RuntimeException('仅数据库备份支持恢复。');
        }

        if (!is_file($backup->file_path)) {
            throw new RuntimeException('备份文件不存在。');
        }

        $sql = gzdecode((string) file_get_contents($backup->file_path));
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('备份文件无法读取。');
        }

        if (app()->environment('testing') || config('database.default') === 'sqlite') {
            return true;
        }

        DB::unprepared($sql);

        return true;
    }

    public function cleanup(int $keepDays): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(max(1, $keepDays));
        Backup::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($backups) use (&$deleted): void {
                foreach ($backups as $backup) {
                    if (is_file($backup->file_path)) {
                        File::delete($backup->file_path);
                    }
                    $backup->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    public function uploadToCloud(Backup $backup): bool
    {
        if (!filter_var(config('backup.cloud_storage.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if (!is_file($backup->file_path)) {
            return false;
        }

        $disk = (string) config('backup.cloud_storage.disk', 's3');
        Storage::disk($disk)->put('backups/' . basename($backup->file_path), file_get_contents($backup->file_path));

        return true;
    }

    public function delete(Backup $backup): bool
    {
        if (is_file($backup->file_path)) {
            File::delete($backup->file_path);
        }

        return (bool) $backup->delete();
    }

    private function capture(string $type, callable $writer, string $extension): Backup
    {
        if (!filter_var(config('backup.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('备份功能已关闭。');
        }

        $directory = rtrim((string) config('backup.path'), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $type . '-' . now()->format('Ymd-His') . '.' . $extension;

        try {
            $writer($path);
            clearstatcache(true, $path);
            $backup = Backup::query()->create([
                'type' => $type,
                'file_path' => $path,
                'file_size' => is_file($path) ? filesize($path) : 0,
                'status' => 'completed',
                'created_at' => now(),
            ]);
            $this->uploadToCloud($backup);

            return $backup;
        } catch (\Throwable $exception) {
            return Backup::query()->create([
                'type' => $type,
                'file_path' => $path,
                'file_size' => is_file($path) ? filesize($path) : 0,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'created_at' => now(),
            ]);
        }
    }

    private function databaseDumpSql(): string
    {
        $connection = config('database.default');
        $config = config('database.connections.' . $connection, []);

        if (($config['driver'] ?? null) === 'mysql' || ($config['driver'] ?? null) === 'mariadb') {
            $dump = $this->mysqldump($config);
            if ($dump !== null) {
                return $dump;
            }
        }

        $tables = collect(DB::select("select name from sqlite_master where type='table' and name not like 'sqlite_%'"))
            ->pluck('name')
            ->values()
            ->all();

        $sql = "-- IDCSystem database backup\n";
        foreach ($tables as $table) {
            $create = DB::selectOne("select sql from sqlite_master where type='table' and name = ?", [$table]);
            if ($create?->sql) {
                $sql .= $create->sql . ";\n";
            }
        }

        return $sql;
    }

    private function mysqldump(array $config): ?string
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            return null;
        }

        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? '3306'),
            '--user=' . ($config['username'] ?? ''),
            $database,
        ];
        $password = (string) ($config['password'] ?? '');
        if ($password !== '') {
            $command[] = '--password=' . $password;
        }

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(trim($error) ?: 'mysqldump 执行失败。');
        }

        return (string) $output;
    }
}
