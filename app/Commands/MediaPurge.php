<?php

namespace App\Commands;

use App\Services\MediaPurger;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * CLAUDE.md §9: do not keep WhatsApp media longer than needed.
 * The scheduler container runs this every hour.
 */
class MediaPurge extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'media:purge';
    protected $description = 'Deletes uploaded documents and media links older than --days (default 7).';
    protected $usage       = 'media:purge [--days=7] [--dry-run]';
    protected $options     = [
        '--days'    => 'Retention in days (default 7)',
        '--dry-run' => 'Only count what would be deleted',
    ];

    public function run(array $params)
    {
        $days = (int) (CLI::getOption('days') ?? self::equalsOption('days') ?? 7);
        if ($days < 1) {
            CLI::error('--days must be at least 1');

            return EXIT_ERROR;
        }

        $dryRun = CLI::getOption('dry-run') !== null;
        $stats  = (new MediaPurger())->purge(new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')), $days, $dryRun);

        CLI::write(sprintf(
            'media%s older than %d days: %d documents, %d orphan files, %d media links',
            $dryRun ? ' (dry run)' : '',
            $days,
            $stats['documents'],
            $stats['files'],
            $stats['links'],
        ));

        return EXIT_SUCCESS;
    }

    /** CodeIgniter reads "--days 7" only; CLAUDE.md §9 writes "--days=7". */
    private static function equalsOption(string $name): ?string
    {
        foreach (array_keys(CLI::getOptions()) as $option) {
            if (str_starts_with((string) $option, $name . '=')) {
                return substr((string) $option, strlen($name) + 1);
            }
        }

        return null;
    }
}
