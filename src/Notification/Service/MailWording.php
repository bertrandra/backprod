<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Notification\Domain\MailTemplates;
use App\Notification\Domain\Notification;

/**
 * What the notices that leave the platform say (2026-09-19).
 *
 * The generic rendering — the type as a subject, the payload as lines — is
 * enough for a screen and for a log. A mail is read by somebody who has no
 * screen in front of them: a reset link with "purpose: RESET" under it is
 * not a sentence they can act on. So the handful of types a person has to
 * act on from their inbox get words of their own, and since 2026-09-19 the
 * platform administrator may change them (Console → Setup → Mail): an
 * override per type on top of the defaults here, with `{link}`, `{email}`
 * and any other scalar of the payload as placeholders.
 *
 * English by default, because the platform's screens are; the editor is
 * where another language goes.
 */
final class MailWording
{
    /**
     * The types with words of their own, their defaults, and what each may
     * say with a placeholder.
     *
     * @var array<string, array{subject: string, body: string, placeholders: list<string>, about: string}>
     */
    public const DEFAULTS = [
        'account.password_reset' => [
            'about' => 'Somebody asked to set a new password. The link works once, for thirty minutes.',
            'placeholders' => ['link', 'email'],
            'subject' => 'Set a new password',
            'body' => "Somebody — we hope you — asked to set a new password for this account.\n\n"
                . "Open this link to choose one; it works once and for thirty minutes:\n{link}\n\n"
                . 'If you did not ask, ignore this mail: nothing changes until the link is used.',
        ],
        'account.invitation' => [
            'about' => 'Somebody was added to a subscription by address and has no password yet. The link works once, for seven days.',
            'placeholders' => ['link', 'email'],
            'subject' => 'You have been added — choose your password',
            'body' => "You have been given access, and an account was made for this address.\n\n"
                . "Open this link to choose your password and sign in; it works once and for seven days:\n{link}\n\n"
                . 'If you were not expecting this, you can ignore it.',
        ],
        'account.password_changed' => [
            'about' => 'A password was just set from a link, and every earlier session was signed out.',
            'placeholders' => ['email'],
            'subject' => 'Your password was changed',
            'body' => 'The password for this account was just set through a link sent to this address, and every '
                . "earlier session was signed out.\n\n"
                . 'If that was not you, ask for a new link straight away from the sign-in page.',
        ],
        'account.email_verification' => [
            'about' => 'A sign-up asks the person to confirm their address.',
            'placeholders' => ['link', 'email'],
            'subject' => 'Confirm your email address',
            'body' => "Thanks for signing up. Open this link to confirm this address is yours:\n{link}\n\n"
                . 'If you did not sign up, ignore this mail.',
        ],
    ];

    public function __construct(private readonly MailTemplates $templates)
    {
    }

    /**
     * @return array{string, string}|null subject and body, or null for a type with no words of its own
     */
    public function for(Notification $notification): ?array
    {
        $template = $this->templateFor($notification->type);

        if ($template === null) {
            return null;
        }

        return [
            self::fill($template['subject'], $notification->payload),
            self::fill($template['body'], $notification->payload),
        ];
    }

    /**
     * Every editable type: its default, what stands today, and its placeholders.
     *
     * @return list<array{type: string, about: string, placeholders: list<string>, default: array{subject: string, body: string}, subject: string, body: string, customised: bool}>
     */
    public function catalogue(): array
    {
        $overrides = $this->templates->overrides();
        $rows = [];

        foreach (self::DEFAULTS as $type => $default) {
            $override = $overrides[$type] ?? null;

            $rows[] = [
                'type' => $type,
                'about' => $default['about'],
                'placeholders' => $default['placeholders'],
                'default' => ['subject' => $default['subject'], 'body' => $default['body']],
                'subject' => $override['subject'] ?? $default['subject'],
                'body' => $override['body'] ?? $default['body'],
                'customised' => $override !== null,
            ];
        }

        return $rows;
    }

    /**
     * Saves the words for the types given; a type left out, or given empty,
     * goes back to its default. Unknown types are refused by omission.
     *
     * @param array<string, array{subject: string, body: string}> $templates
     */
    public function save(array $templates): void
    {
        $overrides = [];

        foreach ($templates as $type => $template) {
            if (!isset(self::DEFAULTS[$type])) {
                continue;
            }

            $subject = trim($template['subject']);
            $body = trim($template['body']);

            if ($subject === '' && $body === '') {
                continue;
            }

            $overrides[$type] = [
                'subject' => $subject === '' ? self::DEFAULTS[$type]['subject'] : $subject,
                'body' => $body === '' ? self::DEFAULTS[$type]['body'] : $body,
            ];
        }

        $this->templates->save($overrides);
    }

    /**
     * A sample payload for a type, so a test mail reads as the real one would.
     *
     * @return array<string, mixed>
     */
    public static function sample(string $type, string $email, string $appUrl): array
    {
        return [
            'link' => rtrim($appUrl, '/') . '/sign-in?reset=SAMPLE-TOKEN',
            'email' => $email,
            'purpose' => $type === 'account.invitation' ? 'INVITATION' : 'RESET',
        ];
    }

    /**
     * @return array{subject: string, body: string}|null
     */
    private function templateFor(string $type): ?array
    {
        $default = self::DEFAULTS[$type] ?? null;

        if ($default === null) {
            return null;
        }

        $override = $this->templates->overrides()[$type] ?? null;

        return [
            'subject' => $override['subject'] ?? $default['subject'],
            'body' => $override['body'] ?? $default['body'],
        ];
    }

    /**
     * `{key}` becomes the payload's scalar for that key; an unknown key stays
     * as written, which is what tells an editor they misspelt one.
     *
     * @param array<string, mixed> $payload
     */
    public static function fill(string $text, array $payload): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $match) use ($payload): string {
                $value = $payload[$match[1]] ?? null;

                return is_scalar($value) ? (string) $value : $match[0];
            },
            $text,
        );
    }
}
