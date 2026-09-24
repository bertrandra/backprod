<?php

declare(strict_types=1);

namespace App\Navigation\Domain;

use InvalidArgumentException;

/**
 * The platform's menu setup: one {@see AudienceMenu} per {@see Audience}.
 *
 * Platform-wide, not per product, by the operator's decision on 2026-09-17:
 * the console's menu is the same console whichever product is chosen, and
 * a customer's menu is a fact about the kind of person they are rather than
 * about what they bought. What varies per product comes from the product's
 * configuration; what varies per customer comes from entitlements; this is
 * neither — it is the operator deciding what the platform shows.
 *
 * A setup is complete by construction: every audience has a menu, defaulting
 * to everything, so a reader never has to ask whether an audience was set.
 */
final class NavigationSetup
{
    /**
     * @param array<string, AudienceMenu> $menus keyed by audience
     */
    private function __construct(private readonly array $menus)
    {
    }

    /**
     * What a platform that has never opened the Menus screen shows.
     *
     * Called `everything()` until 2026-09-24, and that stopped being true
     * when a member's menu started leaving out the screens with nothing on
     * them ({@see AudienceMenu::initialFor()}). A factory whose name
     * promises more than it returns is how a reader stops checking.
     */
    public static function initial(): self
    {
        return self::of([]);
    }

    /**
     * @param array<string, AudienceMenu> $menus any subset of the audiences
     */
    public static function of(array $menus): self
    {
        $complete = [];

        foreach (Audience::all() as $audience) {
            // Not `everything()`: an audience nobody has set up gets the
            // default for *that* audience, which for a member leaves out the
            // screens that have nothing on them yet.
            $complete[$audience] = $menus[$audience] ?? AudienceMenu::initialFor($audience);
        }

        foreach (array_keys($menus) as $audience) {
            if (!Audience::isKnown((string) $audience)) {
                throw new InvalidArgumentException('Not an audience: ' . $audience);
            }
        }

        return new self($complete);
    }

    public function for(string $audience): AudienceMenu
    {
        return $this->menus[$audience] ?? AudienceMenu::initialFor($audience);
    }

    /** @return array<string, array{hidden: list<string>, hide_empty: bool}> */
    public function toArray(): array
    {
        $out = [];

        foreach (Audience::all() as $audience) {
            $out[$audience] = $this->for($audience)->toArray();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $value a stored or submitted document
     */
    public static function fromArray(array $value): self
    {
        $menus = [];

        foreach ($value as $audience => $menu) {
            if (!is_string($audience) || !Audience::isKnown($audience)) {
                throw new InvalidArgumentException('Not an audience: ' . (string) $audience);
            }

            if (!is_array($menu)) {
                throw new InvalidArgumentException('An audience\'s menu is an object.');
            }

            /** @var array<string, mixed> $menu */
            $menus[$audience] = AudienceMenu::fromArray($menu);
        }

        return self::of($menus);
    }
}
