<?php

namespace App\Services\Notice;

use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Notice attachments, stored the same way as homework files: this project has no bucket credentials
 * yet, so uploads go to the disk in HW_FILES_DISK under <EIIN>/app/notices/. A file on that disk gets
 * its URL from HW_FILES_URL (or /storage); otherwise the school's cloud base URL is used.
 */
class NoticeFiles
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{name: string, path: string, size_bytes: int}>
     */
    public function store(Connection $db, array $files, string $prefix): array
    {
        $eiin = $db->table('institute_info')->value('institute_EIIN');
        $stored = [];

        try {
            foreach ($files as $index => $file) {
                $name = sprintf('%s_%d_%s_%d.%s', $prefix, time(), Str::lower(Str::random(6)), $index, Str::lower($file->getClientOriginalExtension()));
                $path = "{$eiin}/app/notices/{$name}";

                if (! Storage::disk($this->disk())->putFileAs(dirname($path), $file, $name)) {
                    throw new RuntimeException('A notice file could not be saved.');
                }

                $stored[] = ['name' => $file->getClientOriginalName(), 'path' => $path, 'size_bytes' => $file->getSize()];
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

        return fn (?string $path) => $path === null ? null : (
            Storage::disk($this->disk())->exists($path)
                ? rtrim(config('pathshala.homework.files_url') ?: url('storage'), '/').'/'.$path
                : rtrim($cloud, '/').'/'.$path
        );
    }

    private function disk(): string
    {
        return config('pathshala.homework.files_disk');
    }
}
