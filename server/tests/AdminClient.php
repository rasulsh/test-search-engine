<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * A tiny browser for the admin panel's tests: keeps cookies, never follows redirects, and (unless
 * $https is false) says X-Forwarded-Proto: https, as the proxy in front of the real host would.
 */
final class AdminClient
{
    /** @var array<string, string> */
    public array $cookies = [];
    /** @var list<string> every Set-Cookie line seen */
    public array $setCookies = [];

    public function __construct(private readonly string $baseUrl, private readonly bool $https = true)
    {
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        $curl = curl_init($this->baseUrl . $path);
        $sent = [];
        foreach ($headers as $name => $value) {
            $sent[] = "{$name}: {$value}";
        }
        if ($this->https) {
            $sent[] = 'X-Forwarded-Proto: https';
        }
        if ($this->cookies !== []) {
            $sent[] = 'Cookie: ' . implode('; ', array_map(
                static fn (string $k, string $v): string => "{$k}={$v}",
                array_keys($this->cookies),
                $this->cookies
            ));
        }
        $received = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $sent,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    if ($name === 'set-cookie') {
                        $this->setCookies[] = $value;
                        [$pair] = explode(';', $value, 2);
                        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                        $this->cookies[$k] = $v;
                    }
                    $received[$name] = $value;
                }

                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status, 'headers' => $received, 'body' => $body];
    }

    /** The csrf token the page's first form carries. */
    public static function csrfIn(string $html): string
    {
        return preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m) === 1 ? $m[1] : '';
    }

    /**
     * Opens the login form and posts the credentials.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function login(string $user = TempConfig::ADMIN_USER, string $password = TempConfig::ADMIN_PASSWORD): array
    {
        $form = $this->request('GET', '/admin.php?p=login');

        return $this->request('POST', '/admin.php?p=login', [
            'csrf' => self::csrfIn($form['body']), 'username' => $user, 'password' => $password,
        ]);
    }

    /** The csrf token of the logged-in panel (read off the status page's logout form). */
    public function csrf(): string
    {
        return self::csrfIn($this->request('GET', '/admin.php?p=help')['body']);
    }
}
