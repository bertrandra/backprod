<?php

declare(strict_types=1);

namespace App\Demo\Infrastructure;

use App\Demo\Domain\DemoFixtures;
use App\Demo\Domain\DemoStructure;
use App\Demo\Domain\DemoWorld;
use App\Tax\Domain\SupplierTaxSettings;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * The demonstration world's rows, written the way the application would
 * have written them.
 *
 * Plain SQL, which is what the integration tests do too, for structure with
 * no invariant behind it. Two things are deliberately *not* here: a
 * subscription and an invoice, which `App\Demo\Service\DemoSeeder` takes
 * through `Subscriptions` and `Invoicing` so the invoice number comes from
 * the gapless sequence and the activation writes its event.
 */
final class PostgresDemoFixtures implements DemoFixtures
{
    /**
     * The business tables, in an order that respects the foreign keys.
     *
     * Reference tables are absent on purpose: `permissions`, `roles`,
     * `platform_permissions`, `tax_rates` and their join tables are migration
     * data, and a reset that dropped them would leave a database that
     * migrates cleanly and authorises nobody.
     */
    private const BUSINESS_TABLES = [
        'vat_declarations', 'vat_reporting_periods', 'vat_transactions', 'tax_records',
        'tax_identifications', 'customer_tax_profiles',
        'einvoice_events', 'einvoice_transmissions', 'invoice_documents',
        'credit_note_lines', 'credit_notes', 'refunds', 'payment_events', 'payments',
        'invoice_lines', 'invoices', 'billing_profiles',
        'order_lines', 'orders', 'quote_lines', 'quotes',
        'entitlements', 'subscription_events', 'subscriptions',
        'offer_version_features', 'offer_versions', 'offers', 'features', 'plans',
        'product_features', 'product_configuration',
        'messages', 'conversation_participants', 'conversations',
        'notification_deliveries', 'notifications', 'notification_consents', 'notification_preferences',
        'project_versions', 'assets', 'projects',
        'job_runs', 'jobs',
        'staff_access_log', 'platform_staff',
        'erasure_requests', 'audit_log', 'financial_events',
        'revenue_periods', 'offer_revenue_periods', 'renewal_periods',
        'tenant_skins', 'tenant_member_roles', 'tenant_members', 'tenant_products', 'tenants',
        'users', 'products',
        // Both cascade from `users`, so a reset that truncated users would take
        // them anyway — named here so the list stays a readable inventory of
        // what a reset removes rather than a list plus whatever cascades.
        'local_credentials', 'auth_refresh_tokens',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function foreignProducts(): array
    {
        $codes = $this->connection->fetchFirstColumn(
            'SELECT code FROM products WHERE NOT (code = ANY(CAST(:codes AS text[]))) ORDER BY code',
            ['codes' => self::textArray(DemoWorld::productCodes())],
        );

        return array_values(array_filter($codes, is_string(...)));
    }

    public function isSeeded(): bool
    {
        return 0 < $this->count(
            'SELECT count(*) FROM products WHERE code = ANY(CAST(:codes AS text[]))',
            ['codes' => self::textArray(DemoWorld::productCodes())],
        );
    }

    public function wipe(): int
    {
        // One statement, so the foreign keys never see a half-empty world.
        $this->connection->executeStatement(
            'TRUNCATE TABLE ' . implode(', ', self::BUSINESS_TABLES) . ' RESTART IDENTITY CASCADE',
        );

        // The bare host's organisation is about to be gone; the setting that
        // names it goes too, or the storefront would point at nothing.
        $this->connection->executeStatement("DELETE FROM platform_settings WHERE key = 'default_tenant'");

        return count(self::BUSINESS_TABLES);
    }

    public function write(string $passwordHash): DemoStructure
    {
        return $this->connection->transactional(function () use ($passwordHash): DemoStructure {
            $products = $this->products();
            $tenants = $this->tenants();
            $users = $this->people($passwordHash);

            $this->holdings($tenants, $products, $users[DemoWorld::STAFF_ADMIN]);
            $this->roles($tenants, $products, $users);

            $offers = [];

            foreach (DemoWorld::PRODUCTS as $code => $definition) {
                $offers[$code] = $this->catalogue($products[$code], $definition['base']);
                $this->supplier($products[$code], $definition['name']);
            }

            $this->customers($tenants);

            return new DemoStructure($products, $tenants, $users, $offers);
        });
    }

    public function verify(DemoStructure $structure): array
    {
        $productCount = count(DemoWorld::PRODUCTS);
        $acme = $structure->tenant('acme');

        return [
            'three offer versions are published per product' => 3 * $productCount === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offer_versions v
                JOIN offers o ON o.id = v.offer_id
                WHERE v.status = 'ACTIVE'
                SQL,
            ),
            // The two the readiness screen adds to "it has a catalogue": a
            // product can be entirely priced and still sell to nobody.
            'every offer is advertised' => 3 * $productCount === $this->count(
                'SELECT count(*) FROM offers WHERE publicly_listed',
            ),
            'every product has its tax position' => $productCount === $this->count(
                'SELECT count(*) FROM product_configuration WHERE key = :key',
                ['key' => SupplierTaxSettings::CONFIGURATION_KEY],
            ),
            'entitlements were granted' => 0 < $this->count(
                'SELECT count(*) FROM entitlements WHERE tenant_id = :tenant',
                ['tenant' => $acme],
            ),
            'a member is mirrored onto every product the tenant holds' => count(DemoWorld::TENANTS['acme']['holds']) === $this->count(
                'SELECT count(*) FROM tenant_members WHERE tenant_id = :tenant AND user_id = :user',
                ['tenant' => $acme, 'user' => $structure->user('grace')],
            ),
            'every role is held by exactly one person' => 2 === $this->count(
                'SELECT count(DISTINCT r.code) FROM tenant_member_roles m JOIN roles r ON r.id = m.role_id',
            ) && 4 === $this->count('SELECT count(*) FROM platform_staff'),
            'platform staff hold no tenant membership' => 0 === $this->count(
                'SELECT count(*) FROM tenant_members m JOIN platform_staff s ON s.user_id = m.user_id',
            ),
        ];
    }

    // --- The rows -----------------------------------------------------------------

    /** @return array<string, string> code => id */
    private function products(): array
    {
        $products = [];

        foreach (DemoWorld::PRODUCTS as $code => $product) {
            $products[$code] = $this->id(
                'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
                ['code' => $code, 'name' => $product['name']],
            );
        }

        return $products;
    }

    /** @return array<string, string> key => id */
    private function tenants(): array
    {
        $tenants = [];

        foreach (DemoWorld::TENANTS as $key => $tenant) {
            $tenants[$key] = $this->id(
                'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
                ['name' => $tenant['name'], 'slug' => $key],
            );
        }

        // Acme is the operator's own: the bare host addresses it (2026-09-17),
        // and Globex lives at /globex/.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES ('default_tenant', CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['value' => json_encode(['tenant_id' => $tenants[DemoWorld::DEFAULT_TENANT]], JSON_THROW_ON_ERROR)],
        );

        return $tenants;
    }

    /** @return array<string, string> key => id */
    private function people(string $passwordHash): array
    {
        $users = [];

        foreach (DemoWorld::PEOPLE as $key => $person) {
            // A distinct placeholder per person: `auth_subject` is unique, so
            // six rows sharing one literal is a constraint violation rather
            // than six people. Rewritten to `local:<id>` below.
            $users[$key] = $this->id(
                'INSERT INTO users (auth_subject, email, display_name) VALUES (:subject, :email, :name) RETURNING id',
                ['subject' => 'seeding:' . $key, 'email' => DemoWorld::email($key), 'name' => $person['name']],
            );
        }

        foreach ($users as $user) {
            // `auth_subject` becomes `local:<id>` — the same `prefix:id` shape
            // erasure uses — because that is what a token this platform issues
            // carries, and a subject the token cannot name is a person who
            // cannot sign in.
            $this->connection->executeStatement(
                "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
                ['id' => $user],
            );

            // The database refuses a password_hash that does not start with
            // `$`, so a seeder handed the plaintext would fail rather than
            // seed a world with a plaintext password in it.
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO local_credentials (user_id, email, password_hash)
                    SELECT id, email, :hash FROM users WHERE id = :id AND email IS NOT NULL
                    SQL,
                ['id' => $user, 'hash' => $passwordHash],
            );
        }

        return $users;
    }

    /**
     * Every tenant holds its products before anybody is a member of them
     * (ADR-047). Assigned by the platform administrator, which is who would.
     *
     * @param array<string, string> $tenants
     * @param array<string, string> $products
     */
    private function holdings(array $tenants, array $products, string $assignedBy): void
    {
        foreach (DemoWorld::TENANTS as $key => $tenant) {
            foreach ($tenant['holds'] as $code) {
                $this->connection->executeStatement(
                    'INSERT INTO tenant_products (tenant_id, product_id, assigned_by) VALUES (:tenant, :product, :user)',
                    ['tenant' => $tenants[$key], 'product' => $products[$code], 'user' => $assignedBy],
                );
            }
        }
    }

    /**
     * Roles are migration data and are joined by id, not by code: the code is
     * what a person reads and the id is what the schema stores, and a seeder
     * that invented either would authorise nobody.
     *
     * @param array<string, string> $tenants
     * @param array<string, string> $products
     * @param array<string, string> $users
     */
    private function roles(array $tenants, array $products, array $users): void
    {
        $staffAdmin = $users[DemoWorld::STAFF_ADMIN];

        foreach (DemoWorld::PEOPLE as $key => $person) {
            if ($person['scope'] === 'platform') {
                // A separate identity, never a tenant membership (non-negotiable
                // #22). Granted by the platform administrator — who grants their
                // own, which is the honest answer for a seeded world.
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO platform_staff (user_id, platform_role_id, granted_by)
                    VALUES (:user, (SELECT id FROM platform_roles WHERE code = :role), :by)
                    SQL,
                    ['user' => $users[$key], 'role' => $person['role'], 'by' => $staffAdmin],
                );

                continue;
            }

            // A member of the tenant, mirrored onto every product it holds
            // (ADR-047): the same rows `PostgresTenantMemberRepository::addMember`
            // writes, so the demo is a world the application could have made.
            foreach ($person['tenants'] as $tenant) {
                foreach (DemoWorld::TENANTS[$tenant]['holds'] as $code) {
                    $this->connection->executeStatement(
                        'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:tenant, :product, :user)',
                        ['tenant' => $tenants[$tenant], 'product' => $products[$code], 'user' => $users[$key]],
                    );

                    $this->connection->executeStatement(
                        <<<'SQL'
                        INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id)
                        VALUES (:tenant, :product, :user, (SELECT id FROM roles WHERE code = :role))
                        SQL,
                        ['tenant' => $tenants[$tenant], 'product' => $products[$code], 'user' => $users[$key], 'role' => $person['role']],
                    );
                }
            }
        }
    }

    /**
     * Three plans, three features, three offers — published and advertised.
     *
     * @return array<string, string> offer code => id
     */
    private function catalogue(string $product, int $base): array
    {
        $plans = [];

        foreach ([['starter', 'Starter', 10], ['pro', 'Pro', 20], ['scale', 'Scale', 30]] as [$code, $name, $rank]) {
            $plans[$code] = $this->id(
                'INSERT INTO plans (product_id, code, name, rank) VALUES (:product, :code, :name, :rank) RETURNING id',
                ['product' => $product, 'code' => $code, 'name' => $name, 'rank' => $rank],
            );
        }

        $features = [];

        foreach ([['projects', 'Projects', 'QUOTA', 'projects'], ['exports', 'Exports', 'QUOTA', 'exports'], ['white_label', 'White label', 'BOOLEAN', null]] as [$code, $name, $kind, $unit]) {
            $features[$code] = $this->id(
                'INSERT INTO features (product_id, code, name, kind, unit) VALUES (:product, :code, :name, :kind, :unit) RETURNING id',
                ['product' => $product, 'code' => $code, 'name' => $name, 'kind' => $kind, 'unit' => $unit],
            );
        }

        $offers = [];

        foreach ([
            ['starter-monthly', 'Starter, monthly', 'starter', $base, 'MONTHLY', ['projects' => 3, 'exports' => 10]],
            ['pro-monthly', 'Pro, monthly', 'pro', intdiv($base * 26, 10), 'MONTHLY', ['projects' => 25, 'exports' => 200, 'white_label' => null]],
            ['scale-yearly', 'Scale, yearly', 'scale', $base * 26, 'YEARLY', ['projects' => null, 'exports' => null, 'white_label' => null]],
        ] as [$code, $name, $plan, $price, $period, $grants]) {
            $offer = $this->id(
                <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name, publicly_listed)
                VALUES (:product, :plan, :code, :name, true) RETURNING id
                SQL,
                ['product' => $product, 'plan' => $plans[$plan] ?? throw new RuntimeException("unknown plan {$plan}"), 'code' => $code, 'name' => $name],
            );

            // Draft, grant, publish — the order the product actually uses. A
            // version's grants freeze the moment it leaves DRAFT (ADR-033), so
            // seeding an ACTIVE row and attaching grants afterwards would build
            // a version the application cannot.
            $version = $this->id(
                <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, 1, 'DRAFT', :period, :price, 'EUR', now() - interval '30 days')
                RETURNING id
                SQL,
                ['offer' => $offer, 'period' => $period, 'price' => $price],
            );

            foreach ($grants as $feature => $limit) {
                $this->connection->executeStatement(
                    'INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value) VALUES (:version, :feature, :limit)',
                    [
                        'version' => $version,
                        'feature' => $features[$feature] ?? throw new RuntimeException("unknown feature {$feature}"),
                        // A null limit is what this schema stores for "unlimited",
                        // and for a BOOLEAN feature it is the only meaningful value.
                        'limit' => $limit,
                    ],
                );
            }

            $this->connection->executeStatement("UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id", ['id' => $version]);

            $offers[$code] = $offer;
        }

        return $offers;
    }

    /**
     * The platform's own legal identity — the *supplier* on every invoice —
     * and the fiscal position it issues under, per product.
     *
     * Product configuration rather than a tenant's billing profile: the
     * customer's identity varies per tenant, the supplier's does not, and
     * `Invoicing` refuses to issue anything at all until the first of these
     * exists. The second is what the readiness screen calls the tax position.
     */
    private function supplier(string $product, string $name): void
    {
        $this->connection->executeStatement(
            "INSERT INTO product_configuration (product_id, key, value) VALUES (:product, 'billing_supplier', CAST(:value AS jsonb))",
            [
                'product' => $product,
                'value' => json_encode([
                    'legal_name' => $name . ' SAS',
                    'vat_number' => 'FR99887766554',
                    'registration_number' => '912 345 678 R.C.S. Paris',
                    'address_line1' => '1 avenue du Code',
                    'address_line2' => null,
                    'postal_code' => '75011',
                    'city' => 'Paris',
                    'country_code' => 'FR',
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->connection->executeStatement(
            'INSERT INTO product_configuration (product_id, key, value) VALUES (:product, :key, CAST(:value AS jsonb))',
            [
                'product' => $product,
                'key' => SupplierTaxSettings::CONFIGURATION_KEY,
                // Through the domain object rather than a hand-written literal:
                // the console writes this key and the tax engine reads it, and
                // a seeder that spelled `oss_registered` differently would
                // configure nothing while answering that it had.
                'value' => json_encode(
                    (new SupplierTaxSettings('FR', true, 'DIGITAL_SERVICES', 'EUR'))->toConfiguration(),
                    JSON_THROW_ON_ERROR,
                ),
            ],
        );
    }

    /**
     * The legal identities invoices are issued against, and the fiscal
     * profile that decides their VAT.
     *
     * @param array<string, string> $tenants
     */
    private function customers(array $tenants): void
    {
        foreach ([
            ['acme', 'FR12345678901', '12 rue de la Paix'],
            ['globex', 'FR98765432109', '1 rue de la Paix'],
        ] as [$key, $vat, $address]) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO billing_profiles
                    (tenant_id, legal_name, vat_number, address_line1, postal_code, city, country_code, billing_email)
                VALUES (:tenant, :name, :vat, :address, '75002', 'Paris', 'FR', :email)
                SQL,
                ['tenant' => $tenants[$key], 'name' => DemoWorld::TENANTS[$key]['name'], 'vat' => $vat, 'address' => $address, 'email' => DemoWorld::email('billing')],
            );

            $this->connection->executeStatement(
                "INSERT INTO customer_tax_profiles (tenant_id, customer_kind, country_code, taxable_person) VALUES (:tenant, 'B2B', 'FR', true)",
                ['tenant' => $tenants[$key]],
            );
        }
    }

    // --- Narrowing what DBAL answers -------------------------------------------

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        if (!is_string($value)) {
            throw new RuntimeException('Expected an id back from: ' . $sql);
        }

        return $value;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function count(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }
}
