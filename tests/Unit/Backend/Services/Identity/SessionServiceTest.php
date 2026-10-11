<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\PrincipalType;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Repositories\Identity\SessionRepository;
use Bulbula\Backend\Services\Identity\SessionService;
use Bulbula\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sessions as specified: opaque token, hash-only storage, two lifetimes, and
 * revocation that is immediate and server-side (TD-02, S-1 … S-8).
 */
final class SessionServiceTest extends DatabaseTestCase
{
    #[Test]
    public function it_issues_an_opaque_token_and_stores_only_its_hash(): void
    {
        $issued = $this->sessions()->start(PrincipalType::Customer, 7, Surface::Web);

        // 256 bits of CSPRNG, hex-encoded: nothing is guessable and no claim
        // sits inside the value (S-2).
        self::assertSame(64, strlen($issued->token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issued->token);

        $stored = $this->storedTokenHash();

        self::assertSame(hash('sha256', $issued->token), $stored);
        self::assertStringNotContainsString($issued->token, $stored);
    }

    #[Test]
    public function it_resolves_the_token_it_issued(): void
    {
        $issued = $this->sessions()->start(PrincipalType::Customer, 7, Surface::Web);

        $resolved = $this->sessions()->resolve($issued->token);

        self::assertNotNull($resolved);
        self::assertSame(7, $resolved->principalId);
        self::assertSame(PrincipalType::Customer, $resolved->principalType);
    }

    #[Test]
    public function an_unknown_or_empty_token_resolves_to_nothing(): void
    {
        self::assertNull($this->sessions()->resolve(''));
        self::assertNull($this->sessions()->resolve(str_repeat('a', 64)));
    }

    #[Test]
    public function a_revoked_session_is_over_immediately(): void
    {
        $issued = $this->sessions()->start(PrincipalType::Customer, 7, Surface::Web);

        self::assertTrue($this->sessions()->revoke($issued->token));
        self::assertNull($this->sessions()->resolve($issued->token));
    }

    #[Test]
    public function it_ends_a_session_that_has_gone_idle(): void
    {
        // The inactivity window is `SESSION_LIFETIME` (1200 seconds by default
        // in config/security.php) — configuration, not a constant (S-6).
        $issued = $this->sessions()->start(PrincipalType::Customer, 7, Surface::Web);
        $window = $this->config()->int('security.session.lifetime');

        $this->clock->advance($window - 1);
        self::assertNotNull($this->sessions()->resolve($issued->token));

        $this->clock->advance($window);
        self::assertNull($this->sessions()->resolve($issued->token));
    }

    #[Test]
    public function activity_keeps_a_session_alive_until_the_absolute_cap(): void
    {
        // A short cap (one hour) so the rule can be isolated from the
        // inactivity window: every step below stays well inside the window, so
        // the only thing that can end this session is the cap itself.
        $issued = $this->sessions()->start(PrincipalType::Customer, 7, Surface::Web);
        $window = $this->config()->int('security.session.lifetime');
        $cap = $this->config()->int('security.session.absolute_lifetime');

        $step = (int) ($cap / 3);

        self::assertSame(3000, $cap, 'this test states the cap it overrides');
        self::assertLessThan($window, $step, 'each step must stay inside the inactivity window');

        // Two steps in, the session is being used steadily and is still live.
        foreach ([1, 2] as $number) {
            $this->clock->advance($step);
            self::assertNotNull($this->sessions()->resolve($issued->token), 'step ' . $number);
        }

        // The third step reaches the cap exactly — active the whole way, and
        // over all the same.
        $this->clock->advance($step);
        self::assertNull($this->sessions()->resolve($issued->token));
    }

    /** @return array<string, int|string|bool> */
    protected function securityOverrides(): array
    {
        return ['session.absolute_lifetime' => 3000];
    }

    #[Test]
    public function signing_out_everywhere_covers_all_of_one_principals_sessions(): void
    {
        $service = $this->sessions();
        $phone = $service->start(PrincipalType::Customer, 7, Surface::Web);
        $desktop = $service->start(PrincipalType::Customer, 7, Surface::Api);
        $someoneElse = $service->start(PrincipalType::Customer, 8, Surface::Web);

        self::assertSame(2, $service->revokeAllFor(PrincipalType::Customer, 7));

        self::assertNull($service->resolve($phone->token));
        self::assertNull($service->resolve($desktop->token));
        self::assertNotNull($service->resolve($someoneElse->token), 'another Customer keeps their session (S-8)');
    }

    #[Test]
    public function rotation_issues_a_new_token_and_kills_the_old_one(): void
    {
        // S-5, K-6: a fresh identifier is issued at authentication or privilege
        // change, and the pre-change value is never promoted.
        $service = $this->sessions();
        $first = $service->start(PrincipalType::Customer, 7, Surface::Web);

        $second = $service->rotate($first->token);

        self::assertNotNull($second);
        self::assertNotSame($first->token, $second->token);
        self::assertNull($service->resolve($first->token));
        self::assertNotNull($service->resolve($second->token));
    }

    #[Test]
    public function a_session_records_which_surface_it_was_created_on(): void
    {
        $issued = $this->sessions()->start(PrincipalType::Customer, 7, Surface::Telegram, network: '10.0.0.1');

        $rows = $this->database()->pdo()
            ->query('SELECT surface, network_marker, principal_type, principal_id FROM sessions')
            ->fetch();

        self::assertSame('telegram', $rows['surface']);
        self::assertSame('10.0.0.1', $rows['network_marker']);
        self::assertSame('customer', $rows['principal_type']);
        self::assertSame(7, (int) $rows['principal_id']);
        self::assertSame(Surface::Telegram, $issued->session->surface);
    }

    private function sessions(): SessionService
    {
        return new SessionService(new SessionRepository($this->database()), $this->config(), $this->clock);
    }

    private function storedTokenHash(): string
    {
        $statement = $this->database()->pdo()->query('SELECT token_hash FROM sessions ORDER BY id DESC LIMIT 1');

        return (string) $statement->fetchColumn();
    }
}
