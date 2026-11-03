<?php

namespace App\Commands;

use App\Models\UserModel;
use App\Services\ConversationAgent;
use App\Services\Model\ModelClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Asks the ConversationAgent a question as a WhatsApp user, printing the
 * answer instead of sending it (one real model call; nothing is sent or stored).
 */
class AgentAsk extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'agent:ask';
    protected $description = 'Prints the follow-up answer a WhatsApp user would get (not sent).';
    protected $usage       = 'agent:ask <phone> "<question>"';
    protected $arguments   = ['phone' => 'users.phone, e.g. 6281...', 'question' => 'The question text'];

    public function run(array $params)
    {
        [$phone, $question] = [$params[0] ?? '', $params[1] ?? ''];
        $user               = (new UserModel())->where('phone', $phone)->first();

        if ($user === null || $question === '') {
            CLI::error('Usage: php spark agent:ask <phone> "<question>" (the phone must have messaged Pahami)');

            return EXIT_ERROR;
        }

        $answer = (new ConversationAgent(ModelClient::fromEnv()))->answer(
            (int) $user['id'],
            $question,
            PHP_INT_MAX,
            new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')),
        );

        CLI::write('document #' . ($answer['document_id'] ?? '—') . ($answer['escalated'] ? ' · ESCALATED' : ''), 'green');
        CLI::write($answer['text']);

        return EXIT_SUCCESS;
    }
}
