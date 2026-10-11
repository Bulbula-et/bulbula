<?php

declare(strict_types=1);

namespace Bulbula\Backend\Mail;

use Bulbula\Backend\Exceptions\ConfigurationException;
use RuntimeException;

/**
 * Writes the message to a file under `storage/tmp/mail/`, for development only.
 *
 * This exists because a sign-in flow a developer cannot complete is a sign-in
 * flow nobody tests — and the alternative usually reached for is logging the
 * code, which OTP-10 forbids outright.
 *
 * **It refuses to run outside `APP_ENV=local`.** Not "it is not used in
 * production": it throws, so a production host that ends up pointing at it can
 * only fail loudly, never quietly write sign-in codes to disk. The directory is
 * outside the document root, is not in the repository, and contains one file
 * per message.
 *
 * The real transport — the provider selected under D-41 — belongs in
 * `Backend\Integrations` (TD-07) and will replace this rather than extend it.
 */
final class LocalFileMailer implements Mailer
{
    public function __construct(
        private readonly string $directory,
        private readonly string $environment,
    ) {
    }

    public function send(Message $message): void
    {
        if ($this->environment !== 'local') {
            throw new ConfigurationException(
                'The local file mail transport refuses to run with APP_ENV=' . $this->environment
                . '. Sign-in codes may only be written to disk in local development; production needs '
                . 'the provider selected under D-41 (Backend\\Integrations, TD-07).',
            );
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create the local mail directory [' . $this->directory . '].');
        }

        // Microsecond precision is deliberate. Two codes can be issued inside
        // one second (a sign-in and then a link confirmation), and whoever reads
        // the spool — a developer, or a test — must be able to tell which
        // message is the later one. With second precision the only ordering
        // left is the random suffix, which orders nothing.
        $microseconds = (int) floor(fmod(microtime(true), 1.0) * 1_000_000);

        $name = gmdate('Ymd-His') . '-' . sprintf('%06d', $microseconds)
            . '-' . bin2hex(random_bytes(4)) . '.txt';

        $written = file_put_contents(
            rtrim($this->directory, '/') . '/' . $name,
            "To: {$message->to}\nSubject: {$message->subject}\n\n{$message->body}\n",
        );

        if ($written === false) {
            throw new RuntimeException('Could not write a local mail file in [' . $this->directory . '].');
        }
    }
}
