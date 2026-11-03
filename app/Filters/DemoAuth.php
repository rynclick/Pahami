<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * HTTP basic auth for the demo (and later /admin) pages.
 * Off when DEMO_PASSWORD is empty, e.g. on a laptop without a tunnel.
 */
class DemoAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user     = (string) env('DEMO_USER', 'pahami');
        $password = (string) env('DEMO_PASSWORD', '');

        if ($password === '') {
            return null;
        }

        [$givenUser, $givenPassword] = self::credentials($request->getHeaderLine('Authorization'));

        if (hash_equals($user, $givenUser) && hash_equals($password, $givenPassword)) {
            return null;
        }

        return Services::response()
            ->setStatusCode(401)
            ->setHeader('WWW-Authenticate', 'Basic realm="Pahami", charset="UTF-8"')
            ->setBody('Login diperlukan.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /** @return array{0: string, 1: string} */
    private static function credentials(string $header): array
    {
        if (! preg_match('/^Basic\s+(\S+)$/i', $header, $m)) {
            return ['', ''];
        }

        $decoded = base64_decode($m[1], true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return ['', ''];
        }

        return explode(':', $decoded, 2);
    }
}
