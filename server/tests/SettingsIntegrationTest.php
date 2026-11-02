<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\ConfigDoctor;
use App\ConfigReport;
use App\Health;
use App\Installer;
use PHPUnit\Framework\TestCase;

/** M46: the settings.json layer in the doctor, the effective-config report, /health, the CLI and the installer. */
final class SettingsIntegrationTest extends TestCase
{
    private string $dir = '';
    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('SEARCH_SETTINGS_FILE');
        putenv('SEARCH_SETTINGS_FILE');
        $this->dir = TempTree::make('settings_integration');
        mkdir($this->dir . '/storage');
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? 'SEARCH_SETTINGS_FILE' : 'SEARCH_SETTINGS_FILE=' . $this->previousEnv);
        TempTree::removeIfAssigned($this->dir);
    }

    /** @return array{file: array<string, mixed>, panel: array<string, mixed>, merged: array<string, mixed>} */
    private function layers(string $settings, array $overrides = []): array
    {
        if ($settings !== '') {
            file_put_contents($this->dir . '/storage/settings.json', $settings);
        }

        return Config::layers(TempConfig::write($overrides + ['paths' => ['storage' => $this->dir . '/storage']]));
    }

    private function clean(): array
    {
        return ['db' => ['dsn' => 'mysql:host=h;dbname=d', 'user' => 'u'], 'reload' => ['token' => 't'],
            'admin' => TempConfig::admin()];
    }

    public function testTheDoctorReportsAnUnusableFileAsAnErrorByNameOnly(): void
    {
        foreach (['{broken' => 'corrupt'] as $content => $problem) {
            $layers = $this->layers($content, $this->clean());
            $report = ConfigDoctor::check($layers['merged'], null, $layers['panel']);
            self::assertSame(
                ["settings.json: {$problem}, ignored (config.php and the code defaults are in use)"],
                $report['errors']
            );
        }
        $big = (string) json_encode(['x' => str_repeat('a', Config::SETTINGS_MAX_BYTES)]);
        $layers = $this->layers($big, $this->clean());
        self::assertSame(
            ['settings.json: oversized, ignored (config.php and the code defaults are in use)'],
            ConfigDoctor::check($layers['merged'], null, $layers['panel'])['errors']
        );
    }

    public function testTheDoctorWarnsAboutIgnoredAndInvalidKeysWithoutValues(): void
    {
        $layers = $this->layers((string) json_encode([
            'vps.token' => 'planted-secret', 'search.default_limit' => 'abc', 'search.tail_cap' => 3,
        ]), $this->clean());
        $report = ConfigDoctor::check($layers['merged'], null, $layers['panel']);

        self::assertSame([], $report['errors']);
        self::assertContains('vps.token: in settings.json but not editable there, ignored', $report['warnings']);
        self::assertContains('search.default_limit: invalid value in settings.json, ignored', $report['warnings']);
        self::assertStringNotContainsString('planted-secret', (string) json_encode($report));
    }

    public function testNoPanelLayerGivesTheSameVerdictAsBefore(): void
    {
        $layers = $this->layers('', $this->clean());
        self::assertSame(
            ConfigDoctor::check($layers['file']),
            ConfigDoctor::check($layers['merged'], null, $layers['panel'])
        );
        self::assertSame(
            ConfigReport::rows($layers['file']),
            ConfigReport::rows($layers['merged'], null, $layers['panel'])
        );
    }

    public function testTheReportGivesPanelValuesTheirOwnSource(): void
    {
        $layers = $this->layers(
            '{"search.title_weight": 14, "search.tail_cap": 3}',
            $this->clean() + ['search' => ['tail_cap' => 12, 'desc_weight' => 2]]
        );
        $rows = [];
        foreach (ConfigReport::rows($layers['merged'], null, $layers['panel']) as $row) {
            $rows[$row['key']] = $row;
        }
        self::assertSame(ConfigReport::SOURCE_PANEL, $rows['search.title_weight']['source']);
        self::assertSame('14', $rows['search.title_weight']['value']);
        self::assertSame(ConfigReport::SOURCE_PANEL, $rows['search.tail_cap']['source'], 'panel wins');
        self::assertSame('3', $rows['search.tail_cap']['value']);
        self::assertSame(ConfigReport::SOURCE_FILE, $rows['search.desc_weight']['source']);
        self::assertSame(ConfigReport::SOURCE_DEFAULT, $rows['search.phrase_bonus']['source']);
    }

    public function testHealthSectionDescribesTheMergedConfig(): void
    {
        $layers = $this->layers('{"search.tail_cap": 3}', $this->clean());
        $section = Health::configSection($layers['merged'], $layers['panel']);
        self::assertTrue($section['ok']);

        $broken = $this->layers('nope');
        $section = Health::configSection($broken['merged'], $broken['panel']);
        self::assertFalse($section['ok']);
        self::assertContains(
            'settings.json: corrupt, ignored (config.php and the code defaults are in use)',
            $section['errors']
        );
    }

    /** @return array{int, string} */
    private function tool(string ...$flags): array
    {
        $file = TempConfig::write($this->clean() + ['paths' => ['storage' => $this->dir . '/storage']]);
        $env = array_filter(
            getenv(),
            static fn (string $k): bool => !str_starts_with($k, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/config-check.php', '--file=' . $file, ...$flags],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out];
    }

    public function testTheCliOutputIsUnchangedWithoutAPanelFileAndShowsItWhenPresent(): void
    {
        [$status, $out] = $this->tool();
        self::assertSame(0, $status);
        self::assertStringContainsString('config is clean', $out);

        file_put_contents($this->dir . '/storage/settings.json', '{"search.tail_cap": 3}');
        [$status, $out] = $this->tool('--verbose');
        self::assertSame(0, $status);
        self::assertStringContainsString('INFO   search.tail_cap = 3 [settings.json]', $out);
        self::assertStringContainsString('[code default]', $out);

        [, $without] = $this->tool('--verbose', '--no-panel');
        self::assertStringContainsString('search.tail_cap = 40 [code default]', $without);

        file_put_contents($this->dir . '/storage/settings.json', '{broken');
        [$status, $out] = $this->tool();
        self::assertSame(1, $status, 'an unusable settings.json is an ERROR like a bad config.php type');
        self::assertStringContainsString('ERROR  settings.json: corrupt', $out);
        [$status] = $this->tool('--no-panel');
        self::assertSame(0, $status);
    }

    public function testTheInstallerNeitherReadsNorWritesTheSettingsFile(): void
    {
        $server = InstallerTest::makeServerDir();
        try {
            mkdir($server . '/storage');
            $settings = '{"search.title_weight": 99}';
            file_put_contents($server . '/storage/settings.json', $settings);

            $installer = new Installer($server, dirname(__DIR__, 2) . '/db/schema.sql');
            self::assertSame('10.0', $installer->defaults()['title_weight']);
            $config = $installer->renderConfig($installer->validate(InstallerTest::validInput()));
            self::assertStringNotContainsString('=> 99', $config);
            self::assertSame($settings, file_get_contents($server . '/storage/settings.json'));
        } finally {
            InstallerTest::removeDir($server);
        }
    }
}
