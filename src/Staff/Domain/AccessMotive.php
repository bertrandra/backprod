<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use App\Shared\Exceptions\UnprocessableEntityException;

/**
 * Why a staff member is about to cross a tenant boundary (R14).
 *
 * Non-negotiable #21 requires such an access to be *traced, motivated and never
 * silent*. Before this, the platform recorded the `permission` a read was made
 * under — which is the **authority** for it, the answer to "on what grounds?" —
 * and nothing about why *this* read happened. R14 was filed rather than papering
 * over that with a free-text box, and it stated the design problem exactly:
 *
 * > a mandatory free-text field collects "support" a thousand times and proves
 * > nothing, while a structured one is only as good as the system it points at.
 *
 * **So it is both.** `purpose` is a small enumeration, which is what makes the
 * log *countable*: how many reads were billing investigations last quarter,
 * whether one person opens tenants for "security review" three times a day.
 * `reference` is the specific thing — a ticket id, a sentence naming what is
 * being looked into — which is what makes any single row mean something.
 *
 * Neither is useful alone, so neither is optional.
 *
 * **Where a motive is required, and where it is not.** A read that reveals one
 * tenant's own data needs one: opening a customer, opening a support thread.
 * *Listing* does not — the contract is careful that a conversation's tenant
 * appears on the detail and not on the list, so skimming a queue crosses nothing
 * — and neither does reading the platform's own access log, which is not a
 * tenant's data at all. Requiring a reason to read the audit trail would make
 * the accountability mechanism the thing people avoid using.
 */
final class AccessMotive
{
    /**
     * The purposes a read can serve, as the log can count them.
     *
     * Short on purpose. A list long enough to describe every situation is a list
     * nobody reads to the end of, and the honest place for the specifics is the
     * reference beside it.
     *
     * @var list<string>
     */
    public const PURPOSES = [
        'SUPPORT_REQUEST',
        'BILLING_INVESTIGATION',
        'INCIDENT',
        'SECURITY_REVIEW',
        'LEGAL_REQUEST',
    ];

    private const MINIMUM_REFERENCE = 8;
    private const MAXIMUM_REFERENCE = 500;

    private function __construct(
        public readonly string $purpose,
        public readonly string $reference,
    ) {
    }

    /**
     * Builds one, or refuses.
     *
     * Refuses with `ACCESS_MOTIVE_REQUIRED` rather than a generic validation
     * failure, because the caller can act on it: the console knows to ask, and a
     * script hitting this endpoint learns what it forgot rather than guessing at
     * which field was wrong.
     */
    public static function from(?string $purpose, ?string $reference): self
    {
        $purpose = $purpose === null ? '' : trim($purpose);
        $reference = $reference === null ? '' : trim($reference);

        if ($purpose === '' || $reference === '') {
            throw new UnprocessableEntityException(
                'ACCESS_MOTIVE_REQUIRED',
                'A staff read across a tenant boundary must say why it is happening.',
                [
                    'purpose' => 'Required. One of: ' . implode(', ', self::PURPOSES) . '.',
                    'reference' => sprintf(
                        'Required. A ticket reference or a sentence, %d to %d characters.',
                        self::MINIMUM_REFERENCE,
                        self::MAXIMUM_REFERENCE,
                    ),
                ],
            );
        }

        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new UnprocessableEntityException(
                'ACCESS_MOTIVE_INVALID',
                'That is not a purpose this platform records.',
                ['purpose' => 'One of: ' . implode(', ', self::PURPOSES) . '.'],
            );
        }

        $length = mb_strlen($reference);

        if ($length < self::MINIMUM_REFERENCE || $length > self::MAXIMUM_REFERENCE) {
            // The floor is the point: "x" is not a reason, and a field that
            // accepted it would be a field that collects nothing while looking
            // like a control.
            throw new UnprocessableEntityException(
                'ACCESS_MOTIVE_INVALID',
                'The reference is too short to mean anything, or too long to read.',
                ['reference' => sprintf(
                    'Between %d and %d characters.',
                    self::MINIMUM_REFERENCE,
                    self::MAXIMUM_REFERENCE,
                )],
            );
        }

        return new self($purpose, $reference);
    }
}
