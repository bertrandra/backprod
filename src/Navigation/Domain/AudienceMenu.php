<?php

declare(strict_types=1);

namespace App\Navigation\Domain;

use InvalidArgumentException;

/**
 * What one audience's menu leaves out.
 *
 * **Hidden, not shown.** The setup stores the entries switched *off* rather
 * than the ones switched on, so an entry added to the shell tomorrow appears
 * for everybody until somebody decides otherwise — a whitelist would hide
 * every new screen by default, silently, on every deployment. Empty means
 * the whole menu the person's permissions allow.
 *
 * The ids are the shell's own (`navigation.ts`): the platform stores them
 * as words and never interprets one, except in {@see NavigationProbe},
 * which knows which of them name a list that can be empty.
 *
 * `hideEmpty` is the second option the operator asked for on 2026-09-17: an
 * entry whose screen has nothing to show yet is left out too — an Invoices
 * entry before the first invoice exists — decided per audience, because a
 * platform administrator wants to see an empty queue and a member does not.
 */
final class AudienceMenu
{
    public const MAX_HIDDEN = 100;

    /**
     * @param list<string> $hidden entry ids switched off for this audience
     */
    public function __construct(
        public readonly array $hidden = [],
        public readonly bool $hideEmpty = false,
    ) {
        if (count($hidden) > self::MAX_HIDDEN) {
            throw new InvalidArgumentException('Too many entries hidden.');
        }

        foreach ($hidden as $id) {
            if (!self::isEntryId($id)) {
                throw new InvalidArgumentException('Not a menu entry id: ' . $id);
            }
        }
    }

    public static function everything(): self
    {
        return new self();
    }

    /**
     * An entry id as the shell writes them: lowercase words joined by
     * hyphens. Checked here so a setup body cannot smuggle markup or a
     * kilobyte into a list the shell renders from.
     */
    public static function isEntryId(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{0,40}$/', $value) === 1;
    }

    public function hides(string $entryId): bool
    {
        return in_array($entryId, $this->hidden, true);
    }

    /** @return array{hidden: list<string>, hide_empty: bool} */
    public function toArray(): array
    {
        return ['hidden' => array_values(array_unique($this->hidden)), 'hide_empty' => $this->hideEmpty];
    }

    /**
     * @param array<string, mixed> $value a stored or submitted document
     */
    public static function fromArray(array $value): self
    {
        $hidden = $value['hidden'] ?? [];
        $hideEmpty = $value['hide_empty'] ?? false;

        if (!is_array($hidden) || !is_bool($hideEmpty)) {
            throw new InvalidArgumentException('A menu is a list of hidden entries and a flag.');
        }

        $ids = [];

        foreach ($hidden as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException('A hidden entry is named by id.');
            }

            $ids[] = $id;
        }

        return new self(array_values(array_unique($ids)), $hideEmpty);
    }
}
