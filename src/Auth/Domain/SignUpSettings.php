<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Whether a new account must prove its address before it may use the
 * platform (2026-09-28, ADR-063), decided once for the whole platform.
 *
 * ADR-061 gave every self-service sign-up seven days to follow the link
 * mailed to it, and made the tenant surface answer `EMAIL_UNCONFIRMED` past
 * that deadline. The operator's answer, on seeing it work: most deployments
 * do not want it. So it becomes a switch, and the switch is **off**.
 *
 * **Absent means off**, and the reason is particular to this setting rather
 * than borrowed from another. `FreemiumPeriod` fails closed because guessing
 * gives something away — a free month nobody sold. `DunningSchedule` falls
 * back to a default because failing closed would mean never chasing a debt.
 * Neither applies here: what silence would cost is a **person locked out of
 * something they have paid for**, by a deadline nobody chose to impose,
 * because a row was never written. Refusing is the expensive direction and
 * asking is the recoverable one — an operator who wants the proof switches
 * it on and says so, and everybody who signs up afterwards is told. So the
 * absence of a decision is the absence of the demand.
 */
interface SignUpSettings
{
    public function emailConfirmationRequired(): bool;

    public function requireEmailConfirmation(bool $required): void;
}
