<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class ForbiddenException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $code, string $message, array $details = [])
    {
        parent::__construct(403, $code, $message, $details);
    }

    public static function noTenantAccess(): self
    {
        return new self(
            'NO_TENANT_ACCESS',
            'This account has no access to the requested product.',
        );
    }

    /**
     * The caller's role does not allow this. Distinct from an entitlement
     * failure: this is about who they are in the tenant, not what the tenant
     * has bought, and the two are fixed in completely different places.
     */
    public static function permissionDenied(string $permission): self
    {
        return new self(
            'PERMISSION_DENIED',
            'Your role does not allow this action.',
            ['permission' => $permission],
        );
    }

    /**
     * The capability name is safe to return: it tells the caller what to buy
     * or request, and discloses nothing about other tenants.
     */
    public static function entitlementRequired(string $capability): self
    {
        return new self(
            'ENTITLEMENT_REQUIRED',
            'This feature is not enabled for the tenant.',
            ['capability' => $capability],
        );
    }

    /**
     * The caller is a member of the organisation and is not on a
     * subscription for this product (2026-09-25).
     *
     * A **fifth** refusal, and it is fixed in a fifth place. The four others
     * are answered by a platform administrator, a tenant administrator, a
     * purchase or a deletion; this one is answered by whoever owns the
     * subscription putting this person on it — within the number of people
     * their offer sells.
     *
     * Distinct from `ENTITLEMENT_REQUIRED` on purpose, and the difference is
     * the whole point of the rule: that one says the *organisation* never
     * bought the feature, this one says it did and **you are not one of the
     * people it covers**. Collapsing them would tell somebody to buy
     * something their colleague is already paying for.
     *
     * Nothing about the subscription is returned — not its offer, not who
     * owns it, not how many seats are left. A refusal is a poor place to
     * publish a price.
     *
     * That is discretion in *this* answer and not a secret being kept: a
     * member of the organisation reads the whole subscription from
     * `GET /subscription`, which their role allows and coverage does not
     * gate. An earlier draft of this note claimed they "have not been told
     * it exists", which was never true — checked on 2026-09-25 by joining
     * Acme through the storefront and reading it back. Whether that read
     * should narrow is a question about what a member may know about their
     * own organisation, and it is not answered here.
     */
    public static function subscriptionRequired(): self
    {
        return new self(
            'SUBSCRIPTION_REQUIRED',
            'You are not one of the people this subscription covers.',
        );
    }

    /**
     * The caller is on a subscription and an invoice against it is unpaid
     * (2026-09-27, spec §5.1).
     *
     * A **sixth** refusal, and it exists because of what the fifth one says.
     * `SUBSCRIPTION_REQUIRED` means "your organisation has one and it does not
     * cover you", which is answered by a colleague giving you a place. This one
     * is answered by a card. Raised as the same code with the same wording,
     * somebody goes and asks a colleague for a seat they are already holding —
     * and the invoice stays unpaid while they wait, which is the one outcome
     * the whole of §5 exists to avoid.
     *
     * So the two are told apart **here**, where the refusal is minted, and not
     * in a screen: the frontend is never the authority, and two identical codes
     * are not something a screen can tell apart either.
     *
     * Nothing about the debt travels with the refusal — not the amount, not
     * the document, not what the provider said. A refusal is a poor place to
     * publish a price, §31 keeps a provider's words out of responses, and the
     * remedy is already addressable: `GET /subscription` returns
     * `past_due_since` and `past_due_invoice_id`, coverage does not gate it,
     * and that is where the screen gets the way to pay. The code's job is to
     * send the reader to the right screen, not to be the screen.
     */
    public static function subscriptionPastDue(): self
    {
        return new self(
            'SUBSCRIPTION_PAST_DUE',
            'This subscription is suspended because an invoice for it has not been paid.',
        );
    }

    /**
     * The address this person signed up with is still unproved, past the
     * deadline they were given (ADR-061).
     *
     * Its own refusal, answered by a click in their mailbox — never by a
     * colleague, an administrator or a card. Folded into
     * `PERMISSION_DENIED`, the person would go and ask for a role they
     * already hold. The remedy is reachable while refused: resending the
     * link and following it are identity-only and public respectively, and
     * `GET /products` says when the deadline was.
     */
    public static function emailUnconfirmed(): self
    {
        return new self(
            'EMAIL_UNCONFIRMED',
            'Confirm your email address to continue: follow the link we sent you, or ask for a new one.',
        );
    }

    /**
     * The tenant has the feature and has used all of it.
     *
     * A fourth refusal, distinct from the three above because it is fixed in
     * a fourth place. ENTITLEMENT_REQUIRED says "you did not buy this";
     * this says "you did, and there is none left" — which is answered by
     * upgrading or by deleting something, not by changing a role or a plan.
     *
     * The limit and the usage are both returned: a client told only that it
     * is over quota cannot show its user how far over, or how much deleting
     * one thing would help.
     */
    public static function quotaExceeded(string $capability, int $limit, int $used): self
    {
        return new self(
            'QUOTA_EXCEEDED',
            'The tenant has used all of this allowance.',
            ['capability' => $capability, 'limit' => $limit, 'used' => $used],
        );
    }
}
