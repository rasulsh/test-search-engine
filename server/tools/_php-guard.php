<?php

/**
 * Required first by every CLI tool: stops with a plain English message on a PHP that is too old or
 * lacks a required extension, instead of a fatal error from deep inside src/ (display_errors is off
 * in the CLI, so a missing mbstring would otherwise end the tool silently with exit status 255).
 * The app needs PHP 8.1+ (the host's default `php` binary is often older than the version the site
 * runs) and the extensions below; the cPanel alt-php CLI loads some of them only when asked with
 * `-d extension=NAME`.
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

if (!function_exists('search_tool_extension_problem')) {
    /**
     * @param array $missing names of required extensions that are not loaded
     * @param string $binary the running binary
     * @return string|null the message to print, null when nothing is missing
     */
    function search_tool_extension_problem($missing, $binary)
    {
        if (count($missing) === 0) {
            return null;
        }

        $tool = isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : 'tool.php';
        $flags = '';
        foreach ($missing as $name) {
            $flags .= ' -d extension=' . $name;
        }

        $names = implode(', ', $missing);

        return "search service: the PHP extension(s) {$names} not loaded in this PHP ({$binary}).\n"
            . "Run the tool with the extension switched on, for example:\n"
            . "  {$binary}{$flags} {$tool}\n"
            . "or enable it for the account's PHP version in cPanel (Select PHP Version / MultiPHP INI Editor).\n";
    }

    /** @return array required extensions this PHP does not have */
    function search_tool_missing_extensions()
    {
        $missing = array();
        foreach (array('mbstring', 'pdo_mysql', 'curl', 'json') as $name) {
            if (!extension_loaded($name)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}

if (PHP_SAPI === 'cli') {
    $searchToolProblem = search_tool_php_problem(PHP_VERSION_ID, PHP_VERSION, PHP_BINARY);
    if ($searchToolProblem === null) {
        $searchToolProblem = search_tool_extension_problem(search_tool_missing_extensions(), PHP_BINARY);
    }
    if ($searchToolProblem !== null) {
        fwrite(STDERR, $searchToolProblem);
        exit(1);
    }
    unset($searchToolProblem);
}
