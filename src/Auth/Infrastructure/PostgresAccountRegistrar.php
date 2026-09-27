<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Domain\JoinDecision;
use App\Auth\Domain\RegisteredAccount;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Validation\Locale;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SensitiveParameter;

/**
 * The six rows a new account is, written together.
 *
 * The same sequence `deploy/siteground/setup.php` performs once for the first
 * account — user, credential, tenant, the product assigned to it, membership,
 * role — with two differences,
 * and both are the point of the storefront: the product already exists rather
 * than being created, and nobody is made a platform administrator. A person
 * who bought a subscription administers their own organisation and nothing
 * else.
 */
final class PostgresAccountRegistrar implements AccountRegistrar
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function emailIsTaken(string $email): bool
    {
        // Against `users`, not `local_credentials`: an account created by an
        // administrator inviting a colleague has a users row and no credential
        // yet, and telling that person "the address is free" would let a
        // stranger take over their invitation by signing up as them.
        //
        // Erased accounts are excluded. §15 keeps the row for retention, and
        // refusing the address forever would make erasure a way to burn an
        // email address permanently.
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM users
                     WHERE lower(email) = lower(:email) AND erased_at IS NULL
                )
                SQL,
            ['email' => $email],
        );
    }

    public function join(
        string $email,
        #[SensitiveParameter] string $passwordHash,
        ?string $displayName,
        string $tenantSlug,
        ?string $productCode,
        ?string $locale = null,
        int $confirmWithinSeconds = 0,
    ): RegisteredAccount {
        $tenant = $this->connection->fetchAssociative(
            'SELECT id, slug, join_policy FROM tenants WHERE slug = :slug',
            ['slug' => $tenantSlug],
        );

        if ($tenant === false) {
            throw new NotFoundException('No such organisation.', [], 'TENANT_NOT_FOUND');
        }

        $tenantId = Row::string($tenant, 'id');
        $domains = $this->connection->fetchFirstColumn(
            'SELECT domain FROM tenant_join_domains WHERE tenant_id = :tenant',
            ['tenant' => $tenantId],
        );

        // Decided before anything is written: a refusal leaves no account
        // behind, so a stranger refused at one organisation has not quietly
        // acquired an account on the platform.
        $status = JoinDecision::statusFor(
            Row::string($tenant, 'join_policy'),
            array_values(array_map(static fn (mixed $d): string => strtolower(is_string($d) ? $d : ''), $domains)),
            $email,
        );

        // In the order the platform shows products (2026-09-24), not the
        // alphabet's: `$products[0]` below becomes somebody's default, and
        // until today that meant whichever code sorted first. An operator
        // who had put Plan at the top of every list watched a person who
        // signed up on a Plan offer land on Atlas.
        $products = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT tp.product_id
                  FROM tenant_products tp
                  JOIN products p ON p.id = tp.product_id
                 WHERE tp.tenant_id = :tenant AND p.active
                 ORDER BY p.display_order, p.code
                SQL,
            ['tenant' => $tenantId],
        );

        if ($products === []) {
            throw new NotFoundException('This organisation holds no product to join yet.', [], 'TENANT_HOLDS_NOTHING');
        }

        // The default product: the one they arrived through when the
        // organisation holds it, else the first it holds. Never a refusal —
        // the person came to join the organisation, not a product.
        $firstProduct = $productCode === null ? null : $this->connection->fetchOne(
            'SELECT id FROM products WHERE code = :code AND active',
            ['code' => $productCode],
        );
        $held = array_flip(array_values(array_filter($products, 'is_string')));
        if (!is_string($firstProduct) || !isset($held[$firstProduct])) {
            $firstProduct = $products[0];
        }

        if (!is_string($firstProduct)) {
            throw new NotFoundException('This organisation holds no product to join yet.', [], 'TENANT_HOLDS_NOTHING');
        }

        $this->connection->beginTransaction();

        try {
            $userId = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO users (auth_subject, email, display_name, default_product_id, locale, email_confirm_by)
                    VALUES ('pending', :email, :name, :product, :locale,
                            CASE WHEN :within > 0 THEN now() + make_interval(secs => :within) END)
                    RETURNING id
                    SQL,
                [
                    'email' => $email,
                    'name' => $displayName,
                    'product' => $firstProduct,
                    'locale' => Locale::of($locale),
                    // The deadline to prove the address (ADR-061), counted by
                    // the clock that will later compare against it.
                    'within' => $confirmWithinSeconds,
                ],
            );

            if (!is_string($userId)) {
                throw new ConflictException('EMAIL_TAKEN', 'That address already has an account.');
            }

            $this->connection->executeStatement(
                "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
                ['id' => $userId],
            );

            $this->connection->executeStatement(
                'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
                ['id' => $userId, 'email' => $email, 'hash' => $passwordHash],
            );

            // A member of the organisation, on every product it holds
            // (ADR-047), as a USER — never an administrator, whatever the
            // organisation is (point 4 of 2026-09-17).
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_members (tenant_id, product_id, user_id, status)
                    SELECT tp.tenant_id, tp.product_id, :user, :status
                      FROM tenant_products tp
                     WHERE tp.tenant_id = :tenant
                    SQL,
                ['tenant' => $tenantId, 'user' => $userId, 'status' => $status],
            );

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id)
                    SELECT tp.tenant_id, tp.product_id, :user, r.id
                      FROM tenant_products tp
                      CROSS JOIN roles r
                     WHERE tp.tenant_id = :tenant AND r.code = 'USER'
                    SQL,
                ['tenant' => $tenantId, 'user' => $userId],
            );

            $this->connection->commit();

            return new RegisteredAccount($userId, 'local:' . $userId, $email, $tenantId, $firstProduct, Row::string($tenant, 'slug'), $status);
        } catch (UniqueConstraintViolationException $collision) {
            $this->connection->rollBack();

            throw new ConflictException(
                'EMAIL_TAKEN',
                'That address already has an account.',
                [],
            );
        } catch (\Throwable $failure) {
            $this->connection->rollBack();

            throw $failure;
        }
    }

    public function issueVerification(string $userId, string $tokenHash, int $lifetimeSeconds): void
    {
        $this->connection->executeStatement(
            'DELETE FROM email_verifications WHERE user_id = :user',
            ['user' => $userId],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO email_verifications (user_id, token_hash, expires_at)
                VALUES (:user, :hash, now() + make_interval(secs => :ttl))
                SQL,
            ['user' => $userId, 'hash' => $tokenHash, 'ttl' => $lifetimeSeconds],
        );
    }

    public function verificationIssuedWithin(string $userId, int $seconds): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM email_verifications
                     WHERE user_id = :user AND created_at > now() - make_interval(secs => :within)
                )
                SQL,
            ['user' => $userId, 'within' => $seconds],
        );
    }

    public function issuePasswordLink(string $userId, string $tokenHash, int $lifetimeSeconds, string $purpose): void
    {
        $this->connection->executeStatement(
            'DELETE FROM password_resets WHERE user_id = :user',
            ['user' => $userId],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO password_resets (user_id, purpose, token_hash, expires_at)
                VALUES (:user, :purpose, :hash, now() + make_interval(secs => :ttl))
                SQL,
            ['user' => $userId, 'purpose' => $purpose, 'hash' => $tokenHash, 'ttl' => $lifetimeSeconds],
        );
    }

    public function consumePasswordLink(string $tokenHash): ?string
    {
        $userId = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE password_resets
                   SET consumed_at = now()
                 WHERE token_hash = :hash
                   AND consumed_at IS NULL
                   AND expires_at > now()
                RETURNING user_id
                SQL,
            ['hash' => $tokenHash],
        );

        return is_string($userId) ? $userId : null;
    }

    public function emailOf(string $userId): ?string
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }

        $email = $this->connection->fetchOne(
            'SELECT email FROM users WHERE id = :id AND erased_at IS NULL',
            ['id' => $userId],
        );

        return is_string($email) ? $email : null;
    }

    public function homeOf(string $userId): ?array
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT tm.tenant_id, tm.product_id, t.slug,
                       EXISTS (
                           SELECT 1 FROM platform_settings ps
                            WHERE ps.key = 'default_tenant' AND ps.value ->> 'tenant_id' = CAST(t.id AS text)
                       ) AS is_default
                  FROM tenant_members tm
                  JOIN tenants t ON t.id = tm.tenant_id
                  JOIN products p ON p.id = tm.product_id
                 WHERE tm.user_id = :user AND p.active
                 -- A live membership first; a waiting one otherwise
                 -- (2026-09-27). This is where notices to the person are
                 -- addressed, and somebody waiting on an administrator or on
                 -- their own address still has to be able to reset a
                 -- forgotten password — which found no home and sent nothing.
                 ORDER BY (tm.status = 'ACTIVE') DESC, t.name, p.code
                 LIMIT 1
                SQL,
            ['user' => $userId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'tenant_id' => Row::string($row, 'tenant_id'),
            'product_id' => Row::string($row, 'product_id'),
            'slug' => Row::string($row, 'slug'),
            'is_default' => (bool) $row['is_default'],
        ];
    }

    public function invite(string $email, string $tenantId): array
    {
        return $this->connection->transactional(function () use ($email, $tenantId): array {
            $existing = $this->connection->fetchOne(
                'SELECT id FROM users WHERE lower(email) = lower(:email) AND erased_at IS NULL',
                ['email' => $email],
            );

            $created = false;

            if (is_string($existing)) {
                $userId = $existing;
            } else {
                $userId = $this->connection->fetchOne(
                    <<<'SQL'
                        INSERT INTO users (auth_subject, email, default_product_id)
                        VALUES ('pending', :email, (SELECT tp.product_id FROM tenant_products tp JOIN products p ON p.id = tp.product_id WHERE tp.tenant_id = :tenant AND p.active ORDER BY p.display_order, p.code LIMIT 1))
                        RETURNING id
                        SQL,
                    ['email' => $email, 'tenant' => $tenantId],
                );

                if (!is_string($userId)) {
                    throw new ConflictException('EMAIL_TAKEN', 'That address already has an account.');
                }

                $this->connection->executeStatement(
                    "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
                    ['id' => $userId],
                );

                // A password nobody knows, in the shape the database
                // requires: sign-in is impossible until the invitation link
                // sets a real one. Random bytes rather than a constant, so
                // two invited accounts never share a hash.
                $this->connection->executeStatement(
                    'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
                    ['id' => $userId, 'email' => $email, 'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12])],
                );
                $created = true;
            }

            // A member on every product the organisation holds (ADR-047),
            // live — the invitation is the approval — and a USER. Somebody
            // already a member keeps what they have.
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_members (tenant_id, product_id, user_id, status)
                    SELECT tp.tenant_id, tp.product_id, :user, 'ACTIVE'
                      FROM tenant_products tp
                     WHERE tp.tenant_id = :tenant
                    ON CONFLICT DO NOTHING
                    SQL,
                ['tenant' => $tenantId, 'user' => $userId],
            );
            // Waiting on an administrator or on their own mailbox, the
            // invitation settles both: an administrator has named them.
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE tenant_members SET status = 'ACTIVE'
                     WHERE tenant_id = :tenant AND user_id = :user AND status IN ('PENDING', 'UNCONFIRMED')
                    SQL,
                ['tenant' => $tenantId, 'user' => $userId],
            );
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id)
                    SELECT tp.tenant_id, tp.product_id, :user, r.id
                      FROM tenant_products tp
                      CROSS JOIN roles r
                     WHERE tp.tenant_id = :tenant AND r.code = 'USER'
                       AND NOT EXISTS (
                           SELECT 1 FROM tenant_member_roles x
                            WHERE x.tenant_id = tp.tenant_id AND x.product_id = tp.product_id AND x.user_id = :user
                       )
                    SQL,
                ['tenant' => $tenantId, 'user' => $userId],
            );

            return ['user_id' => $userId, 'created' => $created];
        });
    }

    public function confirmEmail(string $tokenHash): bool
    {
        // One statement, so a link opened twice in two tabs confirms once:
        // the second UPDATE matches nothing because `consumed_at` is set.
        $userId = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE email_verifications
                   SET consumed_at = now()
                 WHERE token_hash = :hash
                   AND consumed_at IS NULL
                   AND expires_at > now()
                RETURNING user_id
                SQL,
            ['hash' => $tokenHash],
        );

        if (!is_string($userId)) {
            return false;
        }

        $this->proveAddress($userId);

        return true;
    }

    public function proveAddress(string $userId): void
    {
        if (!Uuid::isValid($userId)) {
            return;
        }

        $this->connection->transactional(function () use ($userId): void {
            $email = $this->connection->fetchOne(
                'UPDATE users SET email_verified_at = coalesce(email_verified_at, now()) WHERE id = :id RETURNING email',
                ['id' => $userId],
            );

            if (!is_string($email)) {
                return;
            }

            // Decided again now, against the list as it stands: the
            // organisation may have dropped the domain, or the policy, since
            // the address was typed. What no longer admits them goes — the
            // evidence arrived and the answer is no — rather than lingering as
            // a membership nobody can act on.
            $waiting = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT DISTINCT tm.tenant_id, t.join_policy
                      FROM tenant_members tm
                      JOIN tenants t ON t.id = tm.tenant_id
                     WHERE tm.user_id = :user AND tm.status = 'UNCONFIRMED'
                    SQL,
                ['user' => $userId],
            );

            foreach ($waiting as $row) {
                $tenantId = Row::string($row, 'tenant_id');
                $domains = $this->connection->fetchFirstColumn(
                    'SELECT domain FROM tenant_join_domains WHERE tenant_id = :tenant',
                    ['tenant' => $tenantId],
                );
                $admitted = Row::string($row, 'join_policy') === JoinDecision::DOMAIN
                    && JoinDecision::admitsByDomain(
                        array_values(array_map(static fn (mixed $d): string => strtolower(is_string($d) ? $d : ''), $domains)),
                        $email,
                    );

                $this->connection->executeStatement(
                    $admitted
                        ? "UPDATE tenant_members SET status = 'ACTIVE' WHERE tenant_id = :tenant AND user_id = :user AND status = 'UNCONFIRMED'"
                        : "DELETE FROM tenant_members WHERE tenant_id = :tenant AND user_id = :user AND status = 'UNCONFIRMED'",
                    ['tenant' => $tenantId, 'user' => $userId],
                );
            }
        });
    }

    public function confirmationTargetOf(string $userId): ?array
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }

        // Any membership, live or waiting: somebody waiting on their own
        // address under DOMAIN has no live one, and is exactly who asks.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT u.email, (u.email_verified_at IS NOT NULL) AS verified, tm.tenant_id, tm.product_id
                  FROM users u
                  JOIN tenant_members tm ON tm.user_id = u.id
                  JOIN products p ON p.id = tm.product_id
                 WHERE u.id = :user AND u.erased_at IS NULL AND u.email IS NOT NULL AND p.active
                 ORDER BY (tm.status = 'ACTIVE') DESC, p.display_order, p.code
                 LIMIT 1
                SQL,
            ['user' => $userId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'email' => Row::string($row, 'email'),
            'verified' => (bool) $row['verified'],
            'tenant_id' => Row::string($row, 'tenant_id'),
            'product_id' => Row::string($row, 'product_id'),
        ];
    }
}
