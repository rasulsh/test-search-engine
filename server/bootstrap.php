<?php

/**
 * Runtime bootstrap: registers a zero-dependency PSR-4 autoloader for the App\
 * namespace (the server must run with no Composer runtime dependencies) and
 * returns the configuration array.
 *
 * Prefers server/config.php; falls back to the committed example so the app is
 * runnable out of the box in dev.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = is_file(__DIR__ . '/config.php')
    ? __DIR__ . '/config.php'
    : __DIR__ . '/config.example.php';

return require $configFile;
