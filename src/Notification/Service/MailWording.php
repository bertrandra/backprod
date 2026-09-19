<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Notification\Domain\Notification;

/**
 * What the notices that leave the platform say (2026-09-19).
 *
 * The generic rendering — the type as a subject, the payload as lines — is
 * enough for a screen and for a log. A mail is read by somebody who has no
 * screen in front of them: a reset link with "purpose: RESET" under it is
 * not a sentence they can act on. So the handful of types a person has to
 * act on from their inbox get words of their own; everything else keeps the
 * generic form, which is honest about being a system notice.
 *
 * English, because the platform's screens are; a locale catalogue is the
 * frontend's to own, and this stays small enough to move there when it
 * exists. The link is never rewritten here — it is what the service built.
 */
final class MailWording
{
    /**
     * @return array{string, string}|null subject and body, or null for a type with no words of its own
     */
    public static function for(Notification $notification): ?array
    {
        $payload = $notification->payload;
        $link = is_string($payload['link'] ?? null) ? $payload['link'] : null;

        return match ($notification->type) {
            'account.password_reset' => [
                'Set a new password',
                "Somebody — we hope you — asked to set a new password for this account.\n\n"
                . "Open this link to choose one; it works once and for thirty minutes:\n"
                . ($link ?? '(no link)') . "\n\n"
                . 'If you did not ask, ignore this mail: nothing changes until the link is used.',
            ],
            'account.invitation' => [
                'You have been added — choose your password',
                "You have been given access, and an account was made for this address.\n\n"
                . "Open this link to choose your password and sign in; it works once and for seven days:\n"
                . ($link ?? '(no link)') . "\n\n"
                . 'If you were not expecting this, you can ignore it.',
            ],
            'account.password_changed' => [
                'Your password was changed',
                'The password for this account was just set through a link sent to this address, and every '
                . "earlier session was signed out.\n\n"
                . 'If that was not you, ask for a new link straight away from the sign-in page.',
            ],
            'account.email_verification' => [
                'Confirm your email address',
                "Thanks for signing up. Open this link to confirm this address is yours:\n"
                . ($link ?? '(no link)') . "\n\n"
                . 'If you did not sign up, ignore this mail.',
            ],
            default => null,
        };
    }
}
