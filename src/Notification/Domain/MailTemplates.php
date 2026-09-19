<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * The words the platform's mails say, as the platform administrator set
 * them (2026-09-19) — overrides on top of the defaults the code carries.
 * A type absent here says its default; a type saved with an empty subject
 * and body goes back to it.
 */
interface MailTemplates
{
    /**
     * @return array<string, array<string, array{subject: string, body: string}>> by locale, then by notification type
     */
    public function overrides(): array;

    /**
     * @param array<string, array<string, array{subject: string, body: string}>> $overrides the whole set; what is absent is reset
     */
    public function save(array $overrides): void;
}
