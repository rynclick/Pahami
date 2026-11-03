<?php

namespace App\Commands;

use App\Services\Model\ModelException;
use App\Services\Model\Transcriber;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Transcribes a local audio file with Muse Voice Transcribe (one real, billed call).
 */
class VoiceTranscribe extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'voice:transcribe';
    protected $description = 'Transcribes an audio file (ogg/opus, mp3, wav, ...) with Muse Voice Transcribe.';
    protected $usage       = 'voice:transcribe <path>';
    protected $arguments   = ['path' => 'Audio file path inside the container'];

    public function run(array $params)
    {
        $path = $params[0] ?? null;
        if ($path === null || ! is_file($path)) {
            CLI::error('File not found: ' . ($path ?? '(none)'));

            return EXIT_ERROR;
        }

        try {
            $started = microtime(true);
            $text    = Transcriber::fromEnv()->transcribe((string) file_get_contents($path));
        } catch (ModelException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf('%s · %.1f s', Transcriber::MODEL, microtime(true) - $started), 'green');
        CLI::write($text === '' ? '(nothing heard)' : $text);

        return EXIT_SUCCESS;
    }
}
