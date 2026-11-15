<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;

/**
 * Uploads used to sit in storage/app/public/uploads — the one directory a
 * `php artisan storage:link` symlink publishes, which would have let anybody
 * fetch any file without its capability token. They now live on the private
 * `uploads` disk (storage/app/private/uploads). Stored paths are relative
 * ("uploads/<channel-uuid>/<file>") and do not change; only the files move.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(storage_path('app/public/uploads'), storage_path('app/private/uploads'));
    }

    public function down(): void
    {
        $this->move(storage_path('app/private/uploads'), storage_path('app/public/uploads'));
    }

    private function move(string $from, string $to): void
    {
        // The test suite migrates a throwaway database; it must not shuffle
        // the developer's real files around.
        if (app()->runningUnitTests() || ! is_dir($from)) {
            return;
        }

        File::ensureDirectoryExists($to);

        // Channel by channel, so a half-finished run can simply be repeated.
        foreach (File::directories($from) as $channelDir) {
            $target = $to.DIRECTORY_SEPARATOR.basename($channelDir);
            if (! is_dir($target)) {
                rename($channelDir, $target);

                continue;
            }
            foreach (File::allFiles($channelDir) as $file) {
                $destination = $target.DIRECTORY_SEPARATOR.$file->getRelativePathname();
                File::ensureDirectoryExists(dirname($destination));
                rename($file->getPathname(), $destination);
            }
            File::deleteDirectory($channelDir);
        }

        if (! File::allFiles($from)) {
            File::deleteDirectory($from);
        }
    }
};
