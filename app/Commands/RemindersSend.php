<?php

namespace App\Commands;

use App\Services\Fonnte\FonnteClient;
use App\Services\ReminderSender;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cron entry point (CLAUDE.md §4): run every 5 minutes.
 */
class RemindersSend extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'reminders:send';
    protected $description = 'Sends due hearing reminders over WhatsApp.';

    public function run(array $params)
    {
        $stats = (new ReminderSender(FonnteClient::fromEnv()))
            ->sendDue(new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')));

        CLI::write(sprintf('reminders: %d sent, %d failed, %d cancelled', $stats['sent'], $stats['failed'], $stats['cancelled']));

        return $stats['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
