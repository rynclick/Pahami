<?php

namespace App\Commands;

use App\Services\DemoDataReset;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Starts the demo from an empty state: deletes all users, documents,
 * messages, reminders, model call logs and uploaded files.
 */
class DemoReset extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'demo:reset';
    protected $description = 'Deletes ALL conversation data and uploaded files (asks first unless --force).';
    protected $usage       = 'demo:reset [--force]';
    protected $options     = ['--force' => 'Do not ask for confirmation'];

    public function run(array $params)
    {
        $reset  = new DemoDataReset();
        $counts = $reset->count();
        CLI::write(self::summary('Akan dihapus', $counts));

        if (CLI::getOption('force') === null && CLI::prompt('Hapus semua data ini?', ['n', 'y']) !== 'y') {
            CLI::write('Dibatalkan, tidak ada yang dihapus.');

            return EXIT_SUCCESS;
        }

        CLI::write(self::summary('Dihapus', $reset->reset()), 'green');
        CLI::write('Di browser, klik "Mulai ulang" di simulator untuk memulai sesi baru.');

        return EXIT_SUCCESS;
    }

    private static function summary(string $label, array $counts): string
    {
        return sprintf(
            '%s: %d pengguna, %d dokumen, %d pesan, %d pengingat, %d panggilan model, %d file unggahan',
            $label,
            $counts['users'],
            $counts['documents'],
            $counts['messages'],
            $counts['reminders'],
            $counts['model_calls'],
            $counts['files'],
        );
    }
}
