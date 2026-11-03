<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Language of the web pages (/demo, /demo/chat, /admin): Indonesian or
 * English, from the cookie set by /lang/{id|en}. WhatsApp replies stay
 * Indonesian; English visitors (hackathon judges) get translations next to them.
 */
class UiLocale implements FilterInterface
{
    public const COOKIE = 'pahami_lang';

    public const LOCALES = ['id', 'en'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $locale = self::current();
        $request->setLocale($locale);
        service('language')->setLocale($locale);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /** The chosen language, or null when the visitor has not chosen yet (shows the picker). */
    public static function chosen(): ?string
    {
        $value = $_COOKIE[self::COOKIE] ?? null;

        return in_array($value, self::LOCALES, true) ? $value : null;
    }

    public static function current(): string
    {
        return self::chosen() ?? 'id';
    }
}
