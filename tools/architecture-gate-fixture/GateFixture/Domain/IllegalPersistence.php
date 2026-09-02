<?php

declare(strict_types=1);

namespace App\GateFixture\Domain;

use PDO;

/**
 * DELIBERATELY ILLEGAL — this file must never be imitated.
 *
 * Architecture V2 §37.6 bans Domain → SQL. This fixture commits exactly that
 * violation so tools/prove-architecture-gate.php can demonstrate that Deptrac
 * really rejects it. It lives outside ./src and outside the Composer
 * autoloader, so it is invisible to the application and to the CI
 * architecture check that guards real code.
 *
 * If the proof script ever reports this file as *passing*, the architecture
 * gate has stopped working — that is the whole point of keeping it.
 */
final class IllegalPersistence
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findName(string $id): ?string
    {
        $statement = $this->connection->prepare('SELECT name FROM things WHERE id = :id');
        $statement->execute(['id' => $id]);

        $name = $statement->fetchColumn();

        return is_string($name) ? $name : null;
    }
}
