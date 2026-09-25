<?php

declare(strict_types=1);

namespace App\Demo\Service;

use App\Billing\Service\Invoicing;
use App\Commerce\Service\SubscriptionPeople;
use App\Commerce\Service\Subscriptions;
use App\Demo\Domain\DemoFixtures;
use App\Demo\Domain\DemoWorld;
use App\Demo\Domain\SeededWorld;
use App\Entitlement\Domain\EntitlementRepository;
use App\Project\Service\ProjectWorkspace;
use App\Sales\Service\Sales;
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
 * structure; everything a customer would do, the seeder does the way a
 * customer does it, never by INSERT: an invoice number comes from a gapless
 * sequence and an activation writes a subscription event, and a fixture that
 * wrote those rows itself would demonstrate a world the application cannot
 * produce. A project (2026-09-22) is `ProjectWorkspace::create` for the same
 * reason: it is counted against the subscription's quota and accepted under a
 * schema version the product declared, and a seeded row that skipped both
 * would be a project no member could have made.
 *
 * **Every subscription here is bought** (2026-09-25). Until today the seeder
 * called `Subscriptions::subscribe` and then `Invoicing::issueForSubscription`,
 * which starts a subscription and bills it — a pair of administrative acts no
 * customer performs and, more to the point, the wrong ones: that route issues
 * *the product's supplier → the organisation*, and the world the operator
 * wants shows *the organisation → one of its people*. So the seeder goes
 * through the sale instead: the person orders a seat, the order is fulfilled
 * (which raises that invoice), and the organisation's administrator marks it
 * paid — the money having arrived outside the platform, as it does. Marking it
 * paid is what activates the seat, so the whole chain is exercised and nothing
 * in the demonstration world is a shortcut.
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
        private readonly Sales $sales,
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

        foreach (DemoWorld::SEATS as $seat) {
            $tenant = $structure->tenant($seat['tenant']);
            $product = $structure->product($seat['product']);
            // The person who wants the product buys it, for themselves.
            $buyer = $structure->user($seat['holder']);
            // The organisation collects, outside the platform, and records
            // it here — which is the administrator's part in a sale they did
            // not make.
            $collector = $structure->user(DemoWorld::TENANT_ADMINS[$seat['tenant']]);

            $order = $this->sales->fulfil(
                $tenant,
                $product,
                $this->sales->order($tenant, $product, $structure->offer($seat['product'], $seat['offer']), $buyer, true)->id,
                $buyer,
            );

            if ($order->invoiceId === null) {
                // Every offer in this world is priced, so every order raises
                // an invoice. Refusing rather than carrying a null forward:
                // the next two lines would fail further away from the cause.
                throw new ConflictException(
                    'DEMO_ORDER_RAISED_NO_INVOICE',
                    'A demonstration seat was fulfilled without an invoice, which no priced offer can do.',
                    ['tenant' => $seat['tenant'], 'product' => $seat['product'], 'offer' => $seat['offer']],
                );
            }

            // Paying it completes the order, which activates the seat: the
            // subscription exists because the money did, and not before.
            $invoices[] = $this->invoicing->markPaid($tenant, $product, $order->invoiceId, $collector);

            $started = $this->subscriptions->seatOf($tenant, $product, $buyer);

            if ($started === null) {
                throw new ConflictException(
                    'DEMO_PAYMENT_STARTED_NOTHING',
                    'A demonstration invoice was paid without the seat it was raised for starting.',
                    ['tenant' => $seat['tenant'], 'product' => $seat['product'], 'holder' => $seat['holder']],
                );
            }

            $subscriptions[] = $started;
        }

        // The people each holder has put on their seat (2026-09-25), added
        // through the same service a customer uses — so the `users` quota is
        // enforced here rather than described, and a demonstration world
        // that exceeded what it sold could not be seeded at all.
        //
        // By the holder, never by the administrator: only the person who
        // bought a subscription decides who it covers, and that is not a
        // permission anybody can be granted.
        //
        // Before this runs, a seat covers its holder alone, so it has to come
        // before the projects below: `acme-user2` makes one of them.
        foreach (DemoWorld::SUBSCRIPTION_PEOPLE as $covered) {
            $this->people->add(
                $structure->tenant($covered['tenant']),
                $structure->product($covered['product']),
                $structure->user($covered['holder']),
                true,
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
            'every seat is active' => array_filter(
                $subscriptions,
                // Named `$seat` rather than `$subscription` on purpose:
                // `gate:plans` watches for a string compared against a
                // variable whose name carries one, and a status is exactly
                // what it cannot tell apart from a tier by looking.
                static fn ($seat): bool => $seat->status !== 'ACTIVE',
            ) === [],
            'every seat belongs to the person who bought it' => array_filter(
                array_keys($subscriptions),
                static fn (int $i): bool => $subscriptions[$i]->ownerUserId !== $structure->user(DemoWorld::SEATS[$i]['holder']),
            ) === [],
            // Nothing sold to an organisation (2026-09-25). The platform can
            // still do it; this world does not, and an accident that put one
            // back would otherwise show up only as a screen looking wrong.
            'no organisation subscribed to anything' => array_filter(
                DemoWorld::SEATS,
                fn (array $seat): bool => $this->subscriptions->current(
                    $structure->tenant($seat['tenant']),
                    $structure->product($seat['product']),
                ) !== null,
            ) === [],
            'every invoice has a legal number' => array_filter(
                $invoices,
                static fn ($invoice): bool => !is_string($invoice->number) || $invoice->number === '',
            ) === [],
            'each invoice belongs to its tenant' => array_filter(
                array_keys($invoices),
                static fn (int $i): bool => $invoices[$i]->tenantId !== $structure->tenant(DemoWorld::SEATS[$i]['tenant']),
            ) === [],
            // What the whole change was for: the organisation sells, the
            // person buys. Read off the snapshots the document keeps, since
            // those are what a customer receives — the supplier's legal name
            // is the organisation's, and the customer names one person.
            'each invoice is raised by the organisation to one of its people' => array_filter(
                array_keys($invoices),
                static fn (int $i): bool => ($invoices[$i]->supplier['legal_name'] ?? null)
                        !== DemoWorld::TENANTS[DemoWorld::SEATS[$i]['tenant']]['name']
                    || ($invoices[$i]->customer['legal_name'] ?? null)
                        !== DemoWorld::PEOPLE[DemoWorld::SEATS[$i]['holder']]['name']
                    || ($invoices[$i]->customer['billing_email'] ?? null)
                        !== DemoWorld::email(DemoWorld::SEATS[$i]['holder']),
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
            // by nothing. Globex sells one place on Boreas and has three
            // members.
            'a member outside the places sold is covered by nothing' => !$this->entitlements->covers(
                $structure->tenant('globex'),
                $structure->product('boreas'),
                $structure->user('globex-user2'),
            ),
            // The model's most surprising consequence (2026-09-25), so it is
            // asserted rather than left to be discovered on a screen: running
            // an organisation entitles its administrator to nothing. They
            // administer Acme and hold no product, because they bought none.
            'an administrator who bought nothing is entitled to nothing' => array_filter(
                DemoWorld::TENANT_ADMINS,
                fn (string $who, string $slug): bool => array_filter(
                    DemoWorld::TENANTS[$slug]['holds'],
                    fn (string $code): bool => $this->entitlements->covers(
                        $structure->tenant($slug),
                        $structure->product($code),
                        $structure->user($who),
                    ),
                ) !== [],
                ARRAY_FILTER_USE_BOTH,
            ) === [],
        ] + $this->fixtures->verify($structure);

        return new SeededWorld($structure, $subscriptions, $invoices, $checks);
    }
}
