<?php

namespace App\Services\HwAssignment;

use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Files written by the web system live in the school's cloud bucket. This project has no
 * bucket credentials yet, so its uploads go to the disk in HW_FILES_DISK under the same paths.
 */
class HomeworkFiles
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{name: string, path: string, size: int}>
     */
    public function store(Connection $db, array $files, string $folder, string $prefix): array
    {
        $eiin = $db->table('institute_info')->value('institute_EIIN');
        $stored = [];

        try {
            foreach ($files as $index => $file) {
                $name = sprintf('%s_%d_%s_%d.%s', $prefix, time(), Str::lower(Str::random(6)), $index, Str::lower($file->getClientOriginalExtension()));
                $path = "{$eiin}/homework/{$folder}/{$name}";

                if (! Storage::disk($this->disk())->putFileAs(dirname($path), $file, $name)) {
                    throw new RuntimeException('A homework file could not be saved.');
                }

                $stored[] = ['name' => $file->getClientOriginalName(), 'path' => $path, 'size' => $file->getSize()];
            }
        } catch (\Throwable $e) {
            $this->delete(array_column($stored, 'path'));

            throw $e;
        }

        return $stored;
    }

    public function delete(array $paths): void
    {
        if ($paths) {
            Storage::disk($this->disk())->delete($paths);
        }
    }

    public function urlResolver(Connection $db): callable
    {
        $cloud = (string) $db->table('institute_info')->value('institute_storage_path');

        return fn (?string $path) => $this->url($path, $cloud);
    }

    private function url(?string $path, string $cloud): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk($this->disk())->exists($path)
            ? rtrim(config('pathshala.homework.files_url') ?: url('storage'), '/').'/'.$path
            : rtrim($cloud, '/').'/'.$path;
    }

    private function disk(): string
    {
        return config('pathshala.homework.files_disk');
    }
}
