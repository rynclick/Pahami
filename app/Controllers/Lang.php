<?php

namespace App\Controllers;

use App\Filters\UiLocale;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * /lang/{id|en}?r=<path>: remembers the page language for a year, then goes back.
 */
class Lang extends BaseController
{
    public function set(string $locale): RedirectResponse
    {
        $locale = in_array($locale, UiLocale::LOCALES, true) ? $locale : 'id';

        // Only paths of this site, never an external URL.
        $back = (string) $this->request->getGet('r');
        if (! preg_match('~^/?(demo|admin)(/[\w/-]*)?$~', $back)) {
            $back = 'demo/chat';
        }

        return redirect()->to(site_url(ltrim($back, '/')))
            ->setCookie(UiLocale::COOKIE, $locale, YEAR, '', '/', '', null, false, 'Lax');
    }
}
