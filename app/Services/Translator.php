<?php

namespace App\Services;

use App\Services\Model\ModelClient;
use CodeIgniter\Cache\CacheInterface;

/**
 * Indonesian → English for the web pages' English mode, so judges can read
 * the agent's WhatsApp replies. Not part of the WhatsApp product (which is
 * Indonesian only, CLAUDE.md §3). Cached, so each text is translated once.
 */
class Translator
{
    private const CACHE_DAYS = 30;

    private const MAX_CHARS = 6000;

    private const PROMPT = <<<'TXT'
        Translate the user's Indonesian WhatsApp message into natural English for a reader who does not know Indonesian law.
        - Keep the WhatsApp formatting exactly: *bold*, _italic_, line breaks, bullet characters, emoji, numbers, dates, phone numbers and links.
        - Keep Indonesian court terms and add a short English gloss in parentheses the first time, e.g. "relaas (court summons)", "PTSP (court service desk)", "amar (operative part of the verdict)", "verstek (judgment in absence)".
        - Keep names, case numbers and court names unchanged.
        - Return only the translation, nothing else.
        TXT;

    public function __construct(
        private readonly ModelClient $client,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * @throws Model\ModelException
     */
    public function toEnglish(string $text): string
    {
        $text = mb_substr(trim($text), 0, self::MAX_CHARS);
        if ($text === '') {
            return '';
        }

        $cache = $this->cache ?? cache();
        $key   = 'translate_en_' . md5($text);
        $hit   = $cache->get($key);
        if (is_string($hit)) {
            return $hit;
        }

        $result = $this->client->chat([
            ['role' => 'system', 'content' => self::PROMPT],
            ['role' => 'user', 'content' => $text],
        ], 'translation', ['reasoning_effort' => 'low', 'max_completion_tokens' => 3000]);

        $english = trim($result->content);
        $cache->save($key, $english, self::CACHE_DAYS * DAY);

        return $english;
    }
}
