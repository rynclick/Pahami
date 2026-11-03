<?php

namespace App\Commands;

use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Manual smoke test for a model provider. Makes one real (billed) call.
 */
class ModelPing extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'model:ping';
    protected $description = 'Sends one short request to a model provider and prints the reply and usage.';
    protected $usage       = 'model:ping [provider] [--image path]';
    protected $arguments   = ['provider' => 'meta or baseten (default: MODEL_PROVIDER)'];
    protected $options     = ['--image' => 'Path to a jpg/png to test vision'];

    public function run(array $params)
    {
        $name  = $params[0] ?? (string) env('MODEL_PROVIDER', 'meta');
        $image = CLI::getOption('image');

        $content = 'Balas dengan satu kalimat pendek dalam bahasa Indonesia: siapa Anda?';
        if (is_string($image)) {
            if (! is_file($image)) {
                CLI::error("File not found: {$image}");

                return EXIT_ERROR;
            }
            $content = [
                ModelClient::textPart('Dokumen apa ini? Jawab satu kalimat dalam bahasa Indonesia.'),
                ModelClient::imagePart((string) file_get_contents($image), mime_content_type($image) ?: 'image/jpeg'),
            ];
        }

        try {
            $client = new ModelClient(ModelClient::makeProvider($name));
            $result = $client->chat([['role' => 'user', 'content' => $content]], 'ping', ['reasoning_effort' => 'low']);
        } catch (ModelException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("{$result->provider} / {$result->model}", 'green');
        CLI::write($result->content);
        CLI::write("tokens: {$result->promptTokens} in, {$result->completionTokens} out · {$result->latencyMs} ms", 'light_gray');

        return EXIT_SUCCESS;
    }
}
