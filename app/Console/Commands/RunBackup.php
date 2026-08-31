<?php

namespace App\Console\Commands;

use App\Services\Operations\BackupRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes a backup and prunes old ones.
 *
 * Run nightly by the scheduler. The exit code matters: cron on shared hosting
 * mails non-zero output to the account owner, which is the only alerting some
 * deployments will ever have.
 */
class RunBackup extends Command
{
    protected $signature = 'pisfa:backup
        {--no-media : Back up the database only, skipping private media}
        {--no-prune : Keep every existing backup rather than applying retention}';

    protected $description = 'Back up the database and private media, then apply retention';

    public function handle(BackupRunner $runner): int
    {
        try {
            $result = $runner->run(includeMedia: ! $this->option('no-media'));
        } catch (Throwable $exception) {
            report($exception);

            // The message is shown because an operator reading cron mail needs
            // to know what broke; it is a message this codebase wrote, not a
            // driver error that would name the host and user.
            $this->error('The backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Database: '.$result['database']);
        $this->info($result['media'] === null
            ? 'Private media: nothing to archive.'
            : 'Private media: '.$result['media']);
        $this->info('Total: '.$this->humanBytes($result['bytes']));

        if ($this->option('no-prune')) {
            return self::SUCCESS;
        }

        $deleted = $runner->prune();

        $this->line($deleted === []
            ? 'Retention: nothing to remove.'
            : 'Retention: removed '.count($deleted).' old file(s).');

        // A reminder rather than a failure: a same-server backup is still worth
        // having, it just does not survive losing the server.
        if ((string) config('operations.backup.disk', 'local') === 'local') {
            $this->warn('Backups are on the local disk. Copy them off the server, or set '
                .'PISFA_BACKUP_DISK to a remote disk — a backup beside the database does not '
                .'survive losing the server.');
        }

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes = (int) round($bytes / 1024);
        }

        return $bytes.' B';
    }
}
