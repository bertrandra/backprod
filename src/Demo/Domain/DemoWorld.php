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
                'content' => ['eyebrow' => 'Outdoor plans'],
                'translations' => [
                    'fr' => ['eyebrow' => 'Plans d’extérieur'],
                    'es' => ['eyebrow' => 'Planos de exteriores'],
                    'de' => ['eyebrow' => 'Außenpläne'],
                    'it' => ['eyebrow' => 'Piani per esterni'],
                ],
            ],
            'PROBLEM' => [
                'content' => [
                    'eyebrow' => 'The problem',
                    'title' => 'The plan is never the work. It is what has to be redone before the work can start.',
                ],
                'translations' => [
                    'fr' => ['eyebrow' => 'Le problème', 'title' => 'Le plan n’est jamais le travail. C’est ce qu’il faut refaire avant de pouvoir travailler.'],
                    'es' => ['eyebrow' => 'El problema', 'title' => 'El plano nunca es el trabajo. Es lo que hay que rehacer antes de poder trabajar.'],
                    'de' => ['eyebrow' => 'Das Problem', 'title' => 'Der Plan ist nie die Arbeit. Er ist das, was vorher noch einmal gemacht werden muss.'],
                    'it' => ['eyebrow' => 'Il problema', 'title' => 'Il piano non è mai il lavoro. È ciò che va rifatto prima di poter lavorare.'],
                ],
            ],
            'STEPS' => [
                'content' => [
                    'eyebrow' => 'The tutorial — three moves',
                    'title' => 'Trace, place, print.',
                    'lede' => 'No training, no template to download. These are the only three things to know.',
                ],
                'translations' => [
                    'fr' => ['eyebrow' => 'Le tuto — trois gestes', 'title' => 'Tracer, poser, imprimer.', 'lede' => 'Aucune formation, aucun gabarit à télécharger. Ce sont les trois seules choses à savoir faire.'],
                    'es' => ['eyebrow' => 'El tutorial — tres gestos', 'title' => 'Trazar, colocar, imprimir.', 'lede' => 'Sin formación, sin plantilla que descargar. Son las tres únicas cosas que hay que saber.'],
                    'de' => ['eyebrow' => 'Die Anleitung — drei Handgriffe', 'title' => 'Zeichnen, setzen, drucken.', 'lede' => 'Keine Schulung, keine Vorlage zum Herunterladen. Das sind die einzigen drei Dinge.'],
                    'it' => ['eyebrow' => 'Il tutorial — tre gesti', 'title' => 'Tracciare, posare, stampare.', 'lede' => 'Nessuna formazione, nessun modello da scaricare. Sono le uniche tre cose da sapere.'],
                ],
            ],
            'USE_CASE' => [
                'content' => ['eyebrow' => 'The result', 'title' => 'The same plot, before and after.'],
                'translations' => [
                    'fr' => ['eyebrow' => 'Le résultat', 'title' => 'La même parcelle, avant et après.'],
                    'es' => ['eyebrow' => 'El resultado', 'title' => 'La misma parcela, antes y después.'],
                    'de' => ['eyebrow' => 'Das Ergebnis', 'title' => 'Dasselbe Grundstück, vorher und nachher.'],
                    'it' => ['eyebrow' => 'Il risultato', 'title' => 'La stessa particella, prima e dopo.'],
                ],
            ],
            'DEMO' => [
                'content' => ['eyebrow' => 'Try it', 'title' => 'Open it and move a corner.'],
                'translations' => [
                    'fr' => ['eyebrow' => 'Essayez', 'title' => 'Ouvrez-la et déplacez un coin.'],
                    'es' => ['eyebrow' => 'Pruébelo', 'title' => 'Ábrala y mueva una esquina.'],
                    'de' => ['eyebrow' => 'Probieren Sie es', 'title' => 'Öffnen Sie sie und verschieben Sie eine Ecke.'],
                    'it' => ['eyebrow' => 'Provatela', 'title' => 'Apritela e spostate un angolo.'],
                ],
            ],
            'PROOF' => [
                'content' => ['eyebrow' => 'The proof', 'title' => 'The sheet that comes out, as it comes out.'],
                'translations' => [
                    'fr' => ['eyebrow' => 'La preuve', 'title' => 'La feuille qui sort, telle quelle.'],
                    'es' => ['eyebrow' => 'La prueba', 'title' => 'La hoja que sale, tal cual.'],
                    'de' => ['eyebrow' => 'Der Beleg', 'title' => 'Das Blatt, das herauskommt — so wie es herauskommt.'],
                    'it' => ['eyebrow' => 'La prova', 'title' => 'Il foglio che esce, così com’è.'],
                ],
            ],
            'PRICING' => [
                'content' => [
                    'title' => 'The first plan costs nothing.',
                    'lede' => 'Draw it in full, print it. You pay only if you make a second one.',
                ],
                'translations' => [
                    'fr' => ['title' => 'Le premier plan ne coûte rien.', 'lede' => 'Dessinez-le en entier, imprimez-le. Vous ne payez que si vous en faites un deuxième.'],
                    'es' => ['title' => 'El primer plano no cuesta nada.', 'lede' => 'Dibújelo entero, imprímalo. Solo paga si hace un segundo.'],
                    'de' => ['title' => 'Der erste Plan kostet nichts.', 'lede' => 'Zeichnen Sie ihn ganz, drucken Sie ihn. Sie zahlen erst für den zweiten.'],
                    'it' => ['title' => 'Il primo piano non costa nulla.', 'lede' => 'Disegnalo per intero, stampalo. Paghi solo se ne fai un secondo.'],
                ],
            ],
            'QUESTION' => [
                'content' => ['title' => 'The questions we are asked'],
                'translations' => [
                    'fr' => ['title' => 'Les questions qu’on nous pose'],
                    'es' => ['title' => 'Las preguntas que nos hacen'],
                    'de' => ['title' => 'Die Fragen, die uns gestellt werden'],
                    'it' => ['title' => 'Le domande che ci fanno'],
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
     * @var array<string, list<array{block: string, content: array<string, string>, translations: array<string, array<string, string>>}>>
     */
    public const SHOWCASE = [
        'plan' => [
            [
                'block' => 'HEADLINE',
                'content' => [
                    'headline' => 'Draw a terrace in three minutes',
                    'subline' => 'From the cadastral plot to a file your builder can read — without redrawing anything by hand.',
                    'reassurance' => 'Nothing to install. The first plan costs nothing.',
                ],
                'translations' => [
                    'fr' => [
                        'headline' => 'Dessinez une terrasse en trois minutes',
                        'subline' => 'De la parcelle cadastrale au fichier que votre artisan peut lire, sans rien redessiner à la main.',
                        'reassurance' => 'Rien à installer. Le premier plan ne coûte rien.',
                    ],
                    'es' => [
                        'headline' => 'Dibuje una terraza en tres minutos',
                        'subline' => 'De la parcela catastral al archivo que su constructor puede leer, sin volver a dibujar nada a mano.',
                        'reassurance' => 'Nada que instalar. El primer plano no cuesta nada.',
                    ],
                    'de' => [
                        'headline' => 'Zeichnen Sie eine Terrasse in drei Minuten',
                        'subline' => 'Vom Katasterplan zur Datei, die Ihr Handwerker lesen kann — ohne etwas von Hand nachzuzeichnen.',
                        'reassurance' => 'Nichts zu installieren. Der erste Plan kostet nichts.',
                    ],
                    'it' => [
                        'headline' => 'Disegna una terrazza in tre minuti',
                        'subline' => 'Dalla particella catastale al file che il tuo costruttore può leggere, senza ridisegnare nulla a mano.',
                        'reassurance' => 'Niente da installare. Il primo piano non costa nulla.',
                    ],
                ],
            ],
            [
                'block' => 'PROBLEM',
                'content' => [
                    'title' => 'Half a day for every job',
                    'body' => 'Measure, transfer to paper, start again because the scale will not come out round.',
                    'icon' => 'clock',
                ],
                'translations' => [
                    'fr' => ['title' => 'Une demi-journée par chantier', 'body' => 'Mesurer, reporter sur papier, recommencer parce que l’échelle ne tombe pas juste.'],
                    'es' => ['title' => 'Media jornada por obra', 'body' => 'Medir, pasar al papel, empezar de nuevo porque la escala no sale redonda.'],
                    'de' => ['title' => 'Ein halber Tag je Baustelle', 'body' => 'Messen, aufs Papier übertragen, von vorn anfangen, weil der Maßstab nicht aufgeht.'],
                    'it' => ['title' => 'Mezza giornata per cantiere', 'body' => 'Misurare, riportare su carta, ricominciare perché la scala non torna.'],
                ],
            ],
            [
                'block' => 'PROBLEM',
                'content' => [
                    'title' => 'Two figures for one area',
                    'body' => 'The site notebook and the quotation disagree, and nothing decides between them.',
                    'icon' => 'cross',
                ],
                'translations' => [
                    'fr' => ['title' => 'Deux chiffres pour une surface', 'body' => 'Le carnet de chantier et le devis ne disent pas la même chose, et rien ne tranche.'],
                    'es' => ['title' => 'Dos cifras para una superficie', 'body' => 'El cuaderno de obra y el presupuesto no coinciden, y nada decide entre ellos.'],
                    'de' => ['title' => 'Zwei Zahlen für eine Fläche', 'body' => 'Das Bautagebuch und das Angebot widersprechen sich, und nichts entscheidet.'],
                    'it' => ['title' => 'Due cifre per una superficie', 'body' => 'Il quaderno di cantiere e il preventivo non coincidono, e nulla decide.'],
                ],
            ],
            [
                'block' => 'PROBLEM',
                'content' => [
                    'title' => 'A client who sees nothing',
                    'body' => 'A pencil sketch is not signed. It is discussed, at length, and then drawn again.',
                    'icon' => 'house',
                ],
                'translations' => [
                    'fr' => ['title' => 'Un client qui ne voit rien', 'body' => 'Un croquis au crayon ne se signe pas. Il se discute, longtemps, puis se refait.'],
                    'es' => ['title' => 'Un cliente que no ve nada', 'body' => 'Un croquis a lápiz no se firma. Se discute, largamente, y luego se rehace.'],
                    'de' => ['title' => 'Ein Kunde, der nichts sieht', 'body' => 'Eine Bleistiftskizze wird nicht unterschrieben. Sie wird lange besprochen und dann neu gezeichnet.'],
                    'it' => ['title' => 'Un cliente che non vede nulla', 'body' => 'Uno schizzo a matita non si firma. Si discute, a lungo, e poi si rifà.'],
                ],
            ],
            [
                'block' => 'STEPS',
                'content' => [
                    'title' => 'Bring in the plot',
                    'body' => 'Import it from the cadastre by its reference, or trace it yourself. The boundaries come with it.',
                ],
                'translations' => [
                    'fr' => ['title' => 'Faites venir la parcelle', 'body' => 'Importez-la du cadastre par sa référence, ou tracez-la vous-même. Les limites viennent avec.'],
                    'es' => ['title' => 'Traiga la parcela', 'body' => 'Impórtela del catastro por su referencia, o trácela usted mismo. Los límites vienen con ella.'],
                    'de' => ['title' => 'Holen Sie das Grundstück herein', 'body' => 'Importieren Sie es über die Katasternummer oder zeichnen Sie es selbst. Die Grenzen kommen mit.'],
                    'it' => ['title' => 'Porta dentro la particella', 'body' => 'Importala dal catasto tramite il riferimento, o tracciala tu stesso. I confini arrivano con lei.'],
                ],
            ],
            [
                'block' => 'STEPS',
                'content' => [
                    'title' => 'The terrace follows',
                    'body' => 'Slopes, levels and edges are computed while you drag. Nothing is redrawn twice.',
                ],
                'translations' => [
                    'fr' => ['title' => 'La terrasse suit', 'body' => 'Pentes, niveaux et bordures se calculent pendant que vous déplacez. Rien ne se redessine deux fois.'],
                    'es' => ['title' => 'La terraza sigue', 'body' => 'Pendientes, niveles y bordes se calculan mientras arrastra. Nada se vuelve a dibujar dos veces.'],
                    'de' => ['title' => 'Die Terrasse folgt', 'body' => 'Gefälle, Höhen und Kanten werden beim Ziehen berechnet. Nichts wird zweimal gezeichnet.'],
                    'it' => ['title' => 'La terrazza segue', 'body' => 'Pendenze, livelli e bordi si calcolano mentre trascini. Nulla viene ridisegnato due volte.'],
                ],
            ],
            [
                'block' => 'STEPS',
                'content' => [
                    'title' => 'The file prints',
                    'body' => 'DXF for the builder, a PDF dossier for the client. Both from the same drawing.',
                ],
                'translations' => [
                    'fr' => ['title' => 'Le fichier sort', 'body' => 'DXF pour l’artisan, dossier PDF pour le client. Les deux du même dessin.'],
                    'es' => ['title' => 'El archivo sale', 'body' => 'DXF para el constructor, dosier PDF para el cliente. Ambos del mismo dibujo.'],
                    'de' => ['title' => 'Die Datei geht raus', 'body' => 'DXF für den Handwerker, ein PDF-Dossier für den Kunden. Beide aus derselben Zeichnung.'],
                    'it' => ['title' => 'Il file esce', 'body' => 'DXF per il costruttore, dossier PDF per il cliente. Entrambi dallo stesso disegno.'],
                ],
            ],
            [
                'block' => 'USE_CASE',
                'content' => [
                    'who' => 'A landscaper quoting on site',
                    'before' => 'An afternoon redrawing the plot by hand, and a second afternoon when the client moves the pool.',
                    'after' => 'The plot is on screen before the coffee is finished, and the second version takes three minutes.',
                ],
                'translations' => [
                    'fr' => [
                        'who' => 'Un paysagiste qui chiffre sur place',
                        'before' => 'Un après-midi à redessiner la parcelle à la main, et un deuxième quand le client déplace la piscine.',
                        'after' => 'La parcelle est à l’écran avant la fin du café, et la deuxième version prend trois minutes.',
                    ],
                    'es' => [
                        'who' => 'Un paisajista presupuestando sobre el terreno',
                        'before' => 'Una tarde redibujando la parcela a mano, y otra cuando el cliente mueve la piscina.',
                        'after' => 'La parcela está en pantalla antes de acabar el café, y la segunda versión lleva tres minutos.',
                    ],
                    'de' => [
                        'who' => 'Ein Garten- und Landschaftsbauer, der vor Ort kalkuliert',
                        'before' => 'Ein Nachmittag, um das Grundstück von Hand nachzuzeichnen — und ein zweiter, wenn der Kunde den Pool verschiebt.',
                        'after' => 'Das Grundstuck ist auf dem Bildschirm, bevor der Kaffee ausgetrunken ist, und die zweite Fassung dauert drei Minuten.',
                    ],
                    'it' => [
                        'who' => 'Un paesaggista che fa un preventivo in cantiere',
                        'before' => 'Un pomeriggio a ridisegnare la particella a mano, e un secondo quando il cliente sposta la piscina.',
                        'after' => 'La particella è sullo schermo prima che finisca il caffè, e la seconda versione richiede tre minuti.',
                    ],
                ],
            ],
            [
                'block' => 'USE_CASE',
                'content' => [
                    'who' => 'A builder reading somebody else’s drawing',
                    'before' => 'A PDF with no dimensions, and a phone call to ask what the levels were.',
                    'after' => 'A DXF that opens in the tools they already have, with the levels in it.',
                ],
                'translations' => [
                    'fr' => [
                        'who' => 'Un artisan qui lit le dessin d’un autre',
                        'before' => 'Un PDF sans cotes, et un coup de fil pour demander les niveaux.',
                        'after' => 'Un DXF qui s’ouvre dans ses outils, avec les niveaux dedans.',
                    ],
                    'es' => [
                        'who' => 'Un constructor que lee el dibujo de otro',
                        'before' => 'Un PDF sin cotas y una llamada para preguntar por los niveles.',
                        'after' => 'Un DXF que se abre en las herramientas que ya tiene, con los niveles dentro.',
                    ],
                    'de' => [
                        'who' => 'Ein Handwerker, der die Zeichnung eines anderen liest',
                        'before' => 'Ein PDF ohne Maße und ein Anruf, um nach den Höhen zu fragen.',
                        'after' => 'Eine DXF, die sich in den vorhandenen Werkzeugen öffnet — mit den Höhen darin.',
                    ],
                    'it' => [
                        'who' => 'Un costruttore che legge il disegno di un altro',
                        'before' => 'Un PDF senza quote e una telefonata per chiedere i livelli.',
                        'after' => 'Un DXF che si apre negli strumenti che ha già, con i livelli dentro.',
                    ],
                ],
            ],
            [
                'block' => 'QUOTE',
                'content' => [
                    'quote' => 'The quotation goes out the same evening, from the same drawing. I have stopped redrawing at the kitchen table.',
                    'author' => 'Head of a three-person landscaping firm',
                ],
                'translations' => [
                    'fr' => ['quote' => 'Le devis part le soir même, depuis le même dessin. J’ai arrêté de redessiner à la table de la cuisine.', 'author' => 'Gérant d’une entreprise de paysage de trois personnes'],
                    'es' => ['quote' => 'El presupuesto sale esa misma tarde, del mismo dibujo. He dejado de redibujar en la mesa de la cocina.', 'author' => 'Responsable de una empresa de paisajismo de tres personas'],
                    'de' => ['quote' => 'Das Angebot geht noch am selben Abend raus, aus derselben Zeichnung. Ich zeichne nicht mehr am Küchentisch nach.', 'author' => 'Inhaber eines Garten- und Landschaftsbaubetriebs mit drei Leuten'],
                    'it' => ['quote' => 'Il preventivo parte la sera stessa, dallo stesso disegno. Ho smesso di ridisegnare al tavolo di cucina.', 'author' => 'Titolare di un’impresa di giardinaggio di tre persone'],
                ],
            ],
            [
                // Plan, running on the page that sells it (2026-09-30). The
                // address is the product's own — the same origin `app_url`
                // names — and `{width}` / `{height}` are the platform's
                // tokens: the page puts in the pixels it drew at, and nothing
                // here knows that Plan calls its parameters `x` and `y`.
                //
                // **It needs `--embed https://plan.raillard.org` at build
                // time**, or `frame-src 'self'` refuses the frame and the band
                // shows its caption over Chrome's "This content is blocked"
                // box, which names neither the origin nor the directive. That
                // is why the build says what it allowed.
                'block' => 'DEMO',
                'content' => [
                    'caption' => 'The terrace, in three dimensions, in your browser. Nothing to install.',
                    'embed_url' => 'https://plan.raillard.org/?mode=demo&file=2&x={width}&y={height}&heureauto=y&hrsstart=9&hrsend=19&duree=10&orthophoto=y',
                    'ratio' => '4:3',
                ],
                'translations' => [
                    'fr' => ['caption' => 'La terrasse, en trois dimensions, dans votre navigateur. Rien à installer.'],
                    'es' => ['caption' => 'La terraza, en tres dimensiones, en su navegador. Nada que instalar.'],
                    'de' => ['caption' => 'Die Terrasse, dreidimensional, in Ihrem Browser. Nichts zu installieren.'],
                    'it' => ['caption' => 'La terrazza, in tre dimensioni, nel vostro browser. Niente da installare.'],
                ],
            ],
            [
                'block' => 'QUESTION',
                'content' => [
                    'question' => 'Can I cancel?',
                    'answer' => 'Yes. The service stays yours until the end of the period you have paid for, and the request is recorded with the date it takes effect.',
                ],
                'translations' => [
                    'fr' => ['question' => 'Puis-je résilier ?', 'answer' => 'Oui. Le service reste le vôtre jusqu’à la fin de la période payée, et la demande est enregistrée avec sa date d’effet.'],
                    'es' => ['question' => '¿Puedo cancelar?', 'answer' => 'Sí. El servicio sigue siendo suyo hasta el final del período pagado, y la solicitud se registra con su fecha de efecto.'],
                    'de' => ['question' => 'Kann ich kündigen?', 'answer' => 'Ja. Die Leistung bleibt Ihnen bis zum Ende des bezahlten Zeitraums, und die Kündigung wird mit ihrem Wirkungsdatum festgehalten.'],
                    'it' => ['question' => 'Posso disdire?', 'answer' => 'Sì. Il servizio resta tuo fino alla fine del periodo pagato, e la richiesta viene registrata con la sua data di effetto.'],
                ],
            ],
            [
                'block' => 'QUESTION',
                'content' => [
                    'question' => 'Does a colleague who only looks need a full seat?',
                    'answer' => 'No. A read-only seat costs a fraction of one, and it is a seat of its own rather than a smaller version of the product.',
                ],
                'translations' => [
                    'fr' => ['question' => 'Un collègue qui ne fait que consulter a-t-il besoin d’un siège complet ?', 'answer' => 'Non. Un siège en lecture seule coûte une fraction, et c’est un siège à part plutôt qu’une version réduite du produit.'],
                    'es' => ['question' => '¿Un compañero que solo consulta necesita un asiento completo?', 'answer' => 'No. Un asiento de solo lectura cuesta una fracción, y es un asiento propio y no una versión reducida del producto.'],
                    'de' => ['question' => 'Braucht ein Kollege, der nur schaut, einen vollen Platz?', 'answer' => 'Nein. Ein Platz mit Lesezugriff kostet einen Bruchteil und ist ein eigener Platz, keine kleinere Fassung des Produkts.'],
                    'it' => ['question' => 'Un collega che si limita a consultare ha bisogno di una postazione completa?', 'answer' => 'No. Una postazione in sola lettura costa una frazione, ed è una postazione a sé e non una versione ridotta del prodotto.'],
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
