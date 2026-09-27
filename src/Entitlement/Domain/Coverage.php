<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

/**
 * Whether a subscription covers this person, and when it does not, **why not**
 * (2026-09-27, spec §5.1).
 *
 * This was a `bool` until today, and the boolean was the whole of the problem.
 * Coverage fails for two reasons that are fixed by two different people:
 *
 * ```text
 * NONE          the organisation has a subscription and you are not on it
 *               → a colleague gives you a place
 * IN_ARREARS    you are on one, and an invoice against it is unpaid
 *               → somebody changes a card
 * ```
 *
 * Answered with one word, the second becomes the first: the refusal says "you
 * are not one of the people this subscription covers", so the holder goes and
 * asks a colleague for a seat they are already holding, and the invoice stays
 * unpaid while they wait. That is not a wording problem to fix in the frontend
 * — the frontend is never the authority, and a screen cannot tell two
 * identical codes apart either.
 *
 * So the distinction is minted here, where the question is answered, and
 * `RequestContext::requireSubscription()` raises a different code for each.
 *
 * **`COVERED` wins over `IN_ARREARS`.** A person may be reached by more than
 * one subscription — their own seat and their organisation's — and one of them
 * being owed for must not shut a workshop the other pays for. The most
 * generous answer wins, which is the same rule that settles two entitlements
 * granting one feature.
 */
enum Coverage
{
    /** On a live subscription. The ordinary answer. */
    case COVERED;

    /**
     * On a subscription, and it is suspended for non-payment (§5.1). What is
     * suspended is the entitlements, never the documents: the customer keeps
     * reaching their invoices and payments, or the door of the screen they
     * came to pay at would be the one shut.
     */
    case IN_ARREARS;

    /** On no subscription for this product at all. */
    case NONE;

    public function covers(): bool
    {
        return $this === self::COVERED;
    }

    /**
     * What the database answered, in the words the SQL uses.
     *
     * Unknown reads as `NONE` rather than raising: this runs inside the §10.6
     * chain on every authenticated request, and the direction to fail in is
     * closed.
     */
    public static function fromName(string $name): self
    {
        return match ($name) {
            'COVERED' => self::COVERED,
            'IN_ARREARS' => self::IN_ARREARS,
            default => self::NONE,
        };
    }
}
