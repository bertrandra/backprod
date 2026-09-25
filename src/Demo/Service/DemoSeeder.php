<?php

declare(strict_types=1);

namespace App\Demo\Service;

use App\Billing\Service\Invoicing;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Service\SubscriptionPeople;
use App\Commerce\Service\Subscriptions;
use App\Demo\Domain\DemoFixtures;
use App\Demo\Domain\DemoWorld;
use App\Demo\Domain\SeededWorld;
use App\Entitlement\Domain\EntitlementRepository;
use App\Project\Service\ProjectWorkspace;
use App\Shared\Exceptions\ConflictException;

/**
 * Builds the demonstration world, and rebuilds it.
 *
 * One definition of "a complete demo", used by three callers: the command
 * line (`bin/seed-demo.php`), the installer (`deploy/siteground/setup.php`)
 * and the console's reset. It was a file of closures in `bin/` before the
 * console needed it; a request cannot `require` a script, so it became this.
 *
 * **Invariants go through the services that own them.** The fixtures write
 * structure; subscribing and issuing an invoice are `Subscriptions::subscribe`
 * and `Invoicing::issueForSubscription`, never INSERT: an invoice number comes
 * from a gapless sequence and an activation writes a subscription event, and a
 * fixture that wrote those rows itself would demonstrate a world the
 * application cannot produce. A project (2026-09-22) is
 * `ProjectWorkspace::create` for the same reason: it is counted against the
 * subscription's quota and accepted under a schema version the product
 * declared, and a seeded row that skipped both would be a project no member
 * could have made.
 *
 * **Seeding and verifying in one pass.** The result carries every check, and
 * a caller that finds one failed has a world worse than none: somebody would
 * demonstrate it.
 */
final class DemoSeeder
{
    public const NOT_A_DEMO_DEPLOYMENT = 'NOT_A_DEMO_DEPLOYMENT';
    public const ALREADY_SEEDED = 'DEMO_ALREADY_SEEDED';

    public function __construct(
        private readonly DemoFixtures $fixtures,
        private readonly Subscriptions $subscriptions,
        private readonly Invoicing $invoicing,
        private readonly ProjectWorkspace $workspace,
        private readonly SubscriptionPeople $people,
        private readonly EntitlementRepository $entitlements,
    ) {
    }

    public function isSeeded(): bool
    {
        return $this->fixtures->isSeeded();
    }

    /**
     * Seeds the world into a database that does not hold it.
     *
     * @throws ConflictException DEMO_ALREADY_SEEDED — seeding on top would
     *                           double every catalogue and leave a demo
     *                           nobody could trust
     */
    public function seed(): SeededWorld
    {
        if ($this->fixtures->isSeeded()) {
            throw new ConflictException(
                self::ALREADY_SEEDED,
                'A product with one of the demonstration codes already exists; reset instead of seeding on top.',
                ['codes' => DemoWorld::productCodes()],
            );
        }

        return $this->build();
    }

    /**
     * Empties every business table and seeds the world afresh.
     *
     * Refused while the platform hosts a product that is not the
     * demonstration's: that is somebody's real product, with real tenants
     * and legal documents under it, and a reset that took it too would be
     * the widest destructive act this platform can perform, behind one
     * button. The rule is on what *exists*, not on what the caller says.
     *
     * Everybody is signed out by it, the caller included: the people are
     * rows, and the rows are gone. The answer says who to sign in as.
     *
     * @throws ConflictException NOT_A_DEMO_DEPLOYMENT
     */
    public function reset(): SeededWorld
    {
        $foreign = $this->fixtures->foreignProducts();

        if ($foreign !== []) {
            throw new ConflictException(
                self::NOT_A_DEMO_DEPLOYMENT,
                'This platform hosts products that are not the demonstration\'s; resetting would destroy them.',
                ['products' => $foreign],
            );
        }

        $this->fixtures->wipe();

        return $this->build();
    }

    private function build(): SeededWorld
    {
        // Hashed here rather than handed down as a literal: the fixtures
        // store what they are given, and what they are given must never be
        // the plaintext.
        $structure = $this->fixtures->write(password_hash(DemoWorld::PASSWORD, PASSWORD_BCRYPT));

        $subscriptions = [];
        $invoices = [];

        foreach (DemoWorld::SUBSCRIPTIONS as $live) {
            $tenant = $structure->tenant($live['tenant']);
            $product = $structure->product($live['product']);
            // Activated by the organisation's own administrator, who then
            // owns it and manages its people (2026-09-19).
            $actor = $structure->user(DemoWorld::TENANT_ADMINS[$live['tenant']]);

            $subscriptions[] = $this->subscriptions->subscribe(
                $tenant,
                $product,
                $structure->offer($live['product'], $live['offer']),
                $actor,
            );
            $invoices[] = $this->invoicing->issueForSubscription($tenant, $product, $actor);
        }

        // Les sieges, apres les abonnements de l'organisation : un siege coexiste avec celui du
        // locataire, et ses droits ne touchent que son porteur. Aucune facture ici — le siege de
        // demonstration existe pour montrer le droit, pas la vente, et une facture de plus
        // decalerait les jeux d'essai qui comptent celles des abonnements.
        foreach (DemoWorld::SEATS as $siege) {
            $this->subscriptions->subscribe(
                $structure->tenant($siege['tenant']),
                $structure->product($siege['product']),
                $structure->offer($siege['product'], $siege['offer']),
                $structure->user(DemoWorld::TENANT_ADMINS[$siege['tenant']]),
                Subscriber::user($structure->user($siege['holder'])),
            );
        }

        // The people each subscription covers (2026-09-25), added by the
        // owner through the same service a customer uses — so the `users`
        // quota is enforced here rather than described, and a demonstration
        // world that exceeded what it sold could not be seeded at all.
        //
        // Before the subscriptions' people are on them, nobody but an owner
        // is entitled to anything, so this has to come before the projects
        // below: `acme-user1` makes two of them.
        foreach (DemoWorld::SUBSCRIPTION_PEOPLE as $covered) {
            $this->people->add(
                $structure->tenant($covered['tenant']),
                $structure->product($covered['product']),
                $structure->user(DemoWorld::TENANT_ADMINS[$covered['tenant']]),
                false,
                $structure->user($covered['user']),
                null,
            );
        }

        // After the subscriptions: each project is counted against its
        // organisation's quota on the product, which the subscription grants.
        $projects = [];

        foreach (DemoWorld::PROJECTS as $draft) {
            $projects[] = $this->workspace->create(
                $structure->tenant($draft['tenant']),
                $structure->product($draft['product']),
                $structure->user($draft['by']),
                $draft['name'],
                $draft['description'],
                DemoWorld::SCHEMA_VERSIONS[0],
                (object) $draft['document'],
            );
        }

        $checks = [
            'every project was stored where it was made' => array_filter(
                array_keys($projects),
                static fn (int $i): bool => $projects[$i]->tenantId !== $structure->tenant(DemoWorld::PROJECTS[$i]['tenant'])
                    || $projects[$i]->productId !== $structure->product(DemoWorld::PROJECTS[$i]['product']),
            ) === [],
            'every subscription is active' => array_filter(
                DemoWorld::SUBSCRIPTIONS,
                fn (array $live): bool => $this->subscriptions->current(
                    $structure->tenant($live['tenant']),
                    $structure->product($live['product']),
                )?->status !== 'ACTIVE',
            ) === [],
            'every invoice has a legal number' => array_filter(
                $invoices,
                static fn ($invoice): bool => !is_string($invoice->number) || $invoice->number === '',
            ) === [],
            'each invoice belongs to its tenant' => array_filter(
                array_keys($invoices),
                static fn (int $i): bool => $invoices[$i]->tenantId !== $structure->tenant(DemoWorld::SUBSCRIPTIONS[$i]['tenant']),
            ) === [],
            // The rule the whole demonstration now turns on (2026-09-25):
            // whoever made a project was covered by a subscription at the
            // time. Asked through the port the platform itself asks, so a
            // world that seeded is a world somebody could have built.
            'every project was made by somebody a subscription covers' => array_filter(
                DemoWorld::PROJECTS,
                fn (array $draft): bool => !$this->entitlements->covers(
                    $structure->tenant($draft['tenant']),
                    $structure->product($draft['product']),
                    $structure->user($draft['by']),
                ),
            ) === [],
            // And the other half, which is what makes the number sold mean
            // something: a member the subscription does not cover is covered
            // by nothing. Globex sells one place and has three members.
            'a member outside the places sold is covered by nothing' => !$this->entitlements->covers(
                $structure->tenant('globex'),
                $structure->product('boreas'),
                $structure->user('globex-user1'),
            ),
        ] + $this->fixtures->verify($structure);

        return new SeededWorld($structure, $subscriptions, $invoices, $checks);
    }
}
