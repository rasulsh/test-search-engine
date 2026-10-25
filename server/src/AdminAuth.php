<?php

declare(strict_types=1);

namespace App;

/**
 * Login, session and CSRF rules of the admin panel (M39).
 *
 * The session is its own (own name, Secure + HttpOnly + SameSite=Strict, regenerated on login),
 * expires after an idle period and after an absolute lifetime, and is refused over plain HTTP.
 * Failed logins are throttled per address and globally, counted from the audit table.
 */
final class AdminAuth
{
    public const SESSION_NAME = 'search_admin';

    /** @var array<string, mixed> the config's `admin` section */
    private array $admin;

    /**
     * @param array<string, mixed> $admin the config's `admin` section
     */
    public function __construct(array $admin, private readonly ?Audit $audit = null)
    {
        $this->admin = $admin;
    }

    /** What is wrong with the admin config, as key paths (never values); empty when usable or unset. */
    public static function configProblems(array $admin): array
    {
        $user = trim((string) $admin['username']);
        $hash = (string) $admin['password_hash'];
        if ($user === '' && $hash === '') {
            return [];
        }
        $problems = [];
        if ($user === '') {
            $problems[] = 'admin.username: empty while admin.password_hash is set';
        }
        if ($hash === '') {
            $problems[] = 'admin.password_hash: empty while admin.username is set';
        } elseif (password_get_info($hash)['algo'] === null) {
            $problems[] = 'admin.password_hash: not a password_hash() value (plaintext is not accepted; '
                . 'run tools/admin-password.php)';
        }

        return $problems;
    }

    /** True when username and a valid hash are both set; otherwise the panel answers 503. */
    public static function configured(array $admin): bool
    {
        return trim((string) $admin['username']) !== '' && (string) $admin['password_hash'] !== ''
            && self::configProblems($admin) === [];
    }

    /** @param array<string, mixed> $server */
    public function isHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return (bool) $this->admin['trust_forwarded_proto']
            && strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** @param array<string, mixed> $server */
    public function clientIp(array $server): string
    {
        $header = trim((string) $this->admin['ip_header']);
        if ($header !== '' && isset($server[$header])) {
            $parts = array_map('trim', explode(',', (string) $server[$header]));
            $candidate = (string) end($parts); // the entry the nearest proxy appended
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    public function ipAllowed(string $ip): bool
    {
        $list = trim((string) $this->admin['allowed_ips']);
        if ($list === '') {
            return true;
        }
        foreach (explode(',', $list) as $entry) {
            if (self::ipMatches($ip, trim($entry))) {
                return true;
            }
        }

        return false;
    }

    /** An exact address or a CIDR range, IPv4 or IPv6. */
    public static function ipMatches(string $ip, string $entry): bool
    {
        if ($entry === '' || ($packed = @inet_pton($ip)) === false) {
            return false;
        }
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $netPacked = @inet_pton((string) $net);
        if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
            return false;
        }
        $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bits < 0 || $bits > strlen($packed) * 8 || strncmp($packed, $netPacked, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask);
    }

    /**
     * What a session's stored timestamps say: 'ok', 'anonymous' (never logged in), 'idle' or 'expired'.
     *
     * @param array<string, mixed> $session
     */
    public static function sessionState(array $session, int $now, int $idleSeconds, int $maxSeconds): string
    {
        if (empty($session['admin_login']) || !isset($session['created'], $session['last'])) {
            return 'anonymous';
        }
        if ($now - (int) $session['created'] > $maxSeconds) {
            return 'expired';
        }

        return $now - (int) $session['last'] > $idleSeconds ? 'idle' : 'ok';
    }

    /** Starts the panel's session; $path scopes the cookie to the folder the panel is served from. */
    public function start(string $path): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) max(60, (int) $this->admin['idle_timeout_minutes'] * 60));
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path === '' ? '/' : $path,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    /**
     * Validates the running session. 'ok' refreshes the idle clock unless $passive (a background
     * poll must not keep an unattended dashboard logged in); a dead session is wiped.
     */
    public function check(int $now, bool $passive = false): string
    {
        $state = self::sessionState(
            $_SESSION,
            $now,
            (int) $this->admin['idle_timeout_minutes'] * 60,
            (int) $this->admin['session_hours'] * 3600
        );
        if ($state === 'ok') {
            if (!$passive) {
                $_SESSION['last'] = $now;
            }
        } elseif ($state !== 'anonymous') {
            $this->wipe();
        }

        return $state;
    }

    /**
     * @return 'ok'|'invalid'|'throttled'
     */
    public function attemptLogin(string $username, string $password, string $ip, int $now): string
    {
        $window = (int) $this->admin['login_window_minutes'];
        if (
            $this->audit !== null && (
                $this->audit->failures($ip, $window) >= (int) $this->admin['login_max_failures']
                || $this->audit->failures(null, $window) >= (int) $this->admin['login_max_failures_global']
            )
        ) {
            $this->audit->write($ip, Audit::LOGIN_THROTTLED);

            return 'throttled';
        }

        // Both checks always run, so a wrong username costs the same as a wrong password.
        $userOk = hash_equals((string) $this->admin['username'], $username);
        $passOk = password_verify($password, (string) $this->admin['password_hash']);
        if (!$userOk || !$passOk) {
            $this->audit?->write($ip, Audit::LOGIN_FAIL);

            return 'invalid';
        }

        session_regenerate_id(true);
        $_SESSION = ['admin_login' => true, 'created' => $now, 'last' => $now, 'csrf' => bin2hex(random_bytes(32))];
        $this->audit?->write($ip, Audit::LOGIN_OK);

        return 'ok';
    }

    public function logout(string $ip): void
    {
        $this->audit?->write($ip, Audit::LOGOUT);
        $this->wipe();
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public function csrfValid(?string $given): bool
    {
        return is_string($given) && $given !== '' && hash_equals($this->csrfToken(), $given);
    }

    private function wipe(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
