<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use App\Shared\Exceptions\UnprocessableEntityException;

/**
 * What a draft must satisfy before it goes on sale (ADR-079).
 *
 * Asked at publication because that is the last moment anything can be
 * changed: a published version is frozen (ADR-033) and every subscription
 * taken on it snapshots its terms, so a contradiction published is a
 * contradiction sold.
 *
 * One rule so far, and it is about a promise the terms make to themselves.
 * An `AUTO_RENEW` term rolls only once its customer was told `notice_days`
 * before the day the renewal is decided, and that day is a renewal lead before
 * the term ends (ADR-071). A term shorter than the notice plus the lead can
 * therefore **never** roll: the notice would have to be sent before the
 * subscription began. Such an offer says "renews by itself" and every
 * subscription on it ends at its first term instead — after a mail that
 * promised the opposite, until that mail was stopped too.
 *
 * Measured against the **longest** lead a product may choose, not the one it
 * has chosen today: the lead is an operator's setting and can move after the
 * version is frozen, and a version valid only while nobody touches a setting
 * is not one to freeze.
 */
final class PublicationRules
{
    public const TERM_CANNOT_RENEW = 'OFFER_TERM_CANNOT_RENEW';

    /**
     * @throws UnprocessableEntityException OFFER_TERM_CANNOT_RENEW
     */
    public static function assertPublishable(?OfferCandidate $offer, int $version): void
    {
        foreach ($offer->versions ?? [] as $candidate) {
            if ($candidate->version !== $version) {
                continue;
            }

            $terms = $candidate->terms;

            if (!$terms->termCanRoll(RenewalPolicy::LONGEST_LEAD)) {
                throw new UnprocessableEntityException(
                    self::TERM_CANNOT_RENEW,
                    'These terms renew by themselves but can never do so: the term is shorter than the notice it promises plus the time a renewal is decided ahead. Lengthen the term, shorten the notice, or sell it as ending at its term.',
                    [
                        'term_months' => $terms->termMonths,
                        'notice_days' => $terms->noticeDays,
                        'renewal_lead_days' => RenewalPolicy::LONGEST_LEAD,
                    ],
                );
            }
        }
    }
}
