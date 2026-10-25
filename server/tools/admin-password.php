<?php

/**
 * Admin password hash generator (M39): prompts for a password and prints the password_hash()
 * value to put in config.php as admin.password_hash. The panel never accepts a plaintext password.
 *
 *   php server/tools/admin-password.php
 *
 * The prompt hides the typed characters on a terminal; with a piped stdin the first line is read
 * (handy for scripts, but it then sits in your shell history). The password is never stored or
 * printed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

const MIN_LENGTH = 12;

$interactive = function_exists('posix_isatty') && posix_isatty(STDIN);

$read = static function (string $prompt) use ($interactive): string {
    fwrite(STDERR, $prompt);
    if ($interactive) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($interactive) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }

    return $line === false ? '' : rtrim($line, "\r\n");
};

$password = $read('New admin password: ');
if (mb_strlen($password) < MIN_LENGTH) {
    fwrite(STDERR, 'The password must be at least ' . MIN_LENGTH . " characters.\n");
    exit(1);
}
if ($interactive && $read('Repeat the password: ') !== $password) {
    fwrite(STDERR, "The two entries differ.\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), "\n";
fwrite(STDERR, "Put this value in config.php as 'admin' => ['password_hash' => '...'].\n");
fwrite(STDERR, "Single-quote it in PHP: the hash contains dollar signs.\n");
