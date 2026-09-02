<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * A validated partial update.
 *
 * PATCH means "change what I mention", so absence and null are different
 * things and cannot both be expressed by a nullable field. Description is the
 * one where it bites — null is a real value there, meaning "clear it" — so it
 * carries an explicit flag rather than being guessed at.
 *
 * A name cannot be null-and-provided: a blank name is refused at the
 * boundary, so null there unambiguously means "not mentioned".
 */
final class ProjectChanges
{
    private function __construct(
        public readonly ?string $name,
        public readonly bool $descriptionProvided,
        public readonly ?string $description,
        public readonly ?int $schemaVersion,
        public readonly ?object $document,
    ) {
    }

    public static function of(
        ?string $name,
        bool $descriptionProvided,
        ?string $description,
        ?int $schemaVersion,
        ?object $document,
    ): self {
        return new self($name, $descriptionProvided, $description, $schemaVersion, $document);
    }

    /**
     * Whether this would change anything at all. An empty PATCH is a caller
     * mistake worth naming rather than a silent no-op that returns 200 and
     * leaves them wondering why nothing happened.
     */
    public function isEmpty(): bool
    {
        return $this->name === null
            && !$this->descriptionProvided
            && $this->schemaVersion === null
            && $this->document === null;
    }
}
