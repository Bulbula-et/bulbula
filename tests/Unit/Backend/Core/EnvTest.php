<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\Env;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one reader of the environment.
 *
 * Two things matter here and both have bitten a real deployment: `.env` is read
 * when it exists, and `.env.example` is **never** read — the example is a
 * document, and treating it as settings would mean shipping a host the file it
 * was told to copy from.
 */
final class EnvTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bulbula-env-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory);

        unset($_ENV['BULBULA_ENV_TEST'], $_SERVER['BULBULA_ENV_TEST']);
    }

    #[Test]
    public function a_missing_env_file_is_recorded_rather_than_guessed_at(): void
    {
        Env::load($this->directory);

        self::assertFalse(Env::fileFound());
    }

    #[Test]
    public function the_example_is_never_read_as_configuration(): void
    {
        // The classic misconfiguration: somebody edits `.env.example` and cannot
        // understand why nothing changes.
        file_put_contents($this->directory . '/.env.example', "BULBULA_ENV_TEST=from-the-example\n");

        Env::load($this->directory);

        self::assertFalse(Env::fileFound());
        self::assertNull(Env::get('BULBULA_ENV_TEST'));
    }

    #[Test]
    public function a_present_env_file_is_read(): void
    {
        file_put_contents($this->directory . '/.env', "BULBULA_ENV_TEST=from-the-file\n");

        Env::load($this->directory);

        self::assertTrue(Env::fileFound());
        self::assertSame('from-the-file', Env::get('BULBULA_ENV_TEST'));
    }

    #[Test]
    public function a_blank_value_falls_back_to_the_default(): void
    {
        // `.env.example` leaves optional keys empty on purpose; empty means
        // "unset", never "the empty string" for a typed read.
        file_put_contents($this->directory . '/.env', "BULBULA_ENV_TEST=\n");

        Env::load($this->directory);

        self::assertTrue(Env::fileFound());
        self::assertSame('fallback', Env::get('BULBULA_ENV_TEST', 'fallback'));
        self::assertSame(7, Env::int('BULBULA_ENV_TEST', 7));
        self::assertTrue(Env::bool('BULBULA_ENV_TEST', true));
    }
}
