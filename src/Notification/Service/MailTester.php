<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Notification\Domain\Notifier;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Validation\Locale;
use Throwable;

/**
 * A test mail, to the person asking (2026-09-19).
 *
 * The one place a mail is sent *inside* a request rather than by the job
 * queue — because the point is to see it fail or arrive now, with the
 * reason, while the administrator is looking. Rendered from the template
 * as a real notice would be, with a sample payload, so what arrives is what
 * a person would get.
 *
 * Refused plainly where no mail can leave: the log stand-in is not a
 * channel to test. A failure carries the transport's own sentence, redacted
 * of anything that looks like a credential — this is the administrator
 * reading their own configuration's error, not a customer reading ours.
 */
final class MailTester
{
    public function __construct(
        private readonly Notifier $email,
        private readonly MailWording $wording,
        private readonly string $appUrl = '',
    ) {
    }

    /** Whether a test could leave at all: what the console says before offering the button. */
    public function isLive(): bool
    {
        return $this->email->isLive();
    }

    /**
     * @return array{to: string, subject: string, provider_message_id: string}
     */
    public function send(string $type, string $to, string $locale = Locale::DEFAULT): array
    {
        if (!isset(MailWording::DEFAULTS[$type])) {
            throw new BadRequestException('VALIDATION_FAILED', 'No such mail template.', ['field' => 'type']);
        }

        if ($to === '') {
            throw new ConflictException('NO_ADDRESS', 'Your account has no email address to send a test to.');
        }

        if (!$this->email->isLive()) {
            throw new ConflictException(
                'MAIL_NOT_CONFIGURED',
                'No mail leaves this deployment: MAIL_DSN is empty. Set it (and MAIL_FROM) in .env, then try again.',
            );
        }

        $payload = MailWording::sample($type, $to, $this->appUrl);
        $rendered = $this->wording->catalogue(Locale::of($locale));
        $subject = '';
        $body = '';

        foreach ($rendered as $template) {
            if ($template['type'] === $type) {
                $subject = MailWording::fill($template['subject'], $payload);
                $body = MailWording::fill($template['body'], $payload);
            }
        }

        try {
            $id = $this->email->send($to, '[Test] ' . $subject, $body, $payload);
        } catch (Throwable $failure) {
            throw new ConflictException(
                'MAIL_SEND_FAILED',
                'The mail host refused or could not be reached: ' . self::redacted($failure->getMessage()),
                ['error' => $failure::class],
            );
        }

        return ['to' => $to, 'subject' => $subject, 'provider_message_id' => $id];
    }

    /** The transport's sentence without anything that could be a secret. */
    private static function redacted(string $message): string
    {
        $line = trim((string) strtok($message, "\n"));
        $line = (string) preg_replace('~[a-z]+://[^\s]+~i', '[url]', $line);
        $line = (string) preg_replace('/\b[A-Za-z0-9+\/=]{24,}\b/', '[redacted]', $line);

        return mb_substr($line, 0, 300);
    }
}
