<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Domain\RegisteredAccount;
use App\Shared\Database\Row;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SensitiveParameter;

/**
 * The five rows a new account is, written together.
 *
 * The same sequence `deploy/siteground/setup.php` performs once for the first
 * account — user, credential, tenant, membership, role — with two differences,
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

    public function register(
        string $email,
        #[SensitiveParameter] string $passwordHash,
        ?string $displayName,
        string $organisation,
        string $productCode,
        ?string $countryCode,
    ): RegisteredAccount {
        $product = $this->connection->fetchAssociative(
            'SELECT id FROM products WHERE code = :code AND active',
            ['code' => $productCode],
        );

        if ($product === false) {
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        $productId = Row::string($product, 'id');

        $this->connection->beginTransaction();

        try {
            // `pending` then `local:<id>`, because the subject a token carries
            // is derived from the id the insert has not returned yet. The same
            // two-step the installer makes, and the reason the users table has
            // no DEFAULT for it.
            $userId = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO users (auth_subject, email, display_name)
                    VALUES ('pending', :email, :name)
                    RETURNING id
                    SQL,
                ['email' => $email, 'name' => $displayName],
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

            $tenantId = $this->connection->fetchOne(
                'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
                ['name' => $organisation, 'slug' => $this->freeSlug($organisation)],
            );

            if (!is_string($tenantId)) {
                throw new ConflictException('TENANT_NOT_CREATED', 'The organisation could not be created.');
            }

            $this->connection->executeStatement(
                'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:tenant, :product, :user)',
                ['tenant' => $tenantId, 'product' => $productId, 'user' => $userId],
            );

            // TENANT_ADMIN, and only that. They administer the organisation
            // they just created — members, billing, the skin. Whether they may
            // author offers is ADR-040's question and the answer is no until a
            // platform administrator says otherwise, which is why nothing here
            // touches `may_author_offers`.
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id)
                    SELECT :tenant, :product, :user, id FROM roles WHERE code = 'TENANT_ADMIN'
                    SQL,
                ['tenant' => $tenantId, 'product' => $productId, 'user' => $userId],
            );

            // The invoice needs somebody to be addressed to before there is
            // an invoice. Created here so the checkout that follows is not
            // refused with BILLING_PROFILE_REQUIRED on an account that was
            // built for exactly that purchase — the profile is editable
            // afterwards like any other, and `billing.manage` is what the
            // TENANT_ADMIN role above grants for it.
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO billing_profiles (tenant_id, legal_name, billing_email, country_code)
                    VALUES (:tenant, :name, :email, :country)
                    SQL,
                [
                    'tenant' => $tenantId,
                    'name' => $organisation,
                    'email' => $email,
                    'country' => $countryCode,
                ],
            );

            $this->connection->commit();

            return new RegisteredAccount($userId, 'local:' . $userId, $email, $tenantId, $productId);
        } catch (UniqueConstraintViolationException $collision) {
            $this->connection->rollBack();

            // Two sign-ups for one address, racing past `emailIsTaken`. The
            // index is the authority and this is what it answers with.
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

        $this->connection->executeStatement(
            'UPDATE users SET email_verified_at = now() WHERE id = :id AND email_verified_at IS NULL',
            ['id' => $userId],
        );

        return true;
    }

    /**
     * A slug nobody is using, derived from what the person typed.
     *
     * The suffix is not cosmetic: `tenants.slug` is unique, and two companies
     * called "Acme" will sign up eventually. Appending random hex rather than
     * counting upwards avoids a query loop and avoids leaking how many
     * organisations share a name.
     */
    private function freeSlug(string $organisation): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $organisation), '-'));
        $slug = $slug === '' ? 'org' : substr($slug, 0, 40);

        $taken = (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM tenants WHERE slug = :slug)',
            ['slug' => $slug],
        );

        return $taken ? $slug . '-' . bin2hex(random_bytes(3)) : $slug;
    }
}
