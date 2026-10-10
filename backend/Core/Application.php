<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Api\Controllers\V1\HealthController;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Exceptions\ConfigurationException;
use Bulbula\Backend\Support\Health\HealthChecker;
use Bulbula\Backend\Support\LoggerFactory;
use Bulbula\Frontend\Controllers\HomeController;
use Bulbula\Frontend\Controllers\SearchController;
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

        // Lazy internally: no connection is opened until a query needs one,
        // which is what lets readiness report an outage instead of dying in it.
        $connection = new Connection($config);

        $health = new HealthChecker($connection, $this->basePath . '/storage/logs');

        $this->logger = LoggerFactory::create($config, $this->basePath);

        // One line per controller, by hand. A controller that is not listed
        // here cannot be reached, and a route that names one fails loudly.
        $this->kernel = new Kernel(
            $router,
            new ControllerFactory([
                HomeController::class   => static fn (): HomeController => new HomeController($view),
                SearchController::class => static fn (): SearchController => new SearchController($view),
                HealthController::class => static fn (): HealthController => new HealthController($health),
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
