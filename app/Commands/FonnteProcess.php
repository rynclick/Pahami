<?php

namespace App\Commands;

use App\Services\ConversationAgent;
use App\Services\DocumentAnalyzer;
use App\Services\Model\ModelException;
use App\Services\Model\Transcriber;
use App\Services\Fonnte\FonnteClient;
use App\Services\InboundHandler;
use App\Services\Model\ModelClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Background worker for one inbound WhatsApp message (started by the webhook).
 * Can also be run by hand to re-process a message.
 */
class FonnteProcess extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'fonnte:process';
    protected $description = 'Processes a stored inbound WhatsApp message and sends the replies.';
    protected $usage       = 'fonnte:process <message_id>';
    protected $arguments   = ['message_id' => 'messages.id of the inbound message'];

    public function run(array $params)
    {
        $id = (int) ($params[0] ?? 0);
        if ($id <= 0) {
            CLI::error('Usage: php spark fonnte:process <message_id>');

            return EXIT_ERROR;
        }

        // A PDF with a retry can take a few minutes; the webhook has already answered.
        set_time_limit(300);

        try {
            $client = ModelClient::fromEnv();

            try {
                $transcriber = Transcriber::fromEnv();
            } catch (ModelException $e) {
                // Without a Meta key, voice notes get a polite "please type" reply.
                $transcriber = null;
            }

            (new InboundHandler(FonnteClient::fromEnv(), new DocumentAnalyzer($client), new ConversationAgent($client), $transcriber))->handle($id);
        } catch (Throwable $e) {
            log_message('error', "fonnte:process {$id}: {$e->getMessage()}");
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Message {$id} processed.", 'green');

        return EXIT_SUCCESS;
    }
}
