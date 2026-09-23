<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * What the demonstration world contains — the definition, not the rows.
 *
 * **Five products, three organisations, nine people, four subscriptions,
 * five projects.** The platform is multi-product, and a demo with one
 * product cannot show the part that matters: a tenant holding several
 * (ADR-047), the console switching between them, the storefront asking
 * which one a stranger wants. Since 2026-09-22 one of the five is **Plan**,
 * a product deployed beside the platform (ADR-051): it has an application
 * address, so the switcher leaves for it, and its catalogue meters what a
 * product's own server reports. Acme holds all five, Globex three, and
 * Initech — new the same day — holds Plan alone, so a tenant with one
 * product and a product with three tenants both exist. Each organisation
 * has its administrator and at least one member, and the platform has its
 * one administrator; nobody holds two authorities at once (non-negotiable
 * #22 is easier to show when nobody is both). The addresses are the
 * operator's own since 2026-09-19, so the mails the platform sends — a
 * reset link, an invitation — land somewhere real.
 *
 * `docs/demo-world.html` is the human-readable copy of this file; when one
 * changes, so does the other. The rows are written by {@see DemoFixtures}
 * and the invariants run by the seeder in `App\Demo\Service`.
 */
final class DemoWorld
{
    /**
     * The password every seeded person gets.
     *
     * In the output on purpose. This is demonstration data whose whole point
     * is being opened; a secret nobody is told is a database nobody can look
     * at. It is also in this platform's source, so a deployment that keeps
     * these accounts changes their passwords first — the installer says so,
     * and offers the form.
     */
    public const PASSWORD = 'demo-password-1234';

    public const EMAIL_DOMAIN = 'raillard.org';

    /**
     * The five products, in the order the console lists them. `base` is the
     * monthly Starter price in cents; Pro and Scale are derived from it, so
     * the catalogues have the same shape and visibly different prices.
     * `app_url` is where a product deployed beside the platform lives
     * (ADR-051 §3) — null for one inside this shell. `meters` are the
     * features a product's own server reports usage on (ADR-051 §4),
     * beside the four every catalogue has: Starter's and Pro's limits, Scale
     * unlimited.
     *
     * A product may also declare `capabilities`: BOOLEAN features of its own,
     * each included from one plan upward. They are what a separately deployed
     * product gates its screens on — Plan reads them from `/me/context` and
     * removes the command rather than greying it, because an unbought function
     * should not advertise itself inside a working tool.
     *
     * **The tier is the operator's decision, one word per line.** What is fixed
     * is that a product must still be a product on its lowest plan: Plan's
     * Starter keeps the terrace engine and the cadastral import, because a Plan
     * that can neither draw a parcel nor design a terrace is not a cheaper
     * Plan, it is nothing.
     *
     * @var array<string, array{name: string, base: int, app_url: ?string, meters: array<string, array{name: string, unit: string, starter: int, pro: int}>, capabilities?: array<string, array{name: string, from: 'starter'|'pro'|'scale'}>}>
     */
    public const PRODUCTS = [
        'atlas' => ['name' => 'Atlas', 'base' => 1_900, 'app_url' => null, 'meters' => []],
        'boreas' => ['name' => 'Boreas', 'base' => 2_900, 'app_url' => null, 'meters' => []],
        'ceres' => ['name' => 'Ceres', 'base' => 900, 'app_url' => null, 'meters' => []],
        'delos' => ['name' => 'Delos', 'base' => 4_900, 'app_url' => null, 'meters' => []],
        'plan' => [
            'name' => 'Plan',
            'base' => 1_500,
            'app_url' => 'https://plan.raillard.org',
            // What `docs/plan-service.md` §11 says Plan meters: its documents.
            'meters' => ['plan.documents' => ['name' => 'Plan documents', 'unit' => 'documents', 'starter' => 20, 'pro' => 200]],
            // The seven Plan proposes (its `src/plateforme/capacites.ts`). The
            // codes are the product's and the platform only carries them; what
            // the platform decides is which plan includes each.
            'capabilities' => [
                'plan.terrasse' => ['name' => 'Terrace engine', 'from' => 'starter'],
                'plan.cadastre' => ['name' => 'Cadastral import (IGN)', 'from' => 'starter'],
                'plan.ortho' => ['name' => 'Aerial imagery (IGN)', 'from' => 'pro'],
                'plan.plu' => ['name' => 'Planning rules (PLU)', 'from' => 'pro'],
                'plan.3d' => ['name' => '3D view and GLB viewer', 'from' => 'pro'],
                'plan.export.dxf' => ['name' => 'DXF export', 'from' => 'pro'],
                'plan.export.dossier' => ['name' => 'Client PDF dossier', 'from' => 'scale'],
                // The one that takes away rather than gives (2026-09-23). Every other capability
                // unlocks a function; this one tells the product that whoever holds it may look
                // and not change. It belongs to no tier, because it is not a smaller Plan — it is
                // a different seat, sold on its own plan below.
                'plan.readonly' => ['name' => 'Read-only seat', 'from' => 'lecture'],
            ],
            // A plan of its own, outside the Starter/Pro/Scale ladder, because it is not a rung on
            // it: somebody who consults a plan and never draws one. Sold per seat, so a colleague
            // who reads costs a fraction of one who works.
            'plans' => [
                'lecture' => [
                    'name' => 'Lecture', 'rank' => 5, 'price' => 500, 'period' => 'MONTHLY',
                    // Everything one needs to look at a plan, and `plan.readonly` to say that
                    // looking is all. No quota: a reader stores nothing to count.
                    'grants' => ['plan.readonly', 'plan.terrasse', 'plan.cadastre', 'plan.ortho', 'plan.plu', 'plan.3d'],
                ],
            ],
        ],
    ];

    /**
     * The four features every catalogue grants, by the codes the platform
     * enforces: `max_projects` is what the workspace asks before it stores a
     * project ({@see \App\Project\Service\ProjectWorkspace::QUOTA}), `users`
     * what bounds a subscription's people. Until 2026-09-22 the first was
     * seeded as `projects`, which nothing reads — so the demo's subscribers
     * held a quota of projects and could create none.
     *
     * @var array<string, array{name: string, kind: 'QUOTA'|'BOOLEAN', unit: ?string, starter: ?int, pro: ?int}>
     */
    public const FEATURES = [
        'max_projects' => ['name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects', 'starter' => 3, 'pro' => 25],
        'exports' => ['name' => 'Exports', 'kind' => 'QUOTA', 'unit' => 'exports', 'starter' => 10, 'pro' => 200],
        // `users` (2026-09-19) is what bounds a subscription's people: Starter
        // covers its buyer, Pro three, Scale everybody.
        'users' => ['name' => 'Users', 'kind' => 'QUOTA', 'unit' => 'users', 'starter' => 1, 'pro' => 3],
        'white_label' => ['name' => 'White label', 'kind' => 'BOOLEAN', 'unit' => null, 'starter' => null, 'pro' => null],
    ];

    /**
     * The feature code the workspace counts projects against — the same
     * string as `ProjectWorkspace::QUOTA`, named here because the fixtures
     * are infrastructure and may not read an application service;
     * `DemoWorldTest` fails the day the two disagree.
     */
    public const PROJECTS_QUOTA = 'max_projects';

    /**
     * Which project document schema versions every product accepts
     * (non-negotiable #10): a product that declares none accepts no
     * project at all, which is what the demo did until 2026-09-22. The key
     * is `SchemaVersionPolicy::CONFIGURATION_KEY`, pinned the same way.
     *
     * @var list<int>
     */
    public const SCHEMA_VERSIONS = [1];
    public const SCHEMA_VERSIONS_KEY = 'project_schema_versions';

    /**
     * The organisations, and which products each holds (ADR-047).
     *
     * @var array<string, array{name: string, holds: list<string>}>
     */
    public const TENANTS = [
        'acme' => ['name' => 'Acme Ltd', 'holds' => ['atlas', 'boreas', 'ceres', 'delos', 'plan']],
        'globex' => ['name' => 'Globex SA', 'holds' => ['atlas', 'boreas', 'plan']],
        'initech' => ['name' => 'Initech SARL', 'holds' => ['plan']],
    ];

    /**
     * The organisation the bare host addresses (2026-09-17): Acme, as the
     * operator's own. Globex lives at `/globex/`, Initech at `/initech/`.
     */
    public const DEFAULT_TENANT = 'acme';

    /**
     * The people, keyed by the local part of their address. `tenants` names
     * the organisations a tenant-role holder is a member of — mirrored onto
     * every product each holds; platform staff are members of nothing.
     *
     * @var array<string, array{name: string, scope: 'tenant'|'platform', role: string, tenants: list<string>}>
     */
    public const PEOPLE = [
        'backprod' => ['name' => 'Platform admin', 'scope' => 'platform', 'role' => 'PLATFORM_ADMIN', 'tenants' => []],
        'acme-admin' => ['name' => 'ACME tenant admin', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['acme']],
        'acme-user1' => ['name' => 'ACME user1', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
        'acme-user2' => ['name' => 'ACME user2', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
        // The reader (2026-09-23): an ordinary member of acme, with an ordinary role — what makes
        // them a reader is the seat they hold, not who they are. See SEATS below.
        'acme-user4' => ['name' => 'ACME user4 (lecture)', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
        'globex-admin' => ['name' => 'Globex tenant admin', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['globex']],
        'globex-user1' => ['name' => 'Globex user1', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['globex']],
        'globex-user2' => ['name' => 'Globex user2', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['globex']],
        'initech-admin' => ['name' => 'Initech tenant admin', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['initech']],
        'initech-user1' => ['name' => 'Initech user1', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['initech']],
    ];

    /** The one platform administrator, who assigns and grants on the seeder's behalf. */
    public const STAFF_ADMIN = 'backprod';

    /** Who activates each organisation's subscription, and so owns it: its administrator. */
    public const TENANT_ADMINS = ['acme' => 'acme-admin', 'globex' => 'globex-admin', 'initech' => 'initech-admin'];

    /**
     * The subscriptions that run, each with the invoice it raised, so the
     * console's invoicing and the tenants' billing screens have something on
     * them for more than one product — and so Plan has subscribers on two
     * organisations, while Globex holds it and has not bought it.
     *
     * @var list<array{tenant: string, product: string, offer: string}>
     */
    public const SUBSCRIPTIONS = [
        ['tenant' => 'acme', 'product' => 'atlas', 'offer' => 'pro-monthly'],
        ['tenant' => 'globex', 'product' => 'boreas', 'offer' => 'starter-monthly'],
        ['tenant' => 'acme', 'product' => 'plan', 'offer' => 'pro-monthly'],
        ['tenant' => 'initech', 'product' => 'plan', 'offer' => 'starter-monthly'],
    ];

    /**
     * The seats: a subscription that belongs to ONE person rather than to the
     * organisation (§13.1, `Subscriber::user`).
     *
     * A seat's entitlements reach its holder and nobody else — the repository
     * excludes "a seat belonging to somebody else" from every other person's
     * answer. That is what makes a read-only seat possible at all: acme keeps
     * its Pro subscription for everybody, and one person additionally holds
     * Lecture, which tells Plan that this person looks and does not change.
     *
     * Note the direction: the seat ADDS `plan.readonly` on top of what the
     * tenant already grants. Capabilities are a union, so a restricting one has
     * to be read as a restriction by the product — which Plan does, and says so
     * where it reads it.
     *
     * @var list<array{tenant: string, product: string, offer: string, holder: string}>
     */
    public const SEATS = [
        ['tenant' => 'acme', 'product' => 'plan', 'offer' => 'lecture-monthly', 'holder' => 'acme-user4'],
    ];

    /**
     * The projects, made by the people who would make them, through the
     * workspace — so each one counted against its quota, in a product that
     * had declared its schema version. Plan's carry what Plan keeps for a
     * parcel; Atlas's is the platform's own workspace with something in it.
     *
     * @var list<array{tenant: string, product: string, by: string, name: string, description: string, document: array<string, mixed>}>
     */
    public const PROJECTS = [
        [
            'tenant' => 'acme', 'product' => 'plan', 'by' => 'acme-user1',
            'name' => 'Terrasse sud — parcelle AE 101',
            'description' => 'Terrasse bois sur plots, 24 m², exposition sud, Le Vésinet.',
            'document' => ['parcelle' => 'AE 101', 'commune' => 'Le Vésinet', 'surface_m2' => 24, 'lames' => 'pin classe 4', 'source' => 'demo'],
        ],
        [
            'tenant' => 'acme', 'product' => 'plan', 'by' => 'acme-admin',
            'name' => 'Abri de jardin — parcelle AE 101',
            'description' => 'Dalle et abri 12 m² au fond de la parcelle, à vérifier contre le PLU.',
            'document' => ['parcelle' => 'AE 101', 'commune' => 'Le Vésinet', 'surface_m2' => 12, 'source' => 'demo'],
        ],
        [
            'tenant' => 'initech', 'product' => 'plan', 'by' => 'initech-admin',
            'name' => 'Terrasse du restaurant',
            'description' => 'Terrasse de 60 m² sur lambourdes, accès PMR, Lyon 2e.',
            'document' => ['parcelle' => 'BC 42', 'commune' => 'Lyon', 'surface_m2' => 60, 'source' => 'demo'],
        ],
        [
            'tenant' => 'acme', 'product' => 'atlas', 'by' => 'acme-user1',
            'name' => 'North wall',
            'description' => 'The scaffolding job.',
            'document' => ['source' => 'demo'],
        ],
        [
            'tenant' => 'globex', 'product' => 'boreas', 'by' => 'globex-user1',
            'name' => 'Site survey',
            'description' => 'First pass at the Globex yard.',
            'document' => ['source' => 'demo'],
        ],
    ];

    /** @return list<string> */
    public static function productCodes(): array
    {
        return array_keys(self::PRODUCTS);
    }

    /** The address a seeded person signs in with. */
    public static function email(string $who): string
    {
        return $who . '@' . self::EMAIL_DOMAIN;
    }
}
