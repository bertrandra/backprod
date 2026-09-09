<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Domain\OfferDraft;
use App\Commerce\Domain\SubscriptionTerms;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use DateTimeImmutable;
use Exception;
use stdClass;

/**
 * The terms of a version, read off a request body.
 *
 * Shared by creating an offer and adding a version to one, because they carry
 * the same object. Two copies of this parsing would be two places for a term
 * added to §13.1 later to be accepted by one endpoint and dropped by the
 * other — and dropping a commitment silently is the failure that matters.
 *
 * The enumerations are checked here rather than left to the database. Both
 * would refuse, but a CHECK constraint refuses with the constraint's name,
 * and a caller who mistyped `MONTLY` is owed the list of what was expected.
 */
final class OfferDraftBody
{
    private const BILLING_PERIODS = ['MONTHLY', 'YEARLY', 'CUSTOM'];

    private const CANCELLATION = [
        SubscriptionTerms::ANYTIME,
        SubscriptionTerms::AT_COMMITMENT_END,
        SubscriptionTerms::AT_TERM,
    ];

    private const RENEWAL = [SubscriptionTerms::AUTO_RENEW, SubscriptionTerms::ENDS_AT_TERM];

    private const EARLY_TERMINATION = [
        SubscriptionTerms::FORBIDDEN,
        SubscriptionTerms::CHARGE_REMAINING,
        SubscriptionTerms::FREE,
    ];

    public static function read(JsonBody $body): OfferDraft
    {
        $period = self::oneOf($body->requiredString('billing_period', 16), 'billing_period', self::BILLING_PERIODS);

        $validFrom = $body->has('valid_from')
            ? self::moment($body->requiredString('valid_from', 40), 'valid_from')
            : new DateTimeImmutable();

        $validUntil = $body->has('valid_until') && $body->optionalNullableString('valid_until', 40) !== null
            ? self::moment((string) $body->optionalNullableString('valid_until', 40), 'valid_until')
            : null;

        // The database says the same thing, and would say it as
        // `offer_versions_window_ordered`. Saying it here names the two
        // fields the caller has to look at.
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw self::invalid('valid_until', 'must be after valid_from, or absent for an open window');
        }

        return new OfferDraft(
            $period,
            // 0 is a legitimate price: a free tier is still an offer.
            $body->requiredInt('price_minor_units', 0),
            self::currency($body->requiredString('currency', 3)),
            $validFrom,
            $validUntil,
            self::grants($body),
            self::terms($body),
        );
    }

    /**
     * @return array<string, int|null>
     */
    private static function grants(JsonBody $body): array
    {
        if (!$body->has('grants')) {
            return [];
        }

        $grants = [];

        foreach ($body->requiredObjectList('grants', 100) as $index => $grant) {
            $featureId = $grant->feature_id ?? null;

            if (!is_string($featureId) || trim($featureId) === '') {
                throw self::invalid(sprintf('grants[%d]', $index), 'must carry a "feature_id"');
            }

            $limit = $grant->limit ?? null;

            if ($limit !== null && (!is_int($limit) || $limit < 0)) {
                // Null is meaningful — unlimited for a quota, and the only
                // valid value for a boolean — so it is not an error, and a
                // negative allowance is not a smaller one.
                throw self::invalid(
                    sprintf('grants[%d].limit', $index),
                    'must be a whole number of at least zero, or null for unlimited',
                );
            }

            $grants[trim($featureId)] = $limit;
        }

        return $grants;
    }

    private static function terms(JsonBody $body): SubscriptionTerms
    {
        if (!$body->has('terms')) {
            // §13.1's own default, stated rather than implied: month to
            // month, no commitment, cancel whenever.
            return SubscriptionTerms::openEnded();
        }

        $terms = $body->requiredObject('terms');

        $termMonths = self::wholeMonths($terms, 'term_months');
        $commitment = self::wholeMonths($terms, 'commitment_months') ?? 0;

        if ($termMonths !== null && $commitment > $termMonths) {
            throw self::invalid(
                'terms.commitment_months',
                'must not exceed terms.term_months; a commitment longer than the contract is not a commitment',
            );
        }

        return new SubscriptionTerms(
            $termMonths,
            $commitment,
            self::enumOf($terms, 'cancellation_policy', self::CANCELLATION, SubscriptionTerms::ANYTIME),
            self::enumOf($terms, 'renewal', self::RENEWAL, SubscriptionTerms::AUTO_RENEW),
            self::enumOf($terms, 'early_termination', self::EARLY_TERMINATION, SubscriptionTerms::FORBIDDEN),
            self::wholeMonths($terms, 'notice_days') ?? 0,
        );
    }

    private static function wholeMonths(stdClass $terms, string $field): ?int
    {
        $value = $terms->{$field} ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) || $value < 0) {
            throw self::invalid('terms.' . $field, 'must be a whole number of at least zero');
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function enumOf(stdClass $terms, string $field, array $allowed, string $default): string
    {
        $value = $terms->{$field} ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw self::invalid('terms.' . $field, 'must be one of ' . implode(', ', $allowed));
        }

        return self::oneOf($value, 'terms.' . $field, $allowed);
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $value, string $field, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw self::invalid($field, 'must be one of ' . implode(', ', $allowed));
        }

        return $value;
    }

    private static function currency(string $value): string
    {
        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw self::invalid('currency', 'must be a three-letter ISO 4217 code in upper case');
        }

        return $value;
    }

    private static function moment(string $value, string $field): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw self::invalid($field, 'must be a date and time this platform can read, such as RFC 3339');
        }
    }

    private static function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }

}
