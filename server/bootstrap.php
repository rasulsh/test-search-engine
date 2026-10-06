<?php

/**
 * Runtime bootstrap: registers a zero-dependency PSR-4 autoloader for the App\
 * namespace (the server must run with no Composer runtime dependencies) and
 * returns the configuration array.
 *
 * The configuration is App\Config's defaults with server/config.php's
 * overrides merged over them; without a config.php the app still starts on the
 * defaults (the doctor and /health report the missing credentials).
 */

declare(strict_types=1);

use App\Config;

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

return Config::load(Config::file());
