<?php

declare(strict_types=1);

namespace App\Privacy\Service;

use App\Privacy\Domain\ErasureOutcome;
use App\Privacy\Domain\ErasureRepository;
use App\Shared\Exceptions\ConflictException;

/**
 * Forgetting a person, without forgetting what the law requires kept
 * (non-negotiables #14 and #15).
 *
 * The whole difficulty of RGPD erasure in a billing platform is that "delete
 * everything about me" and "keep your accounts for as long as the law says"
 * are both obligations, and they overlap. The resolution is not to pick one:
 * the person stops being identifiable, and the records that must survive
 * survive — including, uncomfortably but correctly, the name written on an
 * invoice that was already issued. Altering that would falsify a legal
 * document, which is a worse answer than keeping it.
 *
 * **The erasure is itself audited.** It is a privileged act performed on
 * somebody else's data, and non-negotiable #21's reasoning applies: it is
 * never silent. The actor recorded is the administrator who carried it out,
 * not the person erased — whose actor rows are, in the same transaction,
 * being cleared.
 */
final class Erasure
{
    public function __construct(private readonly ErasureRepository $erasures)
    {
    }

    /**
     * @throws ConflictException when the person has already been forgotten
     */
    public function erase(string $subjectUserId, string $requestedByUserId, ?string $requestId): ErasureOutcome
    {
        if ($this->erasures->alreadyErased($subjectUserId)) {
            // Not an error to shrug at. A second run would write a second and
            // emptier record of the same act, and two accounts of one erasure
            // is worse evidence than one.
            throw new ConflictException(
                'ALREADY_ERASED',
                'This person has already been erased.',
            );
        }

        // The audit record is written by the adapter, inside the transaction
        // that does the erasing. Written here it would commit separately, and
        // a process that died in between would leave a person erased with no
        // record of who did it.
        return $this->erasures->erase($subjectUserId, $requestedByUserId, $requestId);
    }
}
