<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Api\Controllers\V1\AuthController;
use Bulbula\Api\Controllers\V1\HealthController;
use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\CommandRunner;
use Bulbula\Backend\Console\Commands\MigrateCommand;
use Bulbula\Backend\Console\Commands\MigrationStatusCommand;
use Bulbula\Backend\Console\Commands\RollbackCommand;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Console\StreamOutput;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Migrations\MigrationLocator;
use Bulbula\Backend\Database\Migrations\MigrationRepository;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Backend\Exceptions\ConfigurationException;
use Bulbula\Backend\Mail\LocalFileMailer;
use Bulbula\Backend\Repositories\Identity\CustomerRepository;
use Bulbula\Backend\Repositories\Identity\OtpRequestRepository;
use Bulbula\Backend\Repositories\Identity\SessionRepository;
use Bulbula\Backend\Repositories\Platform\RateLimitRepository;
use Bulbula\Backend\Security\Csrf;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Backend\Services\Identity\MailOtpSender;
use Bulbula\Backend\Services\Identity\OtpSender;
use Bulbula\Backend\Services\Identity\RequestOtpService;
use Bulbula\Backend\Services\Identity\SessionService;
use Bulbula\Backend\Services\Identity\UndeliverableOtpSender;
use Bulbula\Backend\Services\Identity\VerifyOtpService;
use Bulbula\Backend\Support\Health\HealthChecker;
use Bulbula\Backend\Support\LoggerFactory;
use Bulbula\Backend\Support\SystemClock;
use Bulbula\Frontend\Controllers\HomeController;
use Bulbula\Frontend\Controllers\SearchController;
use Bulbula\Frontend\Controllers\SignInController;
use ErrorException;
use Psr\Log\LoggerInterface;

/**
 * The composition root.
 *
 * Everything the application needs is constructed here, explicitly, in one
 * place, in dependency order: read `boot()` top to bottom and you have read
 * the wiring. There is no container and no auto-wiring — a class states what
 * it needs in its constructor, and this is the only place that gives it.
 */
final class Application
{
    private readonly string $basePath;

    private ?Config $config = null;

    private ?Kernel $kernel = null;

    private ?LoggerInterface $logger = null;

    private ?Migrator $migrator = null;

    private ?CommandRunner $console = null;

    private ?SessionService $sessions = null;

    private ?RequestOtpService $otpRequests = null;

    private ?VerifyOtpService $otpVerifications = null;

    private bool $booted = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** @throws ConfigurationException when read before boot() */
    public function config(): Config
    {
        return $this->config ?? throw new ConfigurationException('The application has not booted.');
    }

    /** @throws ConfigurationException when read before boot() */
    public function kernel(): Kernel
    {
        return $this->kernel ?? throw new ConfigurationException('The application has not booted.');
    }

    /** @throws ConfigurationException when read before boot() */
    public function logger(): LoggerInterface
    {
        return $this->logger ?? throw new ConfigurationException('The application has not booted.');
    }

    /** @throws ConfigurationException when read before boot() */
    public function migrator(): Migrator
    {
        return $this->migrator ?? throw new ConfigurationException('The application has not booted.');
    }

    /** @throws ConfigurationException when read before boot() */
    public function sessions(): SessionService
    {
        return $this->sessions ?? throw new ConfigurationException('The application has not booted.');
    }

    /** @throws ConfigurationException when read before boot() */
    public function otpRequests(): RequestOtpService
    {
        return $this->otpRequests ?? throw new ConfigurationException('The application has not booted.');
    }

    /** @throws ConfigurationException when read before boot() */
    public function otpVerifications(): VerifyOtpService
    {
        return $this->otpVerifications ?? throw new ConfigurationException('The application has not booted.');
    }

    /**
     * The console, built on first use: a web request never pays for it.
     *
     * The output is a parameter so a test can read what a command said; the
     * default is the process's own streams.
     *
     * @throws ConfigurationException when a registered command has no factory
     */
    public function console(?Output $output = null): CommandRunner
    {
        if ($output !== null) {
            // An explicit output is a request for *that* output: a test, or a
            // caller wrapping the console. It is not the memoised one.
            return $this->buildConsole($output);
        }

        return $this->console ??= $this->buildConsole(StreamOutput::console());
    }

    /**
     * @throws ConfigurationException when a registered command has no factory
     */
    private function buildConsole(Output $output): CommandRunner
    {
        // One line per command, by hand — the same rule as controllers.
        $factories = [
            'migrate'          => fn (): Command => new MigrateCommand($this->migrator(), $output),
            'migration:status' => fn (): Command => new MigrationStatusCommand($this->migrator(), $output),
            'rollback'         => fn (): Command => new RollbackCommand($this->migrator(), $output),
        ];

        $registered = $this->registeredCommands();

        foreach ($registered as $name) {
            if (!array_key_exists($name, $factories)) {
                throw new ConfigurationException(
                    'routes/console.php registers [' . $name . '], which no factory provides.',
                );
            }
        }

        return new CommandRunner(
            array_intersect_key($factories, array_flip($registered)),
            $output,
        );
    }

    /**
     * How a sign-in code leaves the application, decided by `MAIL_TRANSPORT`.
     *
     * Only two outcomes are possible, and neither lies:
     *
     * - `local` — the development transport, which writes the message to
     *   `storage/tmp/mail/` and refuses to run anywhere but `APP_ENV=local`.
     * - anything else — the sender that refuses, because the only transport
     *   left is a logger, and a code written to a log is a credential in the
     *   one place nobody rotates (OTP-10).
     *
     * When the provider under D-41 lands, its transport is added here and
     * nothing else in this method changes.
     *
     * @throws ConfigurationException
     */
    private function otpSender(Config $config): OtpSender
    {
        if ($config->string('mail.transport') !== 'local') {
            return new UndeliverableOtpSender();
        }

        $directory = $config->string('mail.local_path', 'storage/tmp/mail');

        return new MailOtpSender(
            new LocalFileMailer(
                str_starts_with($directory, '/') ? $directory : $this->basePath . '/' . $directory,
                $config->string('app.env'),
            ),
            $config,
        );
    }

    /**
     * The commands this deployment offers, as declared by the console route
     * file — a table of contents, so the file and the wiring cannot drift
     * apart silently.
     *
     * @return list<string>
     *
     * @throws ConfigurationException when the file does not return a list of names
     */
    private function registeredCommands(): array
    {
        $registered = require $this->basePath . '/routes/console.php';

        if (!is_array($registered)) {
            throw new ConfigurationException('routes/console.php must return a list of command names.');
        }

        $names = [];

        foreach ($registered as $name) {
            if (!is_string($name)) {
                throw new ConfigurationException('routes/console.php must contain command names only.');
            }

            $names[] = $name;
        }

        return $names;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        Env::load($this->basePath);

        $config = new Config($this->basePath);
        $config->load('app', 'database', 'security', 'mail', 'logging');
        $this->config = $config;

        $debug = $config->bool('app.debug');

        $this->registerErrorHandling($debug);
        date_default_timezone_set($config->string('app.timezone', 'Africa/Addis_Ababa'));

        $router = new Router();
        $router->load(
            $this->basePath . '/routes/web.php',
            $this->basePath . '/routes/api.php',
            $this->basePath . '/routes/admin.php',
            $this->basePath . '/routes/health.php',
        );

        // Services, in dependency order.
        $view = new View(
            $this->basePath . '/frontend/Views',
            $this->basePath . '/admin/Views',
        );

        $errors = new ErrorRenderer($debug);

        $clock = new SystemClock();

        // Lazy internally: no connection is opened until a query needs one,
        // which is what lets readiness report an outage instead of dying in it.
        $connection = new Connection($config);

        $health = new HealthChecker($connection, $this->basePath . '/storage/logs');

        $this->migrator = new Migrator(
            $connection,
            new MigrationLocator($this->basePath . '/database/migrations'),
            new MigrationRepository($connection),
            $clock,
        );

        $this->logger = LoggerFactory::create($config, $this->basePath);

        // Identity: sessions, sign-in codes and the limits on them. The sender
        // is the placeholder that refuses (OTP-10) until Backend\Integrations
        // speaks to the mail provider (D-41) — see UndeliverableOtpSender.
        $customers = new CustomerRepository($connection);
        $otpRequestRows = new OtpRequestRepository($connection);

        $csrf = new Csrf($config);

        $this->sessions = new SessionService(
            new SessionRepository($connection),
            $config,
            $clock,
            $csrf,
        );

        $this->otpRequests = new RequestOtpService(
            $otpRequestRows,
            new RateLimiter(new RateLimitRepository($connection), $config, $clock, $this->logger),
            $this->otpSender($config),
            $config,
            $clock,
            $this->logger,
        );

        $this->otpVerifications = new VerifyOtpService(
            $otpRequestRows,
            $customers,
            $this->sessions,
            new RateLimiter(new RateLimitRepository($connection), $config, $clock, $this->logger),
            $config,
            $clock,
            $this->logger,
        );

        // One line per controller, by hand. A controller that is not listed
        // here cannot be reached, and a route that names one fails loudly.
        $this->kernel = new Kernel(
            $router,
            new ControllerFactory([
                HomeController::class     => static fn (): HomeController => new HomeController($view),
                SearchController::class   => static fn (): SearchController => new SearchController($view),
                HealthController::class   => static fn (): HealthController => new HealthController($health),
                SignInController::class   => fn (): SignInController => new SignInController(
                    $view,
                    $config,
                    $csrf,
                    $this->otpRequests(),
                    $this->otpVerifications(),
                    $this->sessions(),
                ),
                AuthController::class     => fn (): AuthController => new AuthController(
                    $this->otpRequests(),
                    $this->otpVerifications(),
                    $this->sessions(),
                ),
            ]),
            $errors,
        );

        $this->booted = true;

        $this->logger->debug('Application booted.', ['env' => $config->string('app.env', 'local')]);
    }

    public function run(): void
    {
        $this->boot();

        $this->kernel()->handle(Request::fromGlobals())->send();
    }

    private function registerErrorHandling(bool $debug): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        // Warnings and notices become exceptions, so nothing is silently ignored.
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
