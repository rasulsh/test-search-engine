<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * An overrides file for a server the test spawns, pointed at with
 * SEARCH_CONFIG_FILE: the same shape as a deployer's config.php.
 */
final class TempConfig
{
    public const ADMIN_USER = 'admin';
    public const ADMIN_PASSWORD = 'correct-horse-battery';
    /** password_hash() of ADMIN_PASSWORD at the cheapest cost, so tests stay fast. */
    public const ADMIN_HASH = '$2y$04$v/kr1SAX4nx5PRisIO0y4uzV6TS5QR3m.QmKiNIRAvAOi4/h39hlu';

    /** @return array{username: string, password_hash: string} a working admin section */
    public static function admin(): array
    {
        return ['username' => self::ADMIN_USER, 'password_hash' => self::ADMIN_HASH];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return string the file path; removed when the test process exits
     */
    public static function write(array $overrides): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'test_config_');
        file_put_contents($path, '<?php return ' . var_export($overrides, true) . ";\n");
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
