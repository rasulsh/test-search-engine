<?php

/**
 * Required first by every CLI tool: stops with a plain English message on a PHP that is too old,
 * instead of a fatal error from deep inside src/. The app needs PHP 8.1+ (the host's default `php`
 * binary is often older than the version the site runs).
 *
 * Syntax here, and in the tools that require it, stays at PHP 7.0 level (no `fn`, no nullable types,
 * no `match`), so an old PHP can still parse the tool far enough to run this check.
 */

// phpcs:disable PSR1.Files.SideEffects -- a guard has to run code, and declares its helper for the tests

if (!function_exists('search_tool_php_problem')) {
    /**
     * @param int $versionId PHP_VERSION_ID
     * @param string $version PHP_VERSION
     * @param string $binary the running binary
     * @return string|null the message to print, null when the PHP is new enough
     */
    function search_tool_php_problem($versionId, $version, $binary)
    {
        if ($versionId >= 80100) {
            return null;
        }

        $tool = isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : 'tool.php';

        return "search service: PHP 8.1 or newer is required, but this is PHP {$version} ({$binary}).\n"
            . "Run the tool with the PHP 8 binary of the account, for example:\n"
            . "  /opt/cpanel/ea-php82/root/usr/bin/php {$tool}\n"
            . "and run it as the account user (su - ACCOUNT -c '...'), not as root.\n";
    }
}

if (PHP_SAPI === 'cli') {
    $searchToolProblem = search_tool_php_problem(PHP_VERSION_ID, PHP_VERSION, PHP_BINARY);
    if ($searchToolProblem !== null) {
        fwrite(STDERR, $searchToolProblem);
        exit(1);
    }
    unset($searchToolProblem);
}
