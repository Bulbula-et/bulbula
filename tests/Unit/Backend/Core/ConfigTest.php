<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Exceptions\ConfigurationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $basePath = '';

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/bulbula-config-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/config/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->basePath . '/config');
        rmdir($this->basePath);
    }

    #[Test]
    public function it_reads_a_value_by_dot_notation(): void
    {
        $this->write('app', ['name' => 'Bulbula', 'limits' => ['page' => 20]]);

        $config = new Config($this->basePath);
        $config->load('app');

        self::assertSame('Bulbula', $config->string('app.name'));
        self::assertSame(20, $config->int('app.limits.page'));
    }

    #[Test]
    public function a_missing_key_falls_back_to_the_caller_default(): void
    {
        $config = new Config($this->basePath);
        $config->load('app');

        self::assertSame('fallback', $config->string('app.missing', 'fallback'));
        self::assertFalse($config->bool('app.missing'));
    }

    #[Test]
    public function require_refuses_an_unset_value(): void
    {
        $this->write('app', ['name' => '']);

        $config = new Config($this->basePath);
        $config->load('app');

        $this->expectException(ConfigurationException::class);

        $config->require('app.name');
    }

    #[Test]
    public function a_config_file_that_does_not_return_an_array_is_a_boot_failure(): void
    {
        file_put_contents($this->basePath . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn 'not an array';\n");

        $config = new Config($this->basePath);

        $this->expectException(ConfigurationException::class);

        $config->load('app');
    }

    /** @param array<string, mixed> $values */
    private function write(string $name, array $values): void
    {
        file_put_contents(
            $this->basePath . '/config/' . $name . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n",
        );
    }
}
