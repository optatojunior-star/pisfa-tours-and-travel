<?php

namespace App\Services\Operations;

use App\Services\AuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Takes and prunes backups.
 *
 * Two things are backed up and they have different reasons. The database is the
 * business; private media is the evidence — passport scans, signed contracts,
 * inspection photographs — which cannot be regenerated from anything and which
 * is precisely what a customer would ask for after a dispute.
 *
 * `.env` is deliberately **not** included. It holds APP_KEY, and a backup
 * archive that contains both the encrypted data and the key that decrypts it is
 * a single file that gives away everything. APP_KEY belongs in a secret store,
 * which docs/BACKUP_AND_RESTORE.md says plainly and this class will not
 * quietly contradict.
 */
class BackupRunner
{
    public function __construct(
        private readonly DatabaseDump $dump,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{database: string, media: string|null, bytes: int}
     */
    public function run(bool $includeMedia = true): array
    {
        $disk = $this->disk();
        $stamp = now()->format('Y-m-d_His');
        $prefix = trim((string) config('operations.backup.path', 'backups'), '/');

        $databasePath = "{$prefix}/db-{$stamp}.sql";

        $this->writeDump($disk, $databasePath);

        $mediaPath = null;

        if ($includeMedia) {
            $mediaPath = $this->archiveMedia($disk, "{$prefix}/media-{$stamp}.zip");
        }

        $bytes = $disk->size($databasePath) + ($mediaPath === null ? 0 : $disk->size($mediaPath));

        // Audited without the contents: an operator needs to be able to prove a
        // backup was taken, and the audit trail is read by more people than the
        // backup directory is.
        $this->auditLogger->record(
            event: 'operations.backup_taken',
            newValues: [
                'database' => basename($databasePath),
                'media' => $mediaPath === null ? null : basename($mediaPath),
                'bytes' => $bytes,
                'disk' => (string) config('operations.backup.disk', 'local'),
            ],
        );

        return ['database' => $databasePath, 'media' => $mediaPath, 'bytes' => $bytes];
    }

    /**
     * Streams the dump to disk without holding it in memory.
     *
     * Written to a temporary file first and moved into place only once it is
     * complete, so a run that dies half way through never leaves a truncated
     * file that looks like a usable backup.
     */
    private function writeDump(Filesystem $disk, string $path): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'pisfa-dump-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the database dump.');
        }

        try {
            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Could not open the temporary dump file for writing.');
            }

            try {
                foreach ($this->dump->stream() as $chunk) {
                    if (fwrite($handle, $chunk) === false) {
                        throw new RuntimeException('Writing the database dump failed part way through.');
                    }
                }
            } finally {
                fclose($handle);
            }

            $stream = fopen($temporary, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Could not read the completed dump back.');
            }

            try {
                $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Zips the private media disk.
     *
     * Returns null rather than throwing when the zip extension is missing:
     * losing the media half of a backup is bad, but losing the database half
     * because the media half could not be packaged would be worse.
     */
    private function archiveMedia(Filesystem $disk, string $path): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $source = (string) config('operations.backup.media_disk', 'local');
        $files = Storage::disk($source)->allFiles();

        // Backups live on the same disk in the default configuration; including
        // them would grow each backup by the size of every previous one.
        $prefix = trim((string) config('operations.backup.path', 'backups'), '/');
        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => ! str_starts_with($file, $prefix.'/'),
        ));

        if ($files === []) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'pisfa-media-');

        if ($temporary === false) {
            return null;
        }

        $zip = new ZipArchive;

        try {
            if ($zip->open($temporary, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                return null;
            }

            foreach ($files as $file) {
                $contents = Storage::disk($source)->get($file);

                if ($contents !== null) {
                    $zip->addFromString($file, $contents);
                }
            }

            $zip->close();

            $stream = fopen($temporary, 'rb');

            if ($stream === false) {
                return null;
            }

            try {
                $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }

            return $path;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Removes old backups.
     *
     * Never deletes the newest one, whatever the retention settings say. A
     * misconfigured age limit — or a clock that jumped — must not be able to
     * leave the deployment with nothing to restore from.
     *
     * @return list<string>
     */
    public function prune(): array
    {
        $disk = $this->disk();
        $prefix = trim((string) config('operations.backup.path', 'backups'), '/');
        $keep = max(1, (int) config('operations.backup.keep', 14));
        $maxAgeDays = max(1, (int) config('operations.backup.max_age_days', 60));

        $files = collect($disk->files($prefix))
            ->filter(static fn (string $file): bool => str_ends_with($file, '.sql') || str_ends_with($file, '.zip'))
            // Newest first. The file name carries the timestamp, so this holds
            // even where the disk does not report a modified time.
            ->sortDesc()
            ->values();

        $cutoff = now()->subDays($maxAgeDays)->getTimestamp();
        $deleted = [];

        foreach ($files as $index => $file) {
            // The newest of each kind always survives.
            if ($index === 0) {
                continue;
            }

            $tooMany = $index >= $keep;
            $tooOld = $disk->lastModified($file) < $cutoff;

            if ($tooMany || $tooOld) {
                $disk->delete($file);
                $deleted[] = $file;
            }
        }

        return $deleted;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('operations.backup.disk', 'local'));
    }
}
