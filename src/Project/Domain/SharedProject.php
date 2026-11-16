<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * What a share link opens onto (ADR-073): the link and the project it shows.
 *
 * Both whole, because the presenter is what decides how little of them a
 * stranger reads — the tenant, the holder, the description and the counters
 * are here and must not be there.
 */
final class SharedProject
{
    public function __construct(
        public readonly ProjectShare $share,
        public readonly Project $project,
    ) {
    }
}
