<?php

declare(strict_types=1);

namespace App\Demo\Infrastructure;

use App\Commerce\Domain\FreemiumPeriod;
use App\Commerce\Domain\SubscriptionTerms;
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
 * no invariant behind it. What is deliberately *not* here is anything that
 * moves money: a subscription, an invoice and, since 2026-09-26, a payment.
 * `App\Demo\Service\DemoSeeder` takes those through `Subscriptions`,
 * `Invoicing` and `DemoCollection`, so the invoice number comes from the
 * gapless sequence, the activation writes its event, and a collected payment
 * settles its document in the transaction that recorded it.
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
        // The story a product tells and the pictures it shows (2026-09-24).
        // Named although both cascade from `products`: this list is what a
        // reader checks to answer "is anything left behind", and a table
        // that is only ever emptied by a cascade is one nobody finds.
        'product_showcase_band_translations', 'product_showcase_bands',
        'product_showcase_translations', 'product_showcase', 'product_assets',
        'messages', 'conversation_participants', 'conversations',
        'notification_deliveries', 'notifications', 'notification_consents', 'notification_preferences',
        'project_versions', 'assets', 'projects',
        'job_runs', 'jobs',
        // A product beside the platform (ADR-051): its keys, what they read,
        // what they reported, what it was told.
        'product_usage', 'product_access_log', 'product_credentials', 'webhook_deliveries',
        'platform_staff',
        'erasure_requests', 'audit_log', 'financial_events',
        'revenue_periods', 'offer_revenue_periods', 'renewal_periods',
        'tenant_member_roles', 'tenant_members', 'tenant_palettes', 'tenant_products', 'tenants',
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
            $tenants = $this->tenants($products);
            $users = $this->people($passwordHash, $products);

            $this->holdings($tenants, $products, $users[DemoWorld::STAFF_ADMIN]);
            $this->roles($tenants, $products, $users);

            $offers = [];

            foreach (DemoWorld::PRODUCTS as $code => $definition) {
                $offers[$code] = $this->catalogue($products[$code], $definition['base'], $definition['meters'], $definition['capabilities'] ?? [], $definition['plans'] ?? []);
                $this->supplier($products[$code], $definition['name']);
                $this->schemaVersions($products[$code], $code);
                $this->freemium($products[$code], $definition['plans'] ?? []);
                $this->showcase($products[$code], $code);
            }

            $this->customers($tenants);

            return new DemoStructure($products, $tenants, $users, $offers);
        });
    }

    public function verify(DemoStructure $structure): array
    {
        $productCount = count(DemoWorld::PRODUCTS);
        $acme = $structure->tenant('acme');

        // Trois offres par produit, plus celles des plans hors echelle — le siege Lecture de Plan
        // en est un. Compter « trois fois le nombre de produits » ne tient plus des qu'un produit
        // vend autre chose qu'un rang de son echelle, et c'etait la le seul obstacle a le faire.
        $offresAttendues = count(DemoWorld::LADDER) * $productCount + array_sum(array_map(
            static fn (array $p): int => count($p['plans'] ?? []),
            DemoWorld::PRODUCTS,
        ));

        return [
            'every offer version is published' => $offresAttendues === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offer_versions v
                JOIN offers o ON o.id = v.offer_id
                WHERE v.status = 'ACTIVE'
                SQL,
            ),
            // The two the readiness screen adds to "it has a catalogue": a
            // product can be entirely priced and still sell to nobody.
            'every offer is advertised' => $offresAttendues === $this->count(
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
                ['tenant' => $acme, 'user' => $structure->user('acme-user1')],
            ),
            // And where those screens open (2026-09-23, amended 2026-09-28):
            // a member lands on the product deployed beside the platform,
            // which is the only one with anywhere else to be — **unless their
            // organisation decides for them**, in which case they answer
            // nothing and the organisation's own choice is what the ladder
            // reaches. Counted by joining the code rather than trusting an id,
            // because a default pointing at the wrong product looks exactly
            // like this one.
            'every member who answers for themselves opens beside the platform'
                => $this->peopleWhoAnswerForThemselves() === $this->count(
                    <<<'SQL'
                    SELECT count(*) FROM users u
                    JOIN products p ON p.id = u.default_product_id
                    WHERE p.code = :code
                    SQL,
                    ['code' => DemoWorld::PEOPLE_DEFAULT_PRODUCT],
                ),
            // And the order they are listed in (2026-09-23): Plan first,
            // which is what the switcher offers and what somebody with no
            // default lands on. Read back as codes rather than counted, so a
            // number typed into the wrong row fails here and not in a
            // screenshot.
            'the products are listed in the order the world gives them' => array_keys(DemoWorld::PRODUCTS) === $this->connection->fetchFirstColumn(
                'SELECT code FROM products ORDER BY display_order, code',
            ),
            'platform staff default to no product at all' => 0 < $this->count(
                <<<'SQL'
                SELECT count(*) FROM users
                WHERE email = :email AND default_product_id IS NULL
                SQL,
                ['email' => DemoWorld::email(DemoWorld::STAFF_ADMIN)],
            ),
            // The quota the workspace actually asks for (2026-09-22), and a
            // schema version to accept a document under: without both, the
            // Projects screen offers nothing and refuses what it offers.
            'every offer grants the projects quota the workspace reads' => self::offersGranting(DemoWorld::PROJECTS_QUOTA) === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offer_version_features g
                JOIN features f ON f.id = g.feature_id
                WHERE f.code = :code
                SQL,
                ['code' => DemoWorld::PROJECTS_QUOTA],
            ),
            'every product accepts a project schema version' => $productCount === $this->count(
                'SELECT count(*) FROM product_configuration WHERE key = :key',
                ['key' => DemoWorld::SCHEMA_VERSIONS_KEY],
            ),
            'the product beside the platform has its address' => 1 === $this->count(
                'SELECT count(*) FROM products WHERE app_url IS NOT NULL',
            ),
            'both tenant roles are held, and the platform has its one administrator' => 2 === $this->count(
                'SELECT count(DISTINCT r.code) FROM tenant_member_roles m JOIN roles r ON r.id = m.role_id',
            ) && 1 === $this->count('SELECT count(*) FROM platform_staff'),
            'every offer says how many people it covers' => self::offersGranting('users') === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offer_version_features g
                JOIN features f ON f.id = g.feature_id
                WHERE f.code = 'users'
                SQL,
            ),
            'platform staff hold no tenant membership' => 0 === $this->count(
                'SELECT count(*) FROM tenant_members m JOIN platform_staff s ON s.user_id = m.user_id',
            ),
            // The catalogue speaks five languages (2026-09-24). Counted
            // rather than spot-checked: a feature seeded without its
            // translations still renders — in English — so nothing on
            // screen would say this had quietly stopped working.
            'every feature says its name in four other languages' => 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM features f
                WHERE (SELECT count(*) FROM feature_translations t WHERE t.feature_id = f.id) <> 4
                SQL,
            ),
            'every offer says its name in four other languages' => 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offers o
                WHERE (SELECT count(*) FROM offer_translations t WHERE t.offer_id = o.id) <> 4
                SQL,
            ),
            // The shop window (2026-09-24). Counted rather than spot-checked
            // for the reason the catalogue's translations are: a band seeded
            // without its four still renders — in English — so nothing on
            // screen would say this had quietly stopped working.
            'the product beside the platform tells its story' => count(DemoWorld::SHOWCASE['plan']) === $this->count(
                <<<'SQL'
                SELECT count(*) FROM product_showcase s
                JOIN products p ON p.id = s.product_id
                WHERE p.code = 'plan'
                SQL,
            ),
            'every band of it says itself in four other languages' => 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM product_showcase s
                WHERE (SELECT count(*) FROM product_showcase_translations t WHERE t.block_id = s.id) <> 4
                SQL,
            ),
            // Published, or a stranger reads a 404 — which is right for a
            // draft and useless in a demonstration.
            'its story is published, and no other product pretends to have one' => 1 === $this->count(
                'SELECT count(*) FROM products WHERE showcase_published_at IS NOT NULL',
            ),
            // The money (2026-09-26). Counted rather than spot-checked, for
            // the reason the catalogue's translations are: an invoice seeded
            // without the payment that collected it still renders — as a
            // paid document — so nothing on screen would say this had
            // quietly stopped working. What it leaves is a `/payments`
            // screen that is empty in a demonstration, which reads as a
            // feature that does not work.
            'money was collected, and one attempt went through' => 0 < $this->count(
                "SELECT count(*) FROM payments WHERE status = 'SUCCEEDED'",
            ),
            // And one that did not, because the screen renders a failure
            // differently and offers to try again: a world with no failed
            // attempt demonstrates half the screen.
            'an attempt failed, and says both what and why' => 0 < $this->count(
                <<<'SQL'
                SELECT count(*) FROM payments
                WHERE status = 'FAILED' AND failure_code IS NOT NULL AND failure_reason IS NOT NULL
                SQL,
            ),
            // The retry has something to act on. A failed attempt on an
            // invoice that has since been paid cannot be retried at all —
            // `Payments::start` refuses anything but an ISSUED invoice — so
            // a world where every failure sits on a settled document offers
            // a button that can only answer with an error.
            'a failed attempt is owed against an invoice that can still be paid' => 0 < $this->count(
                <<<'SQL'
                SELECT count(*) FROM payments p
                JOIN invoices i ON i.id = p.invoice_id
                WHERE p.status = 'FAILED' AND i.status = 'ISSUED'
                SQL,
            ),
            // More than one organisation, so the console's cross-tenant
            // views have something to show as well.
            'more than one organisation has been paid' => 1 < $this->count(
                "SELECT count(DISTINCT tenant_id) FROM payments WHERE status = 'SUCCEEDED'",
            ),
            // And the one that would be noticed on stage: a payment that
            // collects an amount its invoice never asked for. The amount is
            // the document's, in minor units and in its currency, because
            // nothing anywhere in this platform names one twice.
            'every payment collects exactly what its invoice asks' => 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM payments p
                JOIN invoices i ON i.id = p.invoice_id
                WHERE p.amount_minor_units <> i.gross_minor_units OR p.currency <> i.currency
                SQL,
            ),
            // A paid invoice that was collected by card is settled *by* its
            // payment, so a successful attempt against a document still
            // saying it is owed would mean the settlement did not happen —
            // money in and a customer still being chased for it.
            'every attempt that went through settled the document it collected' => 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM payments p
                JOIN invoices i ON i.id = p.invoice_id
                WHERE p.status = 'SUCCEEDED' AND i.status <> 'PAID'
                SQL,
            ),
            // The free period (2026-09-27, spec §6). Counted rather than
            // spot-checked, for the reason the catalogue's translations are: a
            // world seeded without it still renders — every screen looks
            // exactly the same, and the catalogue simply has one fewer card —
            // so nothing on screen would say this had quietly stopped working.
            //
            // Recognised by its properties throughout, never by a plan's code:
            // free, and over when its period is. That is what the subscription
            // path asks and what `gate:plans` requires it to ask.
            // The organisation's own landing (2026-09-28). Two rows rather
            // than one, because the two halves fail separately: a tenant with
            // no default lands nowhere in particular, and a tenant whose
            // people all answer for themselves makes the column invisible —
            // the ladder never reaches it, and it could hold anything.
            'an organisation says where its screens open' => count(DemoWorld::TENANTS) - 1 === $this->count(
                'SELECT count(*) FROM tenants WHERE default_product_id IS NOT NULL',
            ),
            'and the organisation that decides for its people is the one they leave unanswered'
                => 0 === $this->count(
                    <<<'SQL'
                    SELECT count(*) FROM users u
                    JOIN tenant_members m ON m.user_id = u.id
                    JOIN tenants t ON t.id = m.tenant_id
                    WHERE t.slug = ANY(:deciding) AND u.default_product_id IS NOT NULL
                    SQL,
                    ['deciding' => '{' . implode(',', DemoWorld::TENANTS_THAT_DECIDE_FOR_THEIR_PEOPLE) . '}'],
                ),
            'a free period is on sale, free and finite' => count(DemoWorld::FREEMIUM) === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offer_versions v
                JOIN offers o ON o.id = v.offer_id
                WHERE v.price_minor_units = 0
                  AND v.renewal = 'ENDS_AT_TERM'
                  AND v.status = 'ACTIVE'
                  AND o.publicly_listed
                SQL,
            ),
            // How long it runs, which `term_months` cannot say. Read back as a
            // number rather than counted, so a figure typed into the wrong key
            // fails here and not on somebody's fifth free day.
            'it says how many days it lasts, and only where it is sold' => count(DemoWorld::FREEMIUM) === $this->count(
                <<<'SQL'
                SELECT count(*) FROM product_configuration
                WHERE key = :key AND value = CAST(:value AS jsonb)
                SQL,
                [
                    'key' => FreemiumPeriod::CONFIGURATION_KEY,
                    'value' => json_encode(
                        FreemiumPeriod::ofDays(DemoWorld::FREEMIUM_DAYS)->asConfiguration(),
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            ),
            // One user, one project — what the operator asked for, and both in
            // the platform's own codes, so the workspace enforces them rather
            // than a screen describing them.
            'it sells one person and one project' => 2 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM offer_version_features g
                JOIN features f ON f.id = g.feature_id
                JOIN offer_versions v ON v.id = g.offer_version_id
                WHERE v.price_minor_units = 0
                  AND v.renewal = 'ENDS_AT_TERM'
                  AND f.code IN (:projects, 'users')
                  AND g.limit_value = 1
                SQL,
                ['projects' => DemoWorld::PROJECTS_QUOTA],
            ),
            // Somebody is actually on it, and the row says what it is. Without
            // this the catalogue would advertise a plan nobody in the world
            // has ever taken, which demonstrates the card and not the path.
            'somebody is trying it, and their five days are running' => count(DemoWorld::FREEMIUM) === $this->count(
                <<<'SQL'
                SELECT count(*) FROM subscriptions
                WHERE is_freemium
                  AND status = 'ACTIVE'
                  AND renewal = 'ENDS_AT_TERM'
                  AND term_months IS NULL
                  AND current_period_end > now() + CAST(:short AS interval)
                  AND current_period_end <= now() + CAST(:exact AS interval)
                SQL,
                [
                    'short' => sprintf('%d days', DemoWorld::FREEMIUM_DAYS - 1),
                    'exact' => sprintf('%d days', DemoWorld::FREEMIUM_DAYS),
                ],
            ),
            // And the whole of §6.3: **no document at all.** Numbering is
            // gapless, so a €0 invoice is a permanent, unremovable record of no
            // transaction — and an order would have raised one at fulfilment.
            // Asserted on the rows rather than on the path, because the path
            // could change and this is the fact that must not.
            'the free period raised no order and no invoice' => 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM invoices i
                JOIN subscriptions s ON s.id = i.subscription_id
                WHERE s.is_freemium
                SQL,
            ) && 0 === $this->count(
                <<<'SQL'
                SELECT count(*) FROM orders o
                JOIN subscriptions s ON s.id = o.subscription_id
                WHERE s.is_freemium
                SQL,
            ),
        ];
    }

    /**
     * How many offers in this world grant a feature: the three rungs of every
     * product's ladder, plus the plans of their own that name it.
     *
     * Derived from the world rather than written as `3 * $productCount`, which
     * was right until a plan outside the ladder granted a quota — the freemium
     * sells one project and one person — and would then have failed with a
     * number nobody could read a cause from.
     */
    private static function offersGranting(string $feature): int
    {
        return count(DemoWorld::PRODUCTS) * count(DemoWorld::LADDER) + array_sum(array_map(
            static fn (array $product): int => count(array_filter(
                $product['plans'] ?? [],
                static fn (array $plan): bool => array_key_exists($feature, $plan['grants']),
            )),
            DemoWorld::PRODUCTS,
        ));
    }

    // --- The rows -----------------------------------------------------------------

    /** @return array<string, string> code => id */
    private function products(): array
    {
        $products = [];

        foreach (DemoWorld::PRODUCTS as $code => $product) {
            // The address is where the switcher and the landing send a
            // person for a product deployed beside the platform (ADR-051 §3).
            // The order is where the product sits in every list (2026-09-23):
            // written down rather than left to the alphabet, which put Atlas
            // first for no reason anybody chose.
            $products[$code] = $this->id(
                'INSERT INTO products (code, name, active, app_url, display_order) VALUES (:code, :name, true, :appUrl, :order) RETURNING id',
                ['code' => $code, 'name' => $product['name'], 'appUrl' => $product['app_url'], 'order' => $product['order']],
            );
        }

        return $products;
    }

    /**
     * @param array<string, string> $products code => id
     *
     * @return array<string, string> key => id
     */
    private function tenants(array $products): array
    {
        $tenants = [];

        foreach (DemoWorld::TENANTS as $key => $tenant) {
            $tenants[$key] = $this->id(
                // `default_product_id` is where an organisation's screens open
                // when the address names none and the person has answered
                // nothing for themselves (2026-09-28). A plain foreign key to
                // `products`, so the row only has to exist — that the
                // organisation also *holds* it is checked where a customer
                // sets it, and here it is true by construction.
                'INSERT INTO tenants (name, slug, default_product_id) VALUES (:name, :slug, :product) RETURNING id',
                [
                    'name' => $tenant['name'],
                    'slug' => $key,
                    'product' => $tenant['default'] === null ? null : $products[$tenant['default']],
                ],
            );
        }

        // Acme is the operator's own: the bare host addresses it (2026-09-17);
        // the others live at /<slug>/.
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

    /**
     * @param array<string, string> $products code => id
     *
     * @return array<string, string> key => id
     */
    private function people(string $passwordHash, array $products): array
    {
        $users = [];

        // Where each person's screens open when an address names no product
        // (2026-09-23). Everybody with a membership holds Plan here, and
        // `default_product_id` has a foreign key to a product, not a
        // promise: the database would refuse a code that is not one.
        $default = $products[DemoWorld::PEOPLE_DEFAULT_PRODUCT];

        foreach (DemoWorld::PEOPLE as $key => $person) {
            // A distinct placeholder per person: `auth_subject` is unique, so
            // nine rows sharing one literal is a constraint violation rather
            // than nine people. Rewritten to `local:<id>` below.
            $users[$key] = $this->id(
                'INSERT INTO users (auth_subject, email, display_name, default_product_id) VALUES (:subject, :email, :name, :product) RETURNING id',
                [
                    'subject' => 'seeding:' . $key,
                    'email' => DemoWorld::email($key),
                    'name' => $person['name'],
                    // Staff hold no membership, so they have no product to
                    // default to — the console reads the platform's list. And
                    // a member of an organisation that decides for its people
                    // answers nothing here, so the organisation's own choice
                    // is what the ladder reaches (2026-09-28).
                    'product' => $person['tenants'] === [] || array_intersect(
                        $person['tenants'],
                        DemoWorld::TENANTS_THAT_DECIDE_FOR_THEIR_PEOPLE,
                    ) !== [] ? null : $default,
                ],
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

                $this->palette($tenants[$key], $products[$code], $assignedBy);
            }
        }
    }

    /**
     * A palette for each organisation in each product, drawn at random from
     * the platform's list (2026-10-07), so the demonstration shows what the
     * palettes are for — Acme in Plan and Globex in Plan looking like two
     * companies — rather than every screen in the platform's default.
     *
     * Drawn from `palettes` as it stands, never from a list in this file:
     * those are the operator's, edited on the console and kept across a
     * reset, and a name written here would be refused the day one was
     * renamed. None at all assigns nothing, and the screens wear the
     * default — the same answer an organisation that never chose gets.
     *
     * A different draw on every reset is the point: somebody resetting to
     * look again sees the matrix change, and nothing in the demonstration
     * depends on which colour came out.
     */
    private function palette(string $tenant, string $product, string $chosenBy): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_palettes (tenant_id, product_id, palette, chosen_by)
                SELECT :tenant, :product, name, :user FROM palettes ORDER BY random() LIMIT 1
                SQL,
            ['tenant' => $tenant, 'product' => $product, 'user' => $chosenBy],
        );
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
     * Three plans, the three features every catalogue has plus what this
     * product meters, three offers — published and advertised.
     *
     * The feature codes are the platform's, not the demo's: `max_projects`
     * is what the workspace asks before storing a project, `users` what
     * bounds a subscription's people. A catalogue that named them
     * differently would price quotas nothing enforces.
     *
     * @param array<string, array{name: string, unit: string, starter: int, pro: int}> $meters
     * @param array<string, array{name: string, from: string}> $capabilities
     * @param array<string, array{name: string, rank: int, price: int, period: string, renewal?: string, offer: array{code: string, name: string}, grants: array<string, int|null>}> $plansEnPlus
     *
     * @return array<string, string> offer code => id
     */
    private function catalogue(string $product, int $base, array $meters, array $capabilities = [], array $plansEnPlus = []): array
    {
        $plans = [];

        // The ladder's ranks come from the world's own table (2026-09-27), not
        // from three literals here: renumbering it is a change of constants in
        // one place, which is what spec §6.2 says it is.
        $echelle = [];

        foreach (DemoWorld::LADDER as $code => $rung) {
            $echelle[] = [$code, $rung['name'], $rung['rank']];
        }

        foreach ($plansEnPlus as $code => $plan) {
            $echelle[] = [$code, $plan['name'], $plan['rank']];
        }

        foreach ($echelle as [$code, $name, $rank]) {
            $plans[$code] = $this->id(
                'INSERT INTO plans (product_id, code, name, rank) VALUES (:product, :code, :name, :rank) RETURNING id',
                ['product' => $product, 'code' => $code, 'name' => $name, 'rank' => $rank],
            );
        }

        $features = [];
        $starter = [];
        $pro = [];
        $scale = [];

        foreach (DemoWorld::FEATURES as $code => $feature) {
            $features[$code] = $this->feature($code, $feature['name'], $feature['kind'], $feature['unit']);

            // Every one is a quota since `white_label` was retired
            // (2026-10-05): everybody's with its limit, and Scale's without.
            $starter[$code] = $feature['starter'];
            $pro[$code] = $feature['pro'];
            $scale[$code] = null;
        }

        // A product's own BOOLEAN capabilities, each included from its plan
        // upward. Unlike the three every catalogue has, these are not "Pro and
        // above" by default: a product must still work on its lowest plan, so
        // where each one starts is said explicitly, product by product.
        $rangs = ['starter' => 0, 'pro' => 1, 'scale' => 2];
        $paliers = [&$starter, &$pro, &$scale];

        foreach ($capabilities as $code => $capability) {
            $features[$code] = $this->feature($code, $capability['name'], 'BOOLEAN', null);

            // Un `from` hors de l'echelle nomme un plan a part : la capacite ne remonte nulle
            // part, elle appartient a ce plan-la et a lui seul.
            if (!isset($rangs[$capability['from']])) {
                if (!isset($plansEnPlus[$capability['from']])) {
                    throw new RuntimeException("unknown plan {$capability['from']} for {$code}");
                }

                continue;
            }

            $depuis = $rangs[$capability['from']];

            for ($rang = $depuis; $rang <= 2; $rang++) {
                $paliers[$rang][$code] = null;
            }
        }

        unset($paliers);

        foreach ($meters as $code => $meter) {
            $features[$code] = $this->feature($code, $meter['name'], 'QUOTA', $meter['unit']);
            $starter[$code] = $meter['starter'];
            $pro[$code] = $meter['pro'];
            $scale[$code] = null;
        }

        $offers = [];

        $aVendre = [
            ['starter-monthly', 'Starter, monthly', 'starter', $base, 'MONTHLY', $starter, 'AUTO_RENEW'],
            ['pro-monthly', 'Pro, monthly', 'pro', intdiv($base * 26, 10), 'MONTHLY', $pro, 'AUTO_RENEW'],
            ['scale-yearly', 'Scale, yearly', 'scale', $base * 26, 'YEARLY', $scale, 'AUTO_RENEW'],
        ];

        foreach ($plansEnPlus as $code => $plan) {
            // The offer's code and name are written down rather than derived
            // from the period (2026-09-27): a freemium's period is CUSTOM,
            // because it is never billed, and `freemium-custom` describes
            // nothing a customer would recognise.
            $aVendre[] = [
                $plan['offer']['code'],
                $plan['offer']['name'],
                $code,
                $plan['price'],
                $plan['period'],
                $plan['grants'],
                $plan['renewal'] ?? 'AUTO_RENEW',
            ];
        }

        foreach ($aVendre as [$code, $name, $plan, $price, $period, $grants, $renewal]) {
            $offer = $this->id(
                <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name, publicly_listed)
                VALUES (:product, :plan, :code, :name, true) RETURNING id
                SQL,
                ['product' => $product, 'plan' => $plans[$plan] ?? throw new RuntimeException("unknown plan {$plan}"), 'code' => $code, 'name' => $name],
            );

            $this->translateOffer($offer, $code);

            // Draft, grant, publish — the order the product actually uses. A
            // version's grants freeze the moment it leaves DRAFT (ADR-033), so
            // seeding an ACTIVE row and attaching grants afterwards would build
            // a version the application cannot.
            $version = $this->id(
                <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from, renewal)
                VALUES (:offer, 1, 'DRAFT', :period, :price, 'EUR', now() - interval '30 days', :renewal)
                RETURNING id
                SQL,
                ['offer' => $offer, 'period' => $period, 'price' => $price, 'renewal' => $renewal],
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
     * The story a product tells on its own page (2026-09-24, step 5 of
     * `docs/home-showcase-spec.md`).
     *
     * Written for real and in five languages, because a demonstration
     * whose shop window said *lorem ipsum* would let the page be judged on
     * its layout and never on whether it works — and one written only in
     * English would demonstrate the fallback rather than the feature.
     *
     * **Published**, so a stranger can read it: an unpublished story
     * answers 404, which is right for a draft and useless in a demo.
     *
     * Only the product that has something to say. The other four show
     * their name and their prices, which is the honest state of a product
     * nobody has written a page for — and the one somebody most needs to
     * see before writing their own.
     */
    private function showcase(string $product, string $code): void
    {
        $blocks = DemoWorld::SHOWCASE[$code] ?? [];

        if ($blocks === []) {
            return;
        }

        // Positions within a kind, minted from the order they are written
        // in, in tens — `display_order`'s habit, so one can be slipped
        // between two others without renumbering anybody.
        $positions = [];

        foreach ($blocks as $block) {
            $positions[$block['block']] = ($positions[$block['block']] ?? 0) + 10;

            $id = $this->id(
                <<<'SQL'
                INSERT INTO product_showcase (product_id, block, position, content)
                VALUES (:product, :block, :position, CAST(:content AS jsonb))
                RETURNING id
                SQL,
                [
                    'product' => $product,
                    'block' => $block['block'],
                    'position' => $positions[$block['block']],
                    'content' => json_encode($block['content'], JSON_THROW_ON_ERROR),
                ],
            );

            foreach ($block['translations'] as $locale => $content) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO product_showcase_translations (block_id, locale, content)
                    VALUES (:block, :locale, CAST(:content AS jsonb))
                    SQL,
                    [
                        'block' => $id,
                        'locale' => $locale,
                        'content' => json_encode($content, JSON_THROW_ON_ERROR),
                    ],
                );
            }
        }

        // Each band's own heading, which is one row per band where the
        // blocks above are one per row: the section is called "How it
        // works" once, however many steps it holds. `PRICING` gets one and
        // has no block at all, which is the whole reason headings are their
        // own table.
        foreach (DemoWorld::SHOWCASE_HEADINGS[$code] ?? [] as $band => $heading) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO product_showcase_bands (product_id, block, content)
                VALUES (:product, :block, CAST(:content AS jsonb))
                SQL,
                [
                    'product' => $product,
                    'block' => $band,
                    'content' => json_encode($heading['content'], JSON_THROW_ON_ERROR),
                ],
            );

            foreach ($heading['translations'] as $locale => $content) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO product_showcase_band_translations (product_id, block, locale, content)
                    VALUES (:product, :block, :locale, CAST(:content AS jsonb))
                    SQL,
                    [
                        'product' => $product,
                        'block' => $band,
                        'locale' => $locale,
                        'content' => json_encode($content, JSON_THROW_ON_ERROR),
                    ],
                );
            }
        }

        $this->connection->executeStatement(
            'UPDATE products SET showcase_published_at = now() WHERE id = :id',
            ['id' => $product],
        );
    }

    /**
     * A feature on the platform's one list, created once however many
     * products sell it (2026-09-24).
     *
     * `ON CONFLICT` rather than an insert, because the demonstration seeds
     * five products and `max_projects` is the same capability in every one
     * of them — that is the whole point of there being one list
     * (`docs/translatable-fields-spec.md` §4). The name is refreshed and
     * the kind is not: a second product disagreeing about what a code
     * *means* is the state the merge migration refuses outright, and a
     * seeder that quietly reconciled it would hide exactly that.
     */
    private function feature(string $code, string $name, string $kind, ?string $unit): string
    {
        $id = $this->id(
            <<<'SQL'
            INSERT INTO features (code, name, kind, unit, description)
            VALUES (:code, :name, :kind, :unit, :description)
            ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description
            RETURNING id
            SQL,
            [
                'code' => $code,
                'name' => $name,
                'kind' => $kind,
                'unit' => $unit,
                'description' => DemoWorld::FEATURE_DESCRIPTIONS[$code] ?? null,
            ],
        );

        $this->translateFeature($id, $code);

        return $id;
    }

    /**
     * What a feature says in the four other languages (2026-09-24).
     *
     * Written here rather than left to an operator, because the point of
     * the demonstration is that somebody switching the interface to French
     * sees a French catalogue — an English one with French buttons around
     * it would demonstrate the opposite of what was built.
     *
     * `ON CONFLICT DO NOTHING`, because a feature the five products share
     * is seeded once per product and translated with it.
     */
    private function translateFeature(string $featureId, string $code): void
    {
        foreach (DemoWorld::FEATURE_TRANSLATIONS[$code] ?? [] as $locale => $written) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO feature_translations (feature_id, locale, name, description)
                VALUES (:feature, :locale, :name, :description)
                ON CONFLICT (feature_id, locale) DO NOTHING
                SQL,
                [
                    'feature' => $featureId,
                    'locale' => $locale,
                    'name' => $written['name'],
                    'description' => $written['description'] ?? null,
                ],
            );
        }
    }

    /**
     * What an offer is called in the four other languages.
     *
     * A translation of an offer's name is not a term (ADR-033): the price
     * and the conditions of a published version are frozen, and saying the
     * same offer in another language changes neither.
     */
    private function translateOffer(string $offerId, string $code): void
    {
        foreach (DemoWorld::OFFER_TRANSLATIONS[$code] ?? [] as $locale => $name) {
            $this->connection->executeStatement(
                'INSERT INTO offer_translations (offer_id, locale, name) VALUES (:offer, :locale, :name)',
                ['offer' => $offerId, 'locale' => $locale, 'name' => $name],
            );
        }
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
     * Which project document schema versions the product accepts
     * (non-negotiable #10) — the same row the console's configuration
     * writes, under the key the workspace reads. Without it every product
     * accepted no project, and the demo's Projects screen said so.
     */
    private function schemaVersions(string $product, string $code): void
    {
        $this->connection->executeStatement(
            'INSERT INTO product_configuration (product_id, key, value) VALUES (:product, :key, CAST(:value AS jsonb))',
            [
                'product' => $product,
                'key' => DemoWorld::SCHEMA_VERSIONS_KEY,
                'value' => json_encode(['supported' => DemoWorld::schemaVersionsFor($code)], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * How long this product's free period runs, if it sells one (spec §6.3).
     *
     * `term_months` cannot say five days, so the interval lives in the
     * product's configuration and the subscription path reads it there
     * ({@see \App\Commerce\Domain\FreemiumPeriod}). Nothing is written for a
     * product that sells no free period, and that absence is the refusal: a
     * product which has not said how long it gives itself away for does not
     * give itself away.
     *
     * Which plan is the freemium is read off its **properties** — free, and
     * over when its period is — never off its code. That is §13's rule and
     * what `gate:plans` holds in PHP, and it holds in a fixture too.
     *
     * @param array<string, array{name: string, rank: int, price: int, period: string, renewal?: string, offer: array{code: string, name: string}, grants: array<string, int|null>}> $plansEnPlus
     */
    private function freemium(string $product, array $plansEnPlus): void
    {
        foreach ($plansEnPlus as $plan) {
            if ($plan['price'] !== 0 || ($plan['renewal'] ?? 'AUTO_RENEW') !== SubscriptionTerms::ENDS_AT_TERM) {
                continue;
            }

            $this->connection->executeStatement(
                'INSERT INTO product_configuration (product_id, key, value) VALUES (:product, :key, CAST(:value AS jsonb))',
                [
                    'product' => $product,
                    'key' => FreemiumPeriod::CONFIGURATION_KEY,
                    'value' => json_encode(
                        FreemiumPeriod::ofDays(DemoWorld::FREEMIUM_DAYS)->asConfiguration(),
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            );

            return;
        }
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
            ['initech', 'FR45678912301', '8 quai Saint-Antoine'],
        ] as [$key, $vat, $address]) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO billing_profiles
                    (tenant_id, legal_name, vat_number, address_line1, postal_code, city, country_code, billing_email)
                VALUES (:tenant, :name, :vat, :address, :postalCode, :city, 'FR', :email)
                SQL,
                [
                    'tenant' => $tenants[$key],
                    'name' => DemoWorld::TENANTS[$key]['name'],
                    'vat' => $vat,
                    'address' => $address,
                    'postalCode' => $key === 'initech' ? '69002' : '75002',
                    'city' => $key === 'initech' ? 'Lyon' : 'Paris',
                    'email' => DemoWorld::email('billing'),
                ],
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

    /**
     * How many of the world's people answer the landing question for
     * themselves (2026-09-28).
     *
     * Everybody with a membership, less those whose organisation decides for
     * them: those leave `users.default_product_id` empty so that
     * `tenants.default_product_id` is what the ladder reaches. Without that
     * subtraction the organisation's column could hold anything and nobody
     * would land anywhere different, which is what made it invisible before.
     */
    private static function peopleWhoAnswerForThemselves(): int
    {
        $decided = 0;

        foreach (DemoWorld::PEOPLE as $person) {
            if ($person['tenants'] !== [] && array_intersect(
                $person['tenants'],
                DemoWorld::TENANTS_THAT_DECIDE_FOR_THEIR_PEOPLE,
            ) !== []) {
                ++$decided;
            }
        }

        return self::peopleWithAMembership() - $decided;
    }

    /**
     * How many of the world's people hold a membership — everybody but the
     * platform's own staff, who are members of nothing (§12.2).
     */
    private static function peopleWithAMembership(): int
    {
        return count(array_filter(
            DemoWorld::PEOPLE,
            static fn (array $person): bool => $person['tenants'] !== [],
        ));
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }
}
