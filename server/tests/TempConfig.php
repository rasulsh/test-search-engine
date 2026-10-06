<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * An overrides file for a server the test spawns, pointed at with
 * SEARCH_CONFIG_FILE: the same shape as a deployer's config.php.
 */
final class TempConfig
{
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
