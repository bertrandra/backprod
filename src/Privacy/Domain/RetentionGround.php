<?php

declare(strict_types=1);

namespace App\Privacy\Domain;

/**
 * Why something survives an erasure request (non-negotiable #15).
 *
 * #15 says legal retention prevails where the law requires keeping a piece of
 * data. That is a *reason*, and a reason has to be recorded or the platform
 * cannot answer the only question that matters afterwards: not "did you
 * delete everything?" — it did not, deliberately — but "what did you keep,
 * and on what ground?"
 *
 * Grounds rather than durations. The durations are the part this codebase
 * cannot honestly assert: the retention periods in French accounting and
 * fiscal law were never read from a primary source here, for the same reason
 * R3, R7 and R11 stand open — every `legifrance`, `gouv.fr` and `europa.eu`
 * domain is refused by this environment's egress. So what is recorded is why
 * a category is kept, which is stable, rather than a number of years, which
 * would look authoritative and be unverified.
 */
final class RetentionGround
{
    /**
     * An invoice is a legal document. Its customer snapshot names the person
     * because that is what the document says, and altering it after issue
     * would falsify it — which is the opposite of what accounting law asks.
     * A credit note corrects an invoice; erasing either breaks the pair.
     */
    public const ACCOUNTING = 'accounting_record';

    /**
     * Tax records, VAT transactions and closed reporting periods. Declared
     * figures cannot be restated because somebody asked to be forgotten; a
     * closed period is one-way for exactly this reason.
     */
    public const FISCAL = 'fiscal_record';

    /**
     * §30's trail, and non-negotiable #21: staff access to a tenant's data is
     * never silent. The entry survives with its actor forgotten — what
     * happened is kept, who did it is not.
     */
    public const AUDIT = 'audit_trail';

    /**
     * A notice with legal effect, kept as it was sent. The pre-renewal notice
     * §13.1 requires is evidence that an obligation was met, and evidence
     * that can be erased by the person it was sent to is not evidence.
     */
    public const LEGAL_NOTICE = 'legal_notice_given';

    /**
     * The commercial chain of non-negotiable #20: quote → order →
     * subscription → invoice → payment must stay traceable. The person's
     * identity goes; their place in the chain stays, as an id nobody can
     * resolve to a name.
     */
    public const TRACEABILITY = 'commercial_traceability';
}
