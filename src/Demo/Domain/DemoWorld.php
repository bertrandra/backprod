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
     * @var array<string, array{name: string, base: int, order: int, app_url: ?string, meters: array<string, array{name: string, unit: string, starter: int, pro: int}>, capabilities?: array<string, array{name: string, from: string}>, plans?: array<string, array{name: string, rank: int, price: int, period: string, grants: list<string>}>}>
     */
    public const PRODUCTS = [
        'plan' => [
            'name' => 'Plan',
            'order' => 10,
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
        'atlas' => ['name' => 'Atlas', 'order' => 20, 'base' => 1_900, 'app_url' => null, 'meters' => []],
        'boreas' => ['name' => 'Boreas', 'order' => 30, 'base' => 2_900, 'app_url' => null, 'meters' => []],
        'ceres' => ['name' => 'Ceres', 'order' => 40, 'base' => 900, 'app_url' => null, 'meters' => []],
        'delos' => ['name' => 'Delos', 'order' => 50, 'base' => 4_900, 'app_url' => null, 'meters' => []],
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
     * What a feature says beside its name, in English (2026-09-24).
     *
     * Only the four every catalogue grants: they are what a customer reads
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
        'white_label' => 'Your own logo and colours instead of ours.',
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
        'white_label' => [
            'fr' => ['name' => 'Marque blanche', 'description' => 'Votre logo et vos couleurs à la place des nôtres.'],
            'es' => ['name' => 'Marca blanca', 'description' => 'Su logotipo y sus colores en lugar de los nuestros.'],
            'de' => ['name' => 'White Label', 'description' => 'Ihr Logo und Ihre Farben statt unserer.'],
            'it' => ['name' => 'Marchio bianco', 'description' => 'Il vostro logo e i vostri colori al posto dei nostri.'],
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
                ],
                'translations' => [
                    'fr' => [
                        'headline' => 'Dessinez une terrasse en trois minutes',
                        'subline' => 'De la parcelle cadastrale au fichier que votre artisan peut lire, sans rien redessiner à la main.',
                    ],
                    'es' => [
                        'headline' => 'Dibuje una terraza en tres minutos',
                        'subline' => 'De la parcela catastral al archivo que su constructor puede leer, sin volver a dibujar nada a mano.',
                    ],
                    'de' => [
                        'headline' => 'Zeichnen Sie eine Terrasse in drei Minuten',
                        'subline' => 'Vom Katasterplan zur Datei, die Ihr Handwerker lesen kann — ohne etwas von Hand nachzuzeichnen.',
                    ],
                    'it' => [
                        'headline' => 'Disegna una terrazza in tre minuti',
                        'subline' => 'Dalla particella catastale al file che il tuo costruttore può leggere, senza ridisegnare nulla a mano.',
                    ],
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
