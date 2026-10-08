<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * What the demonstration world contains — the definition, not the rows.
 *
 * **Five products, three organisations, nine people, five seats, one free
 * period, six projects.** The platform is multi-product, and a demo with one
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
 * **And money in it** (2026-09-26): two of the five seats are collected
 * through the payment chain rather than recorded as transfers, one of them
 * after an attempt the card refused, and one order is left owing with its
 * only attempt declined. Until then the world held five invoices and no
 * payment at all, so `/payments` was permanently empty in the one place the
 * platform is shown to people — and a screen that is always empty reads as a
 * feature that does not work.
 *
 * **And one person trying it for nothing** (2026-09-27, spec §6): Plan's
 * freemium, five days, one user, one project — the only subscription in the
 * world that raised no order, no invoice and no payment, because there was
 * nothing outstanding to raise one for.
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
     * beside the three every catalogue has: Starter's and Pro's limits, Scale
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
     * A product may also declare `plans` of its own, beside the
     * Starter/Pro/Scale ladder — Plan's Lecture seat is one: not a rung, a
     * different thing sold to somebody who reads. A capability's `from` may
     * therefore name one of those as readily as a rung, which is why it is
     * typed `string` here rather than the three words the ladder has. The
     * sentence below is the rule instead: `from` names a plan this product
     * sells, and `catalogue()` is where that is resolved.
     *
     * `order` is where the product sits in every list (2026-09-23), in tens
     * so one can be slipped between two others. Plan is first here, and
     * deliberately: it is the product deployed beside the platform, the one
     * whose card, link and subscription have something to show, and the
     * switcher's first option is what somebody lands on. The array is
     * written in that order too, so the file reads as the screen does.
     *
     * @var array<string, array{name: string, base: int, order: int, app_url: ?string, meters: array<string, array{name: string, unit: string, starter: int, pro: int}>, capabilities?: array<string, array{name: string, from: string}>, plans?: array<string, array{name: string, rank: int, price: int, period: string, renewal?: string, offer: array{code: string, name: string}, grants: array<string, int|null>}>}>
     */
    public const PRODUCTS = [
        'plan' => [
            'name' => 'Plan',
            'order' => 10,
            'base' => 1_500,
            'app_url' => 'https://plan.raillard.org',
            // Schema 2 since Plan 2.2.0 (the façade survey), schema 3 since the
            // roofs deduced from BD TOPO (Plan's `MD/spec-toit-ign.md`: a hipped
            // roof on every IGN building, and one on the demonstration's house),
            // schema 4 since the release that started saving it (2026-10-06:
            // every save was refused "accepted: 1, 2, 3" until it was added).
            // All four, and not just the newest: a document written by an
            // earlier release is still a document its owner opens, and a product
            // that stopped accepting the version it wrote last month would refuse
            // its own customers' work. Plan's `contrat/plan-produit.json` is
            // where this list comes from.
            'schema_versions' => [1, 2, 3, 4],
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
                // **From the first paid plan** (2026-10-01): the operator's own
                // answer when asked which offers include it — all of them. It is
                // what the product *is*, and a Plan that could not be looked at
                // in three dimensions is a different product. `from: 'pro'` sold
                // it as a step up, which is a decision about tiers rather than
                // about what Plan does.
                'plan.3d' => ['name' => '3D view and GLB viewer', 'from' => 'starter'],
                'plan.export.dxf' => ['name' => 'DXF export', 'from' => 'pro'],
                'plan.export.dossier' => ['name' => 'Client PDF dossier', 'from' => 'scale'],
                // The one that takes away rather than gives (2026-09-23). Every other capability
                // unlocks a function; this one tells the product that whoever holds it may look
                // and not change. It belongs to no tier, because it is not a smaller Plan — it is
                // a different seat, sold on its own plan below.
                'plan.readonly' => ['name' => 'Read-only seat', 'from' => 'lecture'],
            ],
            // Two plans of their own, outside the Starter/Pro/Scale ladder, because neither is a
            // rung on it. Only this product sells them, and that is the point of the key: whether
            // a product gives itself away, or sells a reader's seat, is a commercial decision of
            // whoever sells *it* — goûter Plan n'a jamais rien dit de Boreas (spec §6.4).
            'plans' => [
                // The free period (spec §6): the lowest rank there is, so leaving it is an upgrade
                // and nothing below it can be dropped to. Price 0 and `ENDS_AT_TERM` together are
                // what make it a freemium — no code anywhere asks whether a plan is *called* one.
                // How long it runs is `FREEMIUM_DAYS` below, in the product's configuration,
                // because `term_months` cannot say five days.
                'freemium' => [
                    'name' => 'Freemium', 'rank' => 10, 'price' => 0, 'period' => 'CUSTOM',
                    'renewal' => 'ENDS_AT_TERM',
                    'offer' => ['code' => 'freemium', 'name' => 'Freemium'],
                    // One person, one project — what the operator asked for. The quotas are the
                    // platform's own codes, so the workspace enforces them: `max_projects` is what
                    // it asks before storing a project and `users` what bounds the people covered.
                    //
                    // And the 3D view, because a free period that cannot show what
                    // the product is shows nothing (2026-10-01). It is outside the
                    // ladder, so it is named here rather than inherited.
                    'grants' => ['users' => 1, 'max_projects' => 1, 'plan.terrasse' => null, 'plan.3d' => null],
                ],
                // Somebody who consults a plan and never draws one. Sold per seat, so a colleague
                // who reads costs a fraction of one who works.
                'lecture' => [
                    'name' => 'Lecture', 'rank' => 20, 'price' => 500, 'period' => 'MONTHLY',
                    'offer' => ['code' => 'lecture-monthly', 'name' => 'Lecture, monthly'],
                    // Everything one needs to look at a plan, and `plan.readonly` to say that
                    // looking is all. No quota: a reader stores nothing to count.
                    'grants' => [
                        'plan.readonly' => null, 'plan.terrasse' => null, 'plan.cadastre' => null,
                        'plan.ortho' => null, 'plan.plu' => null, 'plan.3d' => null,
                    ],
                ],
            ],
        ],
        'atlas' => ['name' => 'Atlas', 'order' => 20, 'base' => 1_900, 'app_url' => null, 'meters' => []],
        'boreas' => ['name' => 'Boreas', 'order' => 30, 'base' => 2_900, 'app_url' => null, 'meters' => []],
        'ceres' => ['name' => 'Ceres', 'order' => 40, 'base' => 900, 'app_url' => null, 'meters' => []],
        'delos' => ['name' => 'Delos', 'order' => 50, 'base' => 4_900, 'app_url' => null, 'meters' => []],
    ];

    /**
     * Where the ladder's three rungs sit (2026-09-27, spec §6.2).
     *
     * **The lowest rank is 10 and they step by 10**, which is the convention
     * every ordered list in this world follows — products, showcase bands,
     * display orders — so that one can be slipped between two others without
     * renumbering anything. Starter, Pro and Scale stood at 10, 20 and 30, and
     * *Lecture* was squeezed underneath at 5 for want of room. The freemium is
     * lower still, so the whole ladder moved up a notch and the bottom of it is
     * 10 again: freemium 10, lecture 20, starter 30, pro 40, scale 50.
     *
     * **One table for the platform, not one per product.** A rank is a tier, so
     * `starter` means the same rung wherever it is sold; the same code sitting
     * at 30 in one product and 10 in another would make every cross-product
     * reading of a catalogue a translation. A product sells a subset of the
     * ladder and adds plans of its own beside it, which is what `plans` above
     * is for, and the bottom of *this* product's list is not the bottom of the
     * scale.
     *
     * Nothing in the database constrains `rank` (only `plans_code_unique` on
     * `(product_id, code)`): it is an ordering, read by
     * {@see \App\Commerce\Service\Subscriptions::directionBetween()} to decide
     * upgrade from downgrade. So renumbering is a change of constants here and
     * **no migration**.
     *
     * @var array<string, array{name: string, rank: int}>
     */
    public const LADDER = [
        'starter' => ['name' => 'Starter', 'rank' => 30],
        'pro' => ['name' => 'Pro', 'rank' => 40],
        'scale' => ['name' => 'Scale', 'rank' => 50],
    ];

    /**
     * How long the demonstration's free period runs: **five days** (spec §6.1).
     *
     * It is written into `product_configuration` and read from there
     * ({@see \App\Commerce\Domain\FreemiumPeriod}), not compiled into the
     * subscription path — `term_months` cannot say five days, and a constant in
     * PHP is a commercial term an operator cannot change without a release.
     * Five is this world's answer; another deployment's is its own.
     */
    public const FREEMIUM_DAYS = 5;

    /**
     * The three features every catalogue grants, by the codes the platform
     * enforces: `max_projects` is what the workspace asks before it stores a
     * project ({@see \App\Project\Service\ProjectWorkspace::QUOTA}), `users`
     * what bounds a subscription's people. Until 2026-09-22 the first was
     * seeded as `projects`, which nothing reads — so the demo's subscribers
     * held a quota of projects and could create none. `white_label` stood
     * here until 2026-10-05: the logo is the organisation's now, under no
     * offer, and the feature is retired.
     *
     * @var array<string, array{name: string, kind: 'QUOTA', unit: string, starter: int, pro: int}>
     */
    public const FEATURES = [
        'max_projects' => ['name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects', 'starter' => 3, 'pro' => 25],
        'exports' => ['name' => 'Exports', 'kind' => 'QUOTA', 'unit' => 'exports', 'starter' => 10, 'pro' => 200],
        // `users` (2026-09-19) is what bounds a subscription's people: Starter
        // covers its buyer, Pro three, Scale everybody.
        'users' => ['name' => 'Users', 'kind' => 'QUOTA', 'unit' => 'users', 'starter' => 1, 'pro' => 3],
    ];

    /**
     * What a feature says beside its name, in English (2026-09-24).
     *
     * Only the three every catalogue grants: they are what a customer reads
     * on a pricing page, so they are where a description earns its place. A
     * product's own capabilities are named and not explained here — what
     * `plan.terrasse` *does* is Plan's documentation, not the platform's.
     *
     * @var array<string, string>
     */
    public const FEATURE_DESCRIPTIONS = [
        'max_projects' => 'How many plans you may keep at once.',
        'exports' => 'How many files you may export each month.',
        'users' => 'How many colleagues the subscription covers.',
    ];

    /**
     * The catalogue in the four other languages (2026-09-24, step 6 of
     * `docs/translatable-fields-spec.md`).
     *
     * This is **operator data**, not an application sentence: it belongs in
     * `feature_translations`, written by the seeder, and never in
     * `frontend/src/i18n/catalogues/` — the two systems are kept apart on
     * purpose (§1.5 of the spec). A feature name shipped in the bundle
     * would be one deployment's business in everybody's build.
     *
     * Present for every code the demonstration seeds, the products' own
     * included: a French reader switching the interface should see a French
     * catalogue rather than an English one with French buttons around it,
     * and that is also the only way anybody notices this works. A
     * description is translated where there is one to translate.
     *
     * @var array<string, array<string, array{name: string, description?: string}>>
     */
    public const FEATURE_TRANSLATIONS = [
        'max_projects' => [
            'fr' => ['name' => 'Projets', 'description' => 'Combien de plans vous pouvez garder en même temps.'],
            'es' => ['name' => 'Proyectos', 'description' => 'Cuántos planos puede conservar a la vez.'],
            'de' => ['name' => 'Projekte', 'description' => 'Wie viele Pläne Sie gleichzeitig behalten dürfen.'],
            'it' => ['name' => 'Progetti', 'description' => 'Quanti progetti può conservare alla volta.'],
        ],
        'exports' => [
            'fr' => ['name' => 'Exports', 'description' => 'Combien de fichiers vous pouvez exporter chaque mois.'],
            'es' => ['name' => 'Exportaciones', 'description' => 'Cuántos archivos puede exportar cada mes.'],
            'de' => ['name' => 'Exporte', 'description' => 'Wie viele Dateien Sie jeden Monat exportieren dürfen.'],
            'it' => ['name' => 'Esportazioni', 'description' => 'Quanti file può esportare ogni mese.'],
        ],
        'users' => [
            'fr' => ['name' => 'Utilisateurs', 'description' => 'Combien de collègues l’abonnement couvre.'],
            'es' => ['name' => 'Usuarios', 'description' => 'A cuántos compañeros cubre la suscripción.'],
            'de' => ['name' => 'Nutzer', 'description' => 'Wie viele Kollegen das Abonnement abdeckt.'],
            'it' => ['name' => 'Utenti', 'description' => 'Quanti colleghi copre l’abbonamento.'],
        ],

        // Plan's own words. The codes are the product's and the platform
        // only carries them; what they are *called* is still read by a
        // person, in their language.
        'plan.documents' => [
            'fr' => ['name' => 'Documents Plan'],
            'es' => ['name' => 'Documentos Plan'],
            'de' => ['name' => 'Plan-Dokumente'],
            'it' => ['name' => 'Documenti Plan'],
        ],
        'plan.terrasse' => [
            'fr' => ['name' => 'Moteur de terrasse'],
            'es' => ['name' => 'Motor de terraza'],
            'de' => ['name' => 'Terrassenmodul'],
            'it' => ['name' => 'Motore terrazza'],
        ],
        'plan.cadastre' => [
            'fr' => ['name' => 'Import cadastre (IGN)'],
            'es' => ['name' => 'Importación catastral (IGN)'],
            'de' => ['name' => 'Katasterimport (IGN)'],
            'it' => ['name' => 'Importazione catastale (IGN)'],
        ],
        'plan.ortho' => [
            'fr' => ['name' => 'Vue aérienne (IGN)'],
            'es' => ['name' => 'Imagen aérea (IGN)'],
            'de' => ['name' => 'Luftbild (IGN)'],
            'it' => ['name' => 'Ortofoto (IGN)'],
        ],
        'plan.plu' => [
            'fr' => ['name' => 'Règles d’urbanisme (PLU)'],
            'es' => ['name' => 'Normas urbanísticas (PLU)'],
            'de' => ['name' => 'Bauvorschriften (PLU)'],
            'it' => ['name' => 'Regole urbanistiche (PLU)'],
        ],
        'plan.3d' => [
            'fr' => ['name' => 'Vue 3D et visionneuse GLB'],
            'es' => ['name' => 'Vista 3D y visor GLB'],
            'de' => ['name' => '3D-Ansicht und GLB-Viewer'],
            'it' => ['name' => 'Vista 3D e visualizzatore GLB'],
        ],
        'plan.export.dxf' => [
            'fr' => ['name' => 'Export DXF'],
            'es' => ['name' => 'Exportación DXF'],
            'de' => ['name' => 'DXF-Export'],
            'it' => ['name' => 'Esportazione DXF'],
        ],
        'plan.export.dossier' => [
            'fr' => ['name' => 'Dossier PDF client'],
            'es' => ['name' => 'Dosier PDF para el cliente'],
            'de' => ['name' => 'Kunden-PDF-Dossier'],
            'it' => ['name' => 'Dossier PDF cliente'],
        ],
        'plan.readonly' => [
            'fr' => ['name' => 'Siège en lecture seule'],
            'es' => ['name' => 'Asiento de solo lectura'],
            'de' => ['name' => 'Platz mit Lesezugriff'],
            'it' => ['name' => 'Postazione in sola lettura'],
        ],
    ];

    /**
     * What each offer is called in the four other languages, by offer code.
     *
     * A translation of an offer's *name* is not a term (ADR-033): the price
     * and the conditions of a published version are frozen, and saying the
     * same offer in another language changes neither.
     *
     * @var array<string, array<string, string>>
     */
    public const OFFER_TRANSLATIONS = [
        'starter-monthly' => [
            'fr' => 'Starter, mensuel',
            'es' => 'Starter, mensual',
            'de' => 'Starter, monatlich',
            'it' => 'Starter, mensile',
        ],
        'pro-monthly' => [
            'fr' => 'Pro, mensuel',
            'es' => 'Pro, mensual',
            'de' => 'Pro, monatlich',
            'it' => 'Pro, mensile',
        ],
        'scale-yearly' => [
            'fr' => 'Scale, annuel',
            'es' => 'Scale, anual',
            'de' => 'Scale, jährlich',
            'it' => 'Scale, annuale',
        ],
        'lecture-monthly' => [
            'fr' => 'Lecture, mensuel',
            'es' => 'Lectura, mensual',
            'de' => 'Lesen, monatlich',
            'it' => 'Lettura, mensile',
        ],
        // The same word in all four, because it is the same word in all four:
        // *freemium* is the borrowed term French, Spanish, German and Italian
        // catalogues all use. Written out rather than left absent — the seeder
        // requires four translations per offer, and an offer nobody translated
        // still renders, in English, with French buttons around it.
        'freemium' => [
            'fr' => 'Freemium',
            'es' => 'Freemium',
            'de' => 'Freemium',
            'it' => 'Freemium',
        ],
    ];

    /**
     * What each of Plan's bands is called (2026-09-28).
     *
     * **A heading is one fact per band**, where {@see SHOWCASE} is one per
     * row: three steps are three entries there and one here, because the
     * section is called "How it works" once. `PRICING` is in this list and
     * in no other — it has something to say about its prices and no row
     * anybody could type one into (§9).
     *
     * A band left out reads the words its component was written with, which
     * is the state every other product is in.
     *
     * @var array<string, array<string, array{content: array<string, string>, translations: array<string, array<string, string>>}>>
     */
    public const SHOWCASE_HEADINGS = [
        'plan' => [
            'HEADLINE' => [
                'content' => [
                    'eyebrow' => 'From an address to a plan',
                ],
                'translations' => [
                    'fr' => [
                        'eyebrow' => 'D’une adresse à un plan',
                    ],
                    'es' => [
                        'eyebrow' => 'De una dirección a un plano',
                    ],
                    'de' => [
                        'eyebrow' => 'Von einer Adresse zum Plan',
                    ],
                    'it' => [
                        'eyebrow' => 'Da un indirizzo a un piano',
                    ],
                ],
            ],
            'PROBLEM' => [
                'content' => [
                    'eyebrow' => 'The problem',
                    'title' => 'Before drawing anything, you redraw what already exists.',
                ],
                'translations' => [
                    'fr' => [
                        'eyebrow' => 'Le problème',
                        'title' => 'Avant de dessiner quoi que ce soit, on redessine ce qui existe déjà.',
                    ],
                    'es' => [
                        'eyebrow' => 'El problema',
                        'title' => 'Antes de dibujar nada, se vuelve a dibujar lo que ya existe.',
                    ],
                    'de' => [
                        'eyebrow' => 'Das Problem',
                        'title' => 'Bevor man etwas zeichnet, zeichnet man nach, was es schon gibt.',
                    ],
                    'it' => [
                        'eyebrow' => 'Il problema',
                        'title' => 'Prima di disegnare qualsiasi cosa, si ridisegna ciò che esiste già.',
                    ],
                ],
            ],
            'STEPS' => [
                'content' => [
                    'eyebrow' => 'Three moves',
                    'title' => 'Type, look, place.',
                    'lede' => 'No training, no template. The address does the drawing you used to do by hand.',
                ],
                'translations' => [
                    'fr' => [
                        'eyebrow' => 'Trois gestes',
                        'title' => 'Tapez, regardez, posez.',
                        'lede' => 'Aucune formation, aucun gabarit. L’adresse fait le dessin que vous faisiez à la main.',
                    ],
                    'es' => [
                        'eyebrow' => 'Tres gestos',
                        'title' => 'Escriba, mire, coloque.',
                        'lede' => 'Sin formación, sin plantilla. La dirección hace el dibujo que usted hacía a mano.',
                    ],
                    'de' => [
                        'eyebrow' => 'Drei Handgriffe',
                        'title' => 'Tippen, schauen, setzen.',
                        'lede' => 'Keine Schulung, keine Vorlage. Die Adresse zeichnet, was Sie bisher von Hand zeichneten.',
                    ],
                    'it' => [
                        'eyebrow' => 'Tre gesti',
                        'title' => 'Scrivi, guarda, posa.',
                        'lede' => 'Nessuna formazione, nessun modello. L’indirizzo fa il disegno che facevi a mano.',
                    ],
                ],
            ],
            'USE_CASE' => [
                'content' => [
                    'eyebrow' => 'Who it is for',
                    'title' => 'On site, on a phone, with the client beside you.',
                ],
                'translations' => [
                    'fr' => [
                        'eyebrow' => 'Pour qui',
                        'title' => 'Sur le terrain, sur un téléphone, le client à côté de vous.',
                    ],
                    'es' => [
                        'eyebrow' => 'Para quién',
                        'title' => 'Sobre el terreno, en el móvil, con el cliente al lado.',
                    ],
                    'de' => [
                        'eyebrow' => 'Für wen',
                        'title' => 'Vor Ort, auf dem Handy, mit dem Kunden daneben.',
                    ],
                    'it' => [
                        'eyebrow' => 'Per chi',
                        'title' => 'Sul posto, sul telefono, con il cliente accanto.',
                    ],
                ],
            ],
            'PROOF' => [
                'content' => [
                    'eyebrow' => 'See it',
                    'title' => 'Real plots, as they come out of it.',
                ],
                'translations' => [
                    'fr' => [
                        'eyebrow' => 'Voyez-le',
                        'title' => 'De vraies parcelles, telles qu’elles en sortent.',
                    ],
                    'es' => [
                        'eyebrow' => 'Véalo',
                        'title' => 'Parcelas reales, tal como salen.',
                    ],
                    'de' => [
                        'eyebrow' => 'Sehen Sie selbst',
                        'title' => 'Echte Grundstücke, so wie sie herauskommen.',
                    ],
                    'it' => [
                        'eyebrow' => 'Guarda',
                        'title' => 'Particelle vere, così come escono.',
                    ],
                ],
            ],
            'DEMO' => [
                'content' => [
                    'eyebrow' => 'Try it',
                    'title' => 'Open it and move a corner.',
                ],
                'translations' => [
                    'fr' => [
                        'eyebrow' => 'Essayez',
                        'title' => 'Ouvrez-la et déplacez un coin.',
                    ],
                    'es' => [
                        'eyebrow' => 'Pruébelo',
                        'title' => 'Ábrala y mueva una esquina.',
                    ],
                    'de' => [
                        'eyebrow' => 'Probieren Sie es',
                        'title' => 'Öffnen Sie sie und verschieben Sie eine Ecke.',
                    ],
                    'it' => [
                        'eyebrow' => 'Provatela',
                        'title' => 'Apritela e spostate un angolo.',
                    ],
                ],
            ],
            'PRICING' => [
                'content' => [
                    'title' => 'The first plan costs nothing.',
                    'lede' => 'Draw it in full, print it. You pay only if you make a second one.',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Le premier plan ne coûte rien.',
                        'lede' => 'Dessinez-le en entier, imprimez-le. Vous ne payez que si vous en faites un deuxième.',
                    ],
                    'es' => [
                        'title' => 'El primer plano no cuesta nada.',
                        'lede' => 'Dibújelo entero, imprímalo. Solo paga si hace un segundo.',
                    ],
                    'de' => [
                        'title' => 'Der erste Plan kostet nichts.',
                        'lede' => 'Zeichnen Sie ihn ganz, drucken Sie ihn. Sie zahlen erst für den zweiten.',
                    ],
                    'it' => [
                        'title' => 'Il primo piano non costa nulla.',
                        'lede' => 'Disegnalo per intero, stampalo. Paghi solo se ne fai un secondo.',
                    ],
                ],
            ],
            'QUESTION' => [
                'content' => [
                    'title' => 'The questions we are asked',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Les questions qu’on nous pose',
                    ],
                    'es' => [
                        'title' => 'Las preguntas que nos hacen',
                    ],
                    'de' => [
                        'title' => 'Die Fragen, die uns gestellt werden',
                    ],
                    'it' => [
                        'title' => 'Le domande che ci fanno',
                    ],
                ],
            ],
        ],
    ];

    /**
     * The story Plan tells on its own page (2026-09-24, step 5 of
     * `docs/home-showcase-spec.md`).
     *
     * **Written for real, and in five languages.** A demonstration whose
     * shop window said *lorem ipsum* would let the page be judged on its
     * layout and never on whether it works; one written only in English
     * would demonstrate the fallback rather than the feature (§11.2 keeps
     * the fallback for *operators*, who publish as they go — the demo does
     * not get to lean on it).
     *
     * One product only. Plan is the one deployed beside the platform and
     * the one with something concrete to say; giving Atlas, Boreas, Ceres
     * and Delos invented stories would be four pages of filler and would
     * also hide the honest case — **a product that has published nothing
     * shows its name and its prices**, which is a page and not a hole.
     *
     * The order within each band is the order they are written here.
     *
     * **The pictures are real** (2026-10-08): screenshots of Plan on two
     * of the demonstration's own projects — a house at Le Vésinet and a
     * village at Peyrusse-le-Roc, on a phone and on a desktop — shipped in
     * {@see PICTURES_DIR} and named by file on the rows that show them. The
     * story the page tells is the address: type it, the plot and the
     * relief arrive, then the terrace, the pool and the pergola go on.
     *
     * @var array<string, list<array{block: string, content: array<string, string>, translations: array<string, array<string, string>>, picture?: string}>>
     */
    public const SHOWCASE = [
        'plan' => [
            [
                'block' => 'HEADLINE',
                'content' => [
                    'headline' => 'Your address. Your plot, your relief, your terrace.',
                    'subline' => 'Type the address: the cadastral plot, the neighbours’ buildings and the terrain arrive by themselves. Then place a terrace, a pool, a pergola — in 3D, over the real aerial photo.',
                    'reassurance' => 'Nothing to install. The first plan costs nothing.',
                    'alt' => 'A house and its garden in 3D over the aerial photo, drawn from one address',
                ],
                'translations' => [
                    'fr' => [
                        'headline' => 'Votre adresse. Votre parcelle, votre relief, votre terrasse.',
                        'subline' => 'Tapez l’adresse : la parcelle cadastrale, les bâtiments voisins et le relief arrivent tout seuls. Puis posez une terrasse, une piscine, une pergola — en 3D, sur la vraie photo aérienne.',
                        'reassurance' => 'Rien à installer. Le premier plan ne coûte rien.',
                        'alt' => 'Une maison et son jardin en 3D sur la photo aérienne, dessinés depuis une adresse',
                    ],
                    'es' => [
                        'headline' => 'Su dirección. Su parcela, su relieve, su terraza.',
                        'subline' => 'Escriba la dirección: la parcela catastral, los edificios vecinos y el terreno llegan solos. Luego coloque una terraza, una piscina, una pérgola — en 3D, sobre la foto aérea real.',
                        'reassurance' => 'Nada que instalar. El primer plano no cuesta nada.',
                        'alt' => 'Una casa y su jardín en 3D sobre la foto aérea, dibujados desde una dirección',
                    ],
                    'de' => [
                        'headline' => 'Ihre Adresse. Ihr Grundstück, Ihr Gelände, Ihre Terrasse.',
                        'subline' => 'Adresse eintippen: Katasterparzelle, Nachbargebäude und Gelände kommen von allein. Dann Terrasse, Pool, Pergola setzen — in 3D, auf dem echten Luftbild.',
                        'reassurance' => 'Nichts zu installieren. Der erste Plan kostet nichts.',
                        'alt' => 'Ein Haus und sein Garten in 3D auf dem Luftbild, aus einer Adresse gezeichnet',
                    ],
                    'it' => [
                        'headline' => 'Il tuo indirizzo. La tua particella, il tuo rilievo, la tua terrazza.',
                        'subline' => 'Scrivi l’indirizzo: la particella catastale, gli edifici vicini e il terreno arrivano da soli. Poi posa una terrazza, una piscina, una pergola — in 3D, sulla vera foto aerea.',
                        'reassurance' => 'Niente da installare. Il primo piano non costa nulla.',
                        'alt' => 'Una casa e il suo giardino in 3D sulla foto aerea, disegnati da un indirizzo',
                    ],
                ],
                'picture' => 'hero-vesinet-3d.webp',
            ],
            [
                'block' => 'PROBLEM',
                'content' => [
                    'title' => 'The plot, measured once more',
                    'body' => 'A tape measure in the garden, a sketch on paper, and boundaries off by a metre.',
                    'icon' => 'ruler',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'La parcelle, mesurée encore une fois',
                        'body' => 'Un mètre ruban dans le jardin, un croquis sur papier, et des limites fausses d’un mètre.',
                    ],
                    'es' => [
                        'title' => 'La parcela, medida una vez más',
                        'body' => 'Una cinta métrica en el jardín, un croquis en papel y unos límites con un metro de error.',
                    ],
                    'de' => [
                        'title' => 'Das Grundstück, noch einmal vermessen',
                        'body' => 'Ein Maßband im Garten, eine Skizze auf Papier, und Grenzen, die um einen Meter danebenliegen.',
                    ],
                    'it' => [
                        'title' => 'La particella, misurata ancora una volta',
                        'body' => 'Un metro a nastro in giardino, uno schizzo su carta, e confini sbagliati di un metro.',
                    ],
                ],
            ],
            [
                'block' => 'PROBLEM',
                'content' => [
                    'title' => 'A slope nobody drew',
                    'body' => 'The terrace is planned flat; the ground is not. The surprise arrives with the digger.',
                    'icon' => 'warning',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Une pente que personne n’a dessinée',
                        'body' => 'La terrasse est prévue à plat ; le terrain ne l’est pas. La surprise arrive avec la pelleteuse.',
                    ],
                    'es' => [
                        'title' => 'Una pendiente que nadie dibujó',
                        'body' => 'La terraza se planea plana; el terreno no lo es. La sorpresa llega con la excavadora.',
                    ],
                    'de' => [
                        'title' => 'Ein Gefälle, das niemand gezeichnet hat',
                        'body' => 'Die Terrasse ist eben geplant; der Boden ist es nicht. Die Überraschung kommt mit dem Bagger.',
                    ],
                    'it' => [
                        'title' => 'Una pendenza che nessuno ha disegnato',
                        'body' => 'La terrazza è prevista in piano; il terreno no. La sorpresa arriva con l’escavatore.',
                    ],
                ],
            ],
            [
                'block' => 'PROBLEM',
                'content' => [
                    'title' => 'A client who cannot picture it',
                    'body' => 'A pencil plan is not signed. It is discussed, at length, and drawn again.',
                    'icon' => 'paper',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Un client qui ne se représente rien',
                        'body' => 'Un plan au crayon ne se signe pas. Il se discute, longtemps, puis se refait.',
                    ],
                    'es' => [
                        'title' => 'Un cliente que no se lo imagina',
                        'body' => 'Un plano a lápiz no se firma. Se discute, largamente, y se vuelve a dibujar.',
                    ],
                    'de' => [
                        'title' => 'Ein Kunde, der es sich nicht vorstellen kann',
                        'body' => 'Ein Bleistiftplan wird nicht unterschrieben. Er wird lange besprochen und neu gezeichnet.',
                    ],
                    'it' => [
                        'title' => 'Un cliente che non riesce a immaginarlo',
                        'body' => 'Un piano a matita non si firma. Si discute, a lungo, e si ridisegna.',
                    ],
                ],
            ],
            [
                'block' => 'STEPS',
                'content' => [
                    'title' => 'Type the address',
                    'body' => 'The cadastral plot comes in with its boundaries, the neighbouring buildings and the aerial photo underneath. Or trace it yourself.',
                    'alt' => 'The dialog that starts a project from an address',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Tapez l’adresse',
                        'body' => 'La parcelle cadastrale arrive avec ses limites, les bâtiments voisins et la photo aérienne dessous. Ou tracez-la vous-même.',
                        'alt' => 'La fenêtre qui crée un projet depuis une adresse',
                    ],
                    'es' => [
                        'title' => 'Escriba la dirección',
                        'body' => 'La parcela catastral llega con sus límites, los edificios vecinos y la foto aérea debajo. O trácela usted mismo.',
                        'alt' => 'La ventana que crea un proyecto desde una dirección',
                    ],
                    'de' => [
                        'title' => 'Adresse eintippen',
                        'body' => 'Die Katasterparzelle kommt mit ihren Grenzen, den Nachbargebäuden und dem Luftbild darunter. Oder zeichnen Sie sie selbst.',
                        'alt' => 'Das Fenster, das ein Projekt aus einer Adresse anlegt',
                    ],
                    'it' => [
                        'title' => 'Scrivi l’indirizzo',
                        'body' => 'La particella catastale arriva con i suoi confini, gli edifici vicini e la foto aerea sotto. Oppure tracciala tu stesso.',
                        'alt' => 'La finestra che crea un progetto da un indirizzo',
                    ],
                ],
                'picture' => 'step-adresse.webp',
            ],
            [
                'block' => 'STEPS',
                'content' => [
                    'title' => 'The relief comes with it',
                    'body' => 'Contour lines, levels and slopes are read from the IGN terrain model. A plot on a hillside looks like one, in plan and in 3D.',
                    'alt' => 'A hillside village in 3D, its houses standing on the real terrain',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Le relief vient avec',
                        'body' => 'Courbes de niveau, altitudes et pentes sont lues dans le modèle de terrain de l’IGN. Une parcelle à flanc de colline y ressemble, en plan comme en 3D.',
                        'alt' => 'Un village à flanc de colline en 3D, ses maisons posées sur le vrai relief',
                    ],
                    'es' => [
                        'title' => 'El relieve viene con ella',
                        'body' => 'Curvas de nivel, cotas y pendientes se leen del modelo de terreno del IGN. Una parcela en ladera lo parece, en plano y en 3D.',
                        'alt' => 'Un pueblo en ladera en 3D, con sus casas sobre el terreno real',
                    ],
                    'de' => [
                        'title' => 'Das Gelände kommt mit',
                        'body' => 'Höhenlinien, Höhen und Gefälle werden aus dem IGN-Geländemodell gelesen. Ein Hanggrundstück sieht aus wie eines, im Plan wie in 3D.',
                        'alt' => 'Ein Dorf am Hang in 3D, seine Häuser auf dem echten Gelände',
                    ],
                    'it' => [
                        'title' => 'Il rilievo arriva insieme',
                        'body' => 'Curve di livello, quote e pendenze si leggono dal modello di terreno dell’IGN. Una particella su un pendio sembra tale, in pianta come in 3D.',
                        'alt' => 'Un villaggio su un pendio in 3D, con le case sul terreno reale',
                    ],
                ],
                'picture' => 'step-relief-peyrusse-3d.webp',
            ],
            [
                'block' => 'STEPS',
                'content' => [
                    'title' => 'Place the terrace, the pool, the pergola',
                    'body' => 'Drag them into place. Dimensions, levels and the price range are computed while you move them.',
                    'alt' => 'A terrace, a spa and a parasol on the plot, with their dimensions',
                ],
                'translations' => [
                    'fr' => [
                        'title' => 'Posez la terrasse, la piscine, la pergola',
                        'body' => 'Glissez-les en place. Cotes, niveaux et fourchette de prix se calculent pendant que vous les déplacez.',
                        'alt' => 'Une terrasse, un spa et un parasol sur la parcelle, avec leurs cotes',
                    ],
                    'es' => [
                        'title' => 'Coloque la terraza, la piscina, la pérgola',
                        'body' => 'Arrástrelas a su sitio. Cotas, niveles y rango de precio se calculan mientras las mueve.',
                        'alt' => 'Una terraza, un spa y una sombrilla en la parcela, con sus cotas',
                    ],
                    'de' => [
                        'title' => 'Terrasse, Pool, Pergola setzen',
                        'body' => 'Ziehen Sie sie an ihren Platz. Maße, Höhen und Preisspanne werden berechnet, während Sie sie bewegen.',
                        'alt' => 'Eine Terrasse, ein Spa und ein Sonnenschirm auf dem Grundstück, mit Maßen',
                    ],
                    'it' => [
                        'title' => 'Posa la terrazza, la piscina, la pergola',
                        'body' => 'Trascinali al loro posto. Quote, livelli e fascia di prezzo si calcolano mentre li sposti.',
                        'alt' => 'Una terrazza, una spa e un ombrellone sulla particella, con le quote',
                    ],
                ],
                'picture' => 'step-terrasse-vesinet-plan.webp',
            ],
            [
                'block' => 'USE_CASE',
                'content' => [
                    'who' => 'A homeowner preparing a planning declaration',
                    'before' => 'A terrace drawn by hand on a cadastral printout, and a town hall that sends the file back.',
                    'after' => 'The plot, the terrace with its 35 m² and its price range, and the declaration form filled from the same drawing.',
                    'alt' => 'On a phone: the terrace, its surface, its finished height and its estimated price',
                ],
                'translations' => [
                    'fr' => [
                        'who' => 'Un particulier qui prépare sa déclaration préalable',
                        'before' => 'Une terrasse dessinée à la main sur un extrait cadastral, et une mairie qui renvoie le dossier.',
                        'after' => 'La parcelle, la terrasse avec ses 35 m² et sa fourchette de prix, et le cerfa rempli depuis le même dessin.',
                        'alt' => 'Sur un téléphone : la terrasse, sa surface, sa hauteur finie et son estimation',
                    ],
                    'es' => [
                        'who' => 'Un particular que prepara su declaración de obras',
                        'before' => 'Una terraza dibujada a mano sobre un extracto catastral, y un ayuntamiento que devuelve el expediente.',
                        'after' => 'La parcela, la terraza con sus 35 m² y su rango de precio, y el formulario rellenado desde el mismo dibujo.',
                        'alt' => 'En el móvil: la terraza, su superficie, su altura final y su estimación',
                    ],
                    'de' => [
                        'who' => 'Ein Eigentümer, der seine Bauanzeige vorbereitet',
                        'before' => 'Eine von Hand auf einen Katasterauszug gezeichnete Terrasse, und ein Rathaus, das die Akte zurückschickt.',
                        'after' => 'Das Grundstück, die Terrasse mit ihren 35 m² und ihrer Preisspanne, und das Formular aus derselben Zeichnung ausgefüllt.',
                        'alt' => 'Auf dem Handy: die Terrasse, ihre Fläche, ihre fertige Höhe und ihre Schätzung',
                    ],
                    'it' => [
                        'who' => 'Un privato che prepara la sua dichiarazione preventiva',
                        'before' => 'Una terrazza disegnata a mano su un estratto catastale, e un comune che rimanda indietro la pratica.',
                        'after' => 'La particella, la terrazza con i suoi 35 m² e la sua fascia di prezzo, e il modulo compilato dallo stesso disegno.',
                        'alt' => 'Sul telefono: la terrazza, la sua superficie, l’altezza finita e la stima',
                    ],
                ],
                'picture' => 'usecase-telephone-chiffrage.webp',
            ],
            [
                'block' => 'USE_CASE',
                'content' => [
                    'who' => 'A landscaper quoting on a sloping plot',
                    'before' => 'An afternoon of measuring, and the slope discovered at the first dig.',
                    'after' => 'The relief is on screen before the coffee is finished, and the second version takes three minutes.',
                    'alt' => 'On a phone: a village on its hillside in 3D',
                ],
                'translations' => [
                    'fr' => [
                        'who' => 'Un paysagiste qui chiffre un terrain en pente',
                        'before' => 'Un après-midi de mesures, et la pente découverte au premier coup de pelle.',
                        'after' => 'Le relief est à l’écran avant la fin du café, et la deuxième version prend trois minutes.',
                        'alt' => 'Sur un téléphone : un village sur son flanc de colline, en 3D',
                    ],
                    'es' => [
                        'who' => 'Un paisajista que presupuesta un terreno en pendiente',
                        'before' => 'Una tarde de mediciones, y la pendiente descubierta al primer golpe de pala.',
                        'after' => 'El relieve está en pantalla antes de acabar el café, y la segunda versión lleva tres minutos.',
                        'alt' => 'En el móvil: un pueblo en su ladera, en 3D',
                    ],
                    'de' => [
                        'who' => 'Ein Landschaftsbauer, der ein Hanggrundstück kalkuliert',
                        'before' => 'Ein Nachmittag Messen, und das Gefälle beim ersten Spatenstich entdeckt.',
                        'after' => 'Das Gelände ist auf dem Bildschirm, bevor der Kaffee ausgetrunken ist, und die zweite Fassung dauert drei Minuten.',
                        'alt' => 'Auf dem Handy: ein Dorf an seinem Hang, in 3D',
                    ],
                    'it' => [
                        'who' => 'Un paesaggista che fa un preventivo su un terreno in pendenza',
                        'before' => 'Un pomeriggio di misure, e la pendenza scoperta al primo colpo di vanga.',
                        'after' => 'Il rilievo è sullo schermo prima che finisca il caffè, e la seconda versione richiede tre minuti.',
                        'alt' => 'Sul telefono: un villaggio sul suo pendio, in 3D',
                    ],
                ],
                'picture' => 'usecase-telephone-relief.webp',
            ],
            [
                'block' => 'QUOTE',
                'content' => [
                    'quote' => 'The quotation goes out the same evening, from the same drawing. I have stopped redrawing at the kitchen table.',
                    'author' => 'Head of a three-person landscaping firm',
                ],
                'translations' => [
                    'fr' => [
                        'quote' => 'Le devis part le soir même, depuis le même dessin. J’ai arrêté de redessiner à la table de la cuisine.',
                        'author' => 'Gérant d’une entreprise de paysage de trois personnes',
                    ],
                    'es' => [
                        'quote' => 'El presupuesto sale esa misma tarde, del mismo dibujo. He dejado de redibujar en la mesa de la cocina.',
                        'author' => 'Responsable de una empresa de paisajismo de tres personas',
                    ],
                    'de' => [
                        'quote' => 'Das Angebot geht noch am selben Abend raus, aus derselben Zeichnung. Ich zeichne nicht mehr am Küchentisch nach.',
                        'author' => 'Inhaber eines Garten- und Landschaftsbaubetriebs mit drei Leuten',
                    ],
                    'it' => [
                        'quote' => 'Il preventivo parte la sera stessa, dallo stesso disegno. Ho smesso di ridisegnare al tavolo di cucina.',
                        'author' => 'Titolare di un’impresa di giardinaggio di tre persone',
                    ],
                ],
            ],
            [
                'block' => 'PROOF',
                'content' => [
                    'caption' => 'Peyrusse-le-Roc, Aveyron: the plot, the village around it and the contour lines, straight from the cadastre and the IGN terrain model.',
                    'alt' => 'A hillside village in plan, with its contour lines over the aerial photo',
                ],
                'translations' => [
                    'fr' => [
                        'caption' => 'Peyrusse-le-Roc, Aveyron : la parcelle, le village autour et les courbes de niveau, directement du cadastre et du modèle de terrain de l’IGN.',
                        'alt' => 'Un village à flanc de colline en plan, avec ses courbes de niveau sur la photo aérienne',
                    ],
                    'es' => [
                        'caption' => 'Peyrusse-le-Roc, Aveyron: la parcela, el pueblo alrededor y las curvas de nivel, directamente del catastro y del modelo de terreno del IGN.',
                        'alt' => 'Un pueblo en ladera en plano, con sus curvas de nivel sobre la foto aérea',
                    ],
                    'de' => [
                        'caption' => 'Peyrusse-le-Roc, Aveyron: das Grundstück, das Dorf drumherum und die Höhenlinien, direkt aus Kataster und IGN-Geländemodell.',
                        'alt' => 'Ein Dorf am Hang im Plan, mit Höhenlinien über dem Luftbild',
                    ],
                    'it' => [
                        'caption' => 'Peyrusse-le-Roc, Aveyron: la particella, il villaggio intorno e le curve di livello, direttamente dal catasto e dal modello di terreno dell’IGN.',
                        'alt' => 'Un villaggio su un pendio in pianta, con le curve di livello sulla foto aerea',
                    ],
                ],
                'picture' => 'proof-peyrusse-contours.webp',
            ],
            [
                'block' => 'PROOF',
                'content' => [
                    'caption' => 'Le Vésinet: the house and its garden in 3D, on a phone, with the sun at the hour you choose.',
                    'alt' => 'On a phone: a house with a red roof and its garden in 3D over the aerial photo',
                ],
                'translations' => [
                    'fr' => [
                        'caption' => 'Le Vésinet : la maison et son jardin en 3D, sur un téléphone, avec le soleil à l’heure que vous choisissez.',
                        'alt' => 'Sur un téléphone : une maison au toit rouge et son jardin en 3D sur la photo aérienne',
                    ],
                    'es' => [
                        'caption' => 'Le Vésinet: la casa y su jardín en 3D, en el móvil, con el sol a la hora que usted elija.',
                        'alt' => 'En el móvil: una casa de tejado rojo y su jardín en 3D sobre la foto aérea',
                    ],
                    'de' => [
                        'caption' => 'Le Vésinet: das Haus und sein Garten in 3D, auf dem Handy, mit der Sonne zur Stunde Ihrer Wahl.',
                        'alt' => 'Auf dem Handy: ein Haus mit rotem Dach und sein Garten in 3D über dem Luftbild',
                    ],
                    'it' => [
                        'caption' => 'Le Vésinet: la casa e il suo giardino in 3D, sul telefono, con il sole all’ora che scegli.',
                        'alt' => 'Sul telefono: una casa dal tetto rosso e il suo giardino in 3D sulla foto aerea',
                    ],
                ],
                'picture' => 'proof-telephone-maison-3d.webp',
            ],
            [
                'block' => 'DEMO',
                'content' => [
                    'caption' => 'The terrace, in three dimensions, in your browser. Nothing to install.',
                    'embed_url' => 'https://plan.raillard.org/?mode=demo&file=2&x={width}&y={height}&heureauto=y&hrsstart=9&hrsend=19&duree=10&orthophoto=y',
                    'ratio' => '4:3',
                ],
                'translations' => [
                    'fr' => [
                        'caption' => 'La terrasse, en trois dimensions, dans votre navigateur. Rien à installer.',
                    ],
                    'es' => [
                        'caption' => 'La terraza, en tres dimensiones, en su navegador. Nada que instalar.',
                    ],
                    'de' => [
                        'caption' => 'Die Terrasse, dreidimensional, in Ihrem Browser. Nichts zu installieren.',
                    ],
                    'it' => [
                        'caption' => 'La terrazza, in tre dimensioni, nel vostro browser. Niente da installare.',
                    ],
                ],
            ],
            [
                'block' => 'QUESTION',
                'content' => [
                    'question' => 'Does the relief work everywhere?',
                    'answer' => 'In France, wherever the IGN publishes its terrain model and the cadastre its plots — which is nearly everywhere. Elsewhere, trace the plot and type the levels in yourself.',
                ],
                'translations' => [
                    'fr' => [
                        'question' => 'Le relief marche-t-il partout ?',
                        'answer' => 'En France, partout où l’IGN publie son modèle de terrain et le cadastre ses parcelles — c’est-à-dire presque partout. Ailleurs, tracez la parcelle et saisissez les niveaux vous-même.',
                    ],
                    'es' => [
                        'question' => '¿El relieve funciona en todas partes?',
                        'answer' => 'En Francia, allí donde el IGN publica su modelo de terreno y el catastro sus parcelas, es decir, casi en todas partes. En otros lugares, trace la parcela e introduzca las cotas usted mismo.',
                    ],
                    'de' => [
                        'question' => 'Funktioniert das Gelände überall?',
                        'answer' => 'In Frankreich überall dort, wo das IGN sein Geländemodell und das Kataster seine Parzellen veröffentlicht — also fast überall. Anderswo zeichnen Sie das Grundstück und geben die Höhen selbst ein.',
                    ],
                    'it' => [
                        'question' => 'Il rilievo funziona ovunque?',
                        'answer' => 'In Francia, ovunque l’IGN pubblichi il suo modello di terreno e il catasto le sue particelle — cioè quasi ovunque. Altrove, traccia la particella e inserisci le quote tu stesso.',
                    ],
                ],
            ],
            [
                'block' => 'QUESTION',
                'content' => [
                    'question' => 'Can I cancel?',
                    'answer' => 'Yes. The service stays yours until the end of the period you have paid for, and the request is recorded with the date it takes effect.',
                ],
                'translations' => [
                    'fr' => [
                        'question' => 'Puis-je résilier ?',
                        'answer' => 'Oui. Le service reste le vôtre jusqu’à la fin de la période payée, et la demande est enregistrée avec sa date d’effet.',
                    ],
                    'es' => [
                        'question' => '¿Puedo cancelar?',
                        'answer' => 'Sí. El servicio sigue siendo suyo hasta el final del período pagado, y la solicitud se registra con su fecha de efecto.',
                    ],
                    'de' => [
                        'question' => 'Kann ich kündigen?',
                        'answer' => 'Ja. Die Leistung bleibt Ihnen bis zum Ende des bezahlten Zeitraums, und die Kündigung wird mit ihrem Wirkungsdatum festgehalten.',
                    ],
                    'it' => [
                        'question' => 'Posso disdire?',
                        'answer' => 'Sì. Il servizio resta tuo fino alla fine del periodo pagato, e la richiesta viene registrata con la sua data di effetto.',
                    ],
                ],
            ],
            [
                'block' => 'QUESTION',
                'content' => [
                    'question' => 'Does a colleague who only looks need a full seat?',
                    'answer' => 'No. A read-only seat costs a fraction of one, and it is a seat of its own rather than a smaller version of the product.',
                ],
                'translations' => [
                    'fr' => [
                        'question' => 'Un collègue qui ne fait que consulter a-t-il besoin d’un siège complet ?',
                        'answer' => 'Non. Un siège en lecture seule coûte une fraction, et c’est un siège à part plutôt qu’une version réduite du produit.',
                    ],
                    'es' => [
                        'question' => '¿Un compañero que solo consulta necesita un asiento completo?',
                        'answer' => 'No. Un asiento de solo lectura cuesta una fracción, y es un asiento propio y no una versión reducida del producto.',
                    ],
                    'de' => [
                        'question' => 'Braucht ein Kollege, der nur schaut, einen vollen Platz?',
                        'answer' => 'Nein. Ein Platz mit Lesezugriff kostet einen Bruchteil und ist ein eigener Platz, keine kleinere Fassung des Produkts.',
                    ],
                    'it' => [
                        'question' => 'Un collega che si limita a consultare ha bisogno di una postazione completa?',
                        'answer' => 'No. Una postazione in sola lettura costa una frazione, ed è una postazione a sé e non una versione ridotta del prodotto.',
                    ],
                ],
            ],
        ],
    ];

    /**
     * The feature code the workspace counts projects against — the same
     * string as `ProjectWorkspace::QUOTA`, named here because the fixtures
     * are infrastructure and may not read an application service;
     * `DemoWorldTest` fails the day the two disagree.
     */
    public const PROJECTS_QUOTA = 'max_projects';

    /**
     * Where the showcase's pictures ship, and the one product they belong
     * to. A path under the source tree rather than storage, because they
     * are part of the demonstration the way the Plan document is: the
     * seeder uploads them through the showcase module's door on every
     * reset, and storage holds the copy.
     */
    public const PICTURES_DIR = __DIR__ . '/../pictures';
    public const PICTURED_PRODUCT = 'plan';

    /**
     * Which project document schema versions a product accepts when its own
     * definition names none (non-negotiable #10): a product that declares
     * none accepts no project at all, which is what the demo did until
     * 2026-09-22. The key is `SchemaVersionPolicy::CONFIGURATION_KEY`,
     * pinned the same way.
     *
     * **A default and no longer the answer for everybody** (2026-09-29).
     * One list for every product was a platform-wide answer to a per-product
     * question, and Plan is where it showed: its 2.2.0 saves in schema 2
     * because of the façade survey, the demo declared 1, and every save was
     * refused. `PRODUCTS[…]['schema_versions']` is where a product says
     * otherwise — data keyed by code, like every other fact in this file,
     * never a branch on one.
     *
     * @var list<int>
     */
    public const SCHEMA_VERSIONS = [1];
    public const SCHEMA_VERSIONS_KEY = 'project_schema_versions';

    /**
     * What `$code` accepts: its own list, or the default above.
     *
     * @return list<int>
     */
    public static function schemaVersionsFor(string $code): array
    {
        // No emptiness check: a product declaring `[]` would be saying it
        // accepts nothing, and quietly substituting the default would hide the
        // mistake in the one place a seeded world cannot report it.
        $declared = self::PRODUCTS[$code]['schema_versions'] ?? null;

        return is_array($declared) ? $declared : self::SCHEMA_VERSIONS;
    }

    /**
     * The organisations, and which products each holds (ADR-047).
     *
     * @var array<string, array{name: string, holds: list<string>}>
     */
    public const TENANTS = [
        'acme' => ['name' => 'Acme Ltd', 'holds' => ['atlas', 'boreas', 'ceres', 'delos', 'plan'], 'default' => 'plan'],
        'globex' => ['name' => 'Globex SA', 'holds' => ['atlas', 'boreas', 'plan'], 'default' => 'atlas'],
        // None, deliberately: an organisation that has not chosen is the
        // ordinary state, and the ladder has to answer for it too.
        'initech' => ['name' => 'Initech SARL', 'holds' => ['plan'], 'default' => null],
    ];

    /**
     * The organisations whose people have **no** default of their own, so that
     * the organisation's is what decides (2026-09-28).
     *
     * The ladder is `?product=` → this browser → **the person** → **the
     * organisation** → the bundle's constant, and the person's answer beats
     * the organisation's. Giving everybody `plan` — which the demonstration
     * did — therefore made `tenants.default_product_id` invisible: it could be
     * set to anything and nobody would land anywhere different.
     *
     * So Globex's people answer nothing and open on Atlas because Globex says
     * so, while Acme's answer Plan for themselves. Two rungs of one ladder,
     * both visible, instead of one rung and a column nobody can see.
     *
     * @var list<string>
     */
    public const TENANTS_THAT_DECIDE_FOR_THEIR_PEOPLE = ['globex'];

    /**
     * The palettes the demonstration does not leave to chance (2026-10-08):
     * Acme in Plan wears Forest ledger, so the organisation the bare host
     * opens on, in the product deployed beside the platform, looks the same
     * after every reset. Every other holding draws one at random.
     *
     * A name, and so only a preference: the list is the operator's, edited
     * on the console and kept across a reset, and a name it no longer has
     * falls back to the draw rather than failing the seed.
     *
     * @var array<string, array<string, string>>
     */
    public const PALETTES = [
        'acme' => ['plan' => 'forest-ledger'],
    ];

    /**
     * The organisation the bare host addresses (2026-09-17): Acme, as the
     * operator's own. Globex lives at `/globex/`, Initech at `/initech/`.
     */
    public const DEFAULT_TENANT = 'acme';

    /**
     * Where a demonstration person's screens open when the address names no
     * product (2026-09-23): Plan, for everybody who holds it — which here is
     * everybody with a membership, since all three organisations hold it.
     *
     * The field exists per person (`users.default_product_id`, chosen on the
     * profile) and the demo left it empty, so the switcher fell back to the
     * first active product by code and Acme's five opened on Atlas. That is
     * a defensible fallback and a poor demonstration: the product worth
     * landing on is the one deployed beside the platform, because it is the
     * only one whose screens are somewhere else and whose card, link and
     * subscription have anything to show.
     *
     * Platform staff get none. A default product is a choice among
     * memberships, and somebody with no membership has nothing to choose
     * from — the console reads the platform's own list instead (ADR-047).
     */
    public const PEOPLE_DEFAULT_PRODUCT = 'plan';

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

    /**
     * Each organisation's administrator.
     *
     * They **do not subscribe** (2026-09-25). An administrator runs the
     * organisation — its members, its billing identity, its VAT, its
     * documents — and reads the subscriptions its people hold. Buying is
     * somebody deciding to use a product, and that somebody is a person.
     *
     * What they still do here is collect: the money arrives outside the
     * platform, and the administrator records it by marking the invoice
     * paid. That is the act that activates the seat, so every seat in this
     * world was sold by a person's decision and settled by their
     * organisation's.
     */
    public const TENANT_ADMINS = ['acme' => 'acme-admin', 'globex' => 'globex-admin', 'initech' => 'initech-admin'];

    /**
     * Every subscription in the world, and every one of them is a **seat**:
     * a subscription that belongs to one person (§13.1, `Subscriber::user`).
     *
     * The platform still sells to an organisation — `Subscriber::tenant()`
     * is not going anywhere — but the demonstration no longer does, because
     * the operator's model is that a person subscribes and an organisation
     * administers. So the four tenant subscriptions that stood here until
     * 2026-09-25 became seats held by the people who would actually have
     * bought them, and their administrators hold none.
     *
     * That changes who the invoice names, and it is the point of the
     * change. A seat is the organisation selling to one of its people
     * (2026-09-19, `InvoiceThenSubscribe`): **supplier Acme Ltd, customer
     * ACME user1**, VAT in the organisation's country. The platform's own
     * supplier identity does not appear on it at all.
     *
     * Each is bought the way a customer buys one — order, invoice, payment
     * — so the demonstration world contains no subscription the application
     * could not have produced, and the seeder says so.
     *
     * What each one is there to show:
     *
     * - **acme-user1** holds Pro on Plan and on Atlas: two products, one
     *   person, and three places on each to give away.
     * - **acme-user4** holds Lecture on Plan, which grants `plan.readonly`
     *   — a capability that takes away rather than gives. It costs `user1`
     *   no place, which is what a seat does that nothing else does.
     * - **globex-user1** and **initech-user1** hold Starter, which sells
     *   one person. Their colleagues are covered by nothing, deliberately:
     *   a demonstration where every quota happens to fit teaches nobody
     *   what a quota is.
     *
     * Note the direction of `plan.readonly`: the seat ADDS it on top of
     * what it grants. Capabilities are a union, so a restricting one has to
     * be read as a restriction by the product — which Plan does, and says
     * so where it reads it.
     *
     * **How each one was collected** (2026-09-26). `card` means the money
     * came in through the payment chain — an attempt started, the provider's
     * word applied, the invoice settled by that — rather than by the
     * administrator recording a transfer that arrived outside the platform.
     * Both paths are real and both are demonstrated, because a deployment
     * has both. `declined` is a first attempt that failed before the one
     * that went through: it stays FAILED for ever, because it is the record
     * of what happened, and the Payments screen shows it beside the attempt
     * that succeeded — which is the story that screen is written to tell.
     *
     * @var list<array{tenant: string, product: string, offer: string, holder: string, card?: bool, declined?: array{code: string, reason: string}}>
     */
    public const SEATS = [
        [
            'tenant' => 'acme', 'product' => 'plan', 'offer' => 'pro-monthly', 'holder' => 'acme-user1',
            'card' => true,
            'declined' => ['code' => 'insufficient_funds', 'reason' => 'The card has insufficient funds.'],
        ],
        ['tenant' => 'acme', 'product' => 'atlas', 'offer' => 'pro-monthly', 'holder' => 'acme-user1'],
        ['tenant' => 'acme', 'product' => 'plan', 'offer' => 'lecture-monthly', 'holder' => 'acme-user4'],
        // Collected by card as well, so the console's cross-tenant view of
        // payments has more than one organisation in it.
        ['tenant' => 'globex', 'product' => 'boreas', 'offer' => 'starter-monthly', 'holder' => 'globex-user1', 'card' => true],
        ['tenant' => 'initech', 'product' => 'plan', 'offer' => 'starter-monthly', 'holder' => 'initech-user1'],
    ];

    /**
     * The seat somebody ordered and whose card was refused (2026-09-26).
     *
     * A failed payment on an invoice that has since been paid is a failed
     * payment nobody can do anything with: the Payments screen offers *Try
     * again*, and a retry against a settled invoice is refused
     * (`INVOICE_NOT_PAYABLE`) — a button that answers with an error banner
     * in front of an audience. So the world also holds a debt: an order
     * fulfilled, its invoice ISSUED and owed, one attempt FAILED, and the
     * seat therefore not started. That is the state a retry exists for, and
     * it is a state every deployment has.
     *
     * It is deliberately **not** a seat: `SEATS` is what the world sold, and
     * this one is not sold until it is paid for. `globex-user2` is the
     * colleague Starter does not cover — so the same person the world
     * already uses to show a quota refusing is the one who tried to buy
     * their way out of it.
     *
     * @var list<array{tenant: string, product: string, offer: string, buyer: string, failure: array{code: string, reason: string}}>
     */
    public const DECLINED_ORDERS = [
        [
            'tenant' => 'globex', 'product' => 'atlas', 'offer' => 'pro-monthly', 'buyer' => 'globex-user2',
            'failure' => ['code' => 'card_declined', 'reason' => 'The card was declined by the issuing bank.'],
        ],
    ];

    /**
     * The provider name every demonstration payment carries.
     *
     * The stub's, and that is the honest answer: nothing in a seeded world
     * moved money, and `StubPaymentProvider` exists so a row it produced can
     * never be mistaken for a row a real provider produced. The seeder does
     * not *call* a provider — a deployment configured with Stripe would
     * otherwise have its demonstration create payment intents at a PSP, and
     * one configured with none could not seed at all — so it plays the part
     * and writes what an adapter would have handed back.
     *
     * Named here rather than imported, because the fixtures and this
     * definition are the domain and the stub is infrastructure;
     * `DemoWorldTest` holds the two to each other.
     */
    public const PAYMENT_PROVIDER = 'stub';

    /**
     * The free period somebody is trying (2026-09-27, spec §6).
     *
     * Deliberately **not** in `SEATS`: that list is what the world *sold*, each
     * row an order, an invoice and a payment. A freemium is none of those — no
     * order, no invoice and no payment at all — so it is seeded through its own
     * door, which is the only door it has.
     *
     * `globex-user2` takes it, and the choice is the third act of a story this
     * world already tells twice about the same person: Starter on Boreas covers
     * one place and not theirs, and the card they put in for Atlas was refused.
     * So the one member the demonstration uses to show a quota refusing and a
     * payment failing is the one who gets in for five days by trying it — on
     * Plan, which is the only product with anywhere to be.
     *
     * One person, one project, five days: the quota is filled exactly, which is
     * the only state in which a limit of one is visibly a limit.
     *
     * @var list<array{tenant: string, product: string, offer: string, holder: string}>
     */
    public const FREEMIUM = [
        ['tenant' => 'globex', 'product' => 'plan', 'offer' => 'freemium', 'holder' => 'globex-user2'],
    ];

    /**
     * Who each holder has put on their seat (2026-09-25) — added through
     * `SubscriptionPeople`, so the quota is enforced here exactly as it is
     * for a customer, and a world that exceeded what it sold could not be
     * seeded at all.
     *
     * **Buying covers people, membership does not** (ADR-053). `holder`
     * names whose subscription it is, because only the owner may add to it:
     * that is not a permission an administrator can be given, it is who
     * bought the thing.
     *
     * Pro sells three *counting the owner*, so `acme-user1` covers
     * themselves and `acme-user2` on both products, with one place still
     * free. Nobody else is on anything: `acme-admin` administers Acme and
     * is entitled to no product, which is the model the operator asked for
     * and the most surprising thing in this world — so the seeder asserts
     * it rather than leaving it to be noticed.
     *
     * @var list<array{tenant: string, product: string, holder: string, user: string}>
     */
    public const SUBSCRIPTION_PEOPLE = [
        ['tenant' => 'acme', 'product' => 'plan', 'holder' => 'acme-user1', 'user' => 'acme-user2'],
        ['tenant' => 'acme', 'product' => 'atlas', 'holder' => 'acme-user1', 'user' => 'acme-user2'],
    ];

    /**
     * Where a project document too large to be a constant is kept.
     *
     * Plan's real demonstration document is 42 KB encoded and 35 objects
     * deep — a parcel with its cadastral record, its PLU zones and
     * servitudes, a terrace with its bill of materials, paths, trees and
     * camera positions. As a PHP literal it would bury every other fact in
     * this file; as a JSON file beside it, it is the thing Plan exported and
     * can be replaced by exporting again.
     *
     * Copied whole by `bin/build-dist.sh`, which takes all of `src/` rather
     * than only its PHP — so a deployment seeded from the bundle gets it too.
     *
     * **A document file names nothing this world decides.** It carries the
     * document, the version that document is written in, and which release of
     * the product exported it — and not the project's name, its description,
     * who made it or which organisation it belongs to. Those are the
     * demonstration's own facts, they live in `PROJECTS`, and the file held a
     * second copy of the name for one day: the seeder read the one here, so a
     * re-export under a different name would have changed nothing and said
     * nothing, which is the shape every drift in this repository has had.
     */
    public const DOCUMENTS = __DIR__ . '/../documents';

    /**
     * The projects, made by the people who would make them, through the
     * workspace — so each one counted against its quota, in a product that
     * had declared its schema version. Atlas's is the platform's own
     * workspace with something in it.
     *
     * **A project carries a document its product exported, or none at all**
     * (2026-09-30). One of Plan's is real, named by file rather than written
     * inline; every other document here is `[]`, which is exactly what a
     * project created through the Projects screen carries — the shape belongs
     * to the product and this platform may not invent it (§4, §16).
     *
     * They used to carry `['parcelle' => 'BC 42', …]` and `['source' =>
     * 'demo']`, which looked like data and was read by nothing. The operator
     * found out by clicking them: a project in a format no product knows does
     * not open, and it is indistinguishable from one that is broken. Empty is
     * honest and it is the same case a new project already is — whatever
     * opens one opens the other.
     *
     * Giving the real Le Vésinet record to a terrace in Lyon was the other
     * way out, and it is worse: the demonstration would say something untrue
     * about its own data.
     *
     * @var list<array{tenant: string, product: string, by: string, name: string, description: string, document?: array<string, mixed>, document_file?: string}>
     */
    public const PROJECTS = [
        [
            // Plan's own demonstration project: the `demo_project` of Plan's
            // `contrat/plan-produit.json`, stored as it was given. Written in
            // **schema 3** since its house carries a hipped roof, which only
            // schema 3 describes; before that it was schema 1, and Plan still
            // opens that — its migrations read every earlier version.
            'tenant' => 'acme', 'product' => 'plan', 'by' => 'acme-user1',
            'name' => 'Parcelle AE 101',
            'description' => 'La parcelle au Vésinet : cadastre, PLU, terrasse bois sur vis de fondation, cheminements et arbres.',
            'document_file' => 'plan-parcelle-ae-101.json',
        ],
        [
            // By `user2`, whom `user1` put on their seat — not by the
            // administrator, who since 2026-09-25 subscribes to nothing and
            // so may create nothing. The place `user1` gave away is what
            // makes this project possible, which is worth one row to show.
            'tenant' => 'acme', 'product' => 'plan', 'by' => 'acme-user2',
            'name' => 'Abri de jardin — parcelle AE 101',
            'description' => 'Dalle et abri 12 m² au fond de la parcelle, à vérifier contre le PLU.',
            'document' => [],
        ],
        [
            'tenant' => 'initech', 'product' => 'plan', 'by' => 'initech-user1',
            'name' => 'Terrasse du restaurant',
            'description' => 'Terrasse de 60 m² sur lambourdes, accès PMR, Lyon 2e.',
            'document' => [],
        ],
        [
            'tenant' => 'acme', 'product' => 'atlas', 'by' => 'acme-user1',
            'name' => 'North wall',
            'description' => 'The scaffolding job.',
            'document' => [],
        ],
        [
            // By `globex-user1`, who bought the Starter seat it is counted
            // against. Starter sells one person, so `globex-user2` and the
            // administrator are covered by nothing and can make none — the
            // seeder proves both halves, because a quota nobody is ever
            // refused by is a number on a page.
            'tenant' => 'globex', 'product' => 'boreas', 'by' => 'globex-user1',
            'name' => 'Site survey',
            'description' => 'First pass at the Globex yard.',
            'document' => [],
        ],
        [
            // The free period's one project (2026-09-27), by the person trying
            // it. Freemium sells `max_projects` = 1, so this fills the quota
            // exactly: a limit of one with nothing counted against it is a
            // number on a page, and the next project this person attempts is
            // refused — which is the sentence the plan exists to say.
            'tenant' => 'globex', 'product' => 'plan', 'by' => 'globex-user2',
            'name' => 'Terrasse à l’essai',
            'description' => 'Cinq jours pour voir si Plan fait le travail : terrasse 18 m², Villeurbanne.',
            'document' => [],
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
