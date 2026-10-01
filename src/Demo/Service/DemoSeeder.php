<?php

declare(strict_types=1);

namespace App\Demo\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Service\Invoicing;
use App\Commerce\Service\Freemium;
use App\Commerce\Service\SubscriptionPeople;
use App\Commerce\Service\Subscriptions;
use App\Demo\Domain\DemoFixtures;
use App\Demo\Domain\DemoWorld;
use App\Demo\Domain\SeededWorld;
use App\Entitlement\Domain\EntitlementRepository;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Project\Service\ProjectWorkspace;
use App\Sales\Service\Sales;
use App\Shared\Exceptions\ConflictException;
use RuntimeException;
use stdClass;

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
 * **And some of it is collected** (2026-09-26). Two of the five seats are paid
 * through the payment chain rather than recorded as a transfer — one of them
 * after an attempt the card refused — and one order is left owing, its only
 * attempt declined, because that is the state the Payments screen's *Try
 * again* exists for and a retry against a settled invoice is refused. The
 * world had five invoices and no payment at all until today, which made
 * `/payments` permanently empty in the one place the platform is shown to
 * people. See {@see DemoCollection}.
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
        private readonly Freemium $freemium,
        private readonly Invoicing $invoicing,
        private readonly ProjectWorkspace $workspace,
        private readonly SubscriptionPeople $people,
        private readonly EntitlementRepository $entitlements,
        private readonly Sales $sales,
        private readonly DemoCollection $collection,
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
                $this->sales->order($tenant, $product, $structure->offer($seat['product'], $seat['offer']), $buyer)->id,
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
            $invoices[] = $this->collect($seat, $tenant, $product, $order->invoiceId, $buyer, $collector);

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

        // The debt (2026-09-26), after every seat so the numbers already in
        // each issuer's series are the ones the world sold.
        //
        // An order fulfilled, its invoice issued and owed, one attempt the
        // card refused, and no seat. It is what the Payments screen's *Try
        // again* is for: a retry against an invoice that has since been paid
        // is refused, so a world where every failure sits on a settled
        // document offers a button that can only answer with an error.
        $declined = [];

        foreach (DemoWorld::DECLINED_ORDERS as $unpaid) {
            $tenant = $structure->tenant($unpaid['tenant']);
            $product = $structure->product($unpaid['product']);
            $buyer = $structure->user($unpaid['buyer']);

            $order = $this->sales->fulfil(
                $tenant,
                $product,
                $this->sales->order($tenant, $product, $structure->offer($unpaid['product'], $unpaid['offer']), $buyer)->id,
                $buyer,
            );

            if ($order->invoiceId === null) {
                throw new ConflictException(
                    'DEMO_ORDER_RAISED_NO_INVOICE',
                    'A demonstration seat was fulfilled without an invoice, which no priced offer can do.',
                    ['tenant' => $unpaid['tenant'], 'product' => $unpaid['product'], 'offer' => $unpaid['offer']],
                );
            }

            $declined[] = $this->collection->declined(
                $this->invoicing->show($tenant, $product, $order->invoiceId),
                $buyer,
                $unpaid['failure']['code'],
                $unpaid['failure']['reason'],
            );
        }

        // The free period (2026-09-27, spec §6), through the only door it has.
        //
        // Not `Sales::order` and not `openCheckoutSession`: the price is zero,
        // so there is no order, no invoice and no payment — and numbering being
        // gapless, the €0 invoice that chain would raise is a permanent,
        // unremovable record of no transaction. `Sales::order` refuses this
        // offer outright for that reason, so a seeder that tried it would not
        // get a wrong world, it would get no world.
        //
        // Before the projects below, because the one this person makes is
        // counted against the quota the free period sells.
        $freemium = [];

        foreach (DemoWorld::FREEMIUM as $trying) {
            $freemium[] = $this->freemium->take(
                $structure->tenant($trying['tenant']),
                $structure->product($trying['product']),
                $structure->offer($trying['product'], $trying['offer']),
                $structure->user($trying['holder']),
            );
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
            [$document, $schemaVersion] = $this->documentOf($draft);

            $projects[] = $this->workspace->create(
                $structure->tenant($draft['tenant']),
                $structure->product($draft['product']),
                $structure->user($draft['by']),
                $draft['name'],
                $draft['description'],
                $schemaVersion,
                $document,
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
            // The money that did not arrive (2026-09-26). A failed payment
            // with no reason on it is a row the screen renders as a blank
            // apology, and the retry it offers has to have a debt to act
            // against — so the attempt is FAILED, it says why, and the seat
            // it would have started does not exist.
            'the attempt the card refused is recorded, with its reason' => count($declined) === count(DemoWorld::DECLINED_ORDERS)
                && array_filter(
                    $declined,
                    static fn (Payment $attempt): bool => $attempt->status !== PaymentStatus::FAILED
                        || $attempt->failureCode === null
                        || $attempt->failureReason === null,
                ) === [],
            'an order whose payment failed started no seat' => array_filter(
                DemoWorld::DECLINED_ORDERS,
                fn (array $unpaid): bool => $this->subscriptions->seatOf(
                    $structure->tenant($unpaid['tenant']),
                    $structure->product($unpaid['product']),
                    $structure->user($unpaid['buyer']),
                ) !== null,
            ) === [],
            // The free period belongs to the person trying it (2026-09-27), and
            // it says so on the row rather than by a join to the offer: what is
            // remembered is what was sold, because the offer may be repriced
            // tomorrow and this account has still had its one (§6.4).
            'the free period is held by the person who took it, and says it is one' => count($freemium) === count(DemoWorld::FREEMIUM)
                && array_filter(
                    array_keys($freemium),
                    static fn (int $i): bool => !$freemium[$i]->isFreemium
                        || $freemium[$i]->ownerUserId !== $structure->user(DemoWorld::FREEMIUM[$i]['holder'])
                        || $freemium[$i]->subscriberUserId !== $structure->user(DemoWorld::FREEMIUM[$i]['holder']),
                ) === [],
            // And it covers them, which is the whole of it: five free days that
            // opened no workspace would be a card on a page. Asked through the
            // port the platform itself asks, so a world that seeded is a world
            // somebody could have used.
            'the free period covers its holder' => array_filter(
                DemoWorld::FREEMIUM,
                fn (array $trying): bool => !$this->entitlements->coverageFor(
                    $structure->tenant($trying['tenant']),
                    $structure->product($trying['product']),
                    $structure->user($trying['holder']),
                )->covers(),
            ) === [],
            // The rule the whole demonstration now turns on (2026-09-25):
            // whoever made a project was covered by a subscription at the
            // time. Asked through the port the platform itself asks, so a
            // world that seeded is a world somebody could have built.
            'every project was made by somebody a subscription covers' => array_filter(
                DemoWorld::PROJECTS,
                fn (array $draft): bool => !$this->entitlements->coverageFor(
                    $structure->tenant($draft['tenant']),
                    $structure->product($draft['product']),
                    $structure->user($draft['by']),
                )->covers(),
            ) === [],
            // And the other half, which is what makes the number sold mean
            // something: a member the subscription does not cover is covered
            // by nothing. Globex sells one place on Boreas and has three
            // members.
            'a member outside the places sold is covered by nothing' => !$this->entitlements->coverageFor(
                $structure->tenant('globex'),
                $structure->product('boreas'),
                $structure->user('globex-user2'),
            )->covers(),
            // The model's most surprising consequence (2026-09-25), so it is
            // asserted rather than left to be discovered on a screen: running
            // an organisation entitles its administrator to nothing. They
            // administer Acme and hold no product, because they bought none.
            'an administrator who bought nothing is entitled to nothing' => array_filter(
                DemoWorld::TENANT_ADMINS,
                fn (string $who, string $slug): bool => array_filter(
                    DemoWorld::TENANTS[$slug]['holds'],
                    fn (string $code): bool => $this->entitlements->coverageFor(
                        $structure->tenant($slug),
                        $structure->product($code),
                        $structure->user($who),
                    )->covers(),
                ) !== [],
                ARRAY_FILTER_USE_BOTH,
            ) === [],
        ] + $this->fixtures->verify($structure);

        return new SeededWorld($structure, $subscriptions, $invoices, $checks);
    }

    /**
     * How a seat's money arrived.
     *
     * Two paths, both real, and a deployment has both: a transfer that
     * landed outside the platform and which the organisation's administrator
     * records, or a card the buyer put in — which goes through the payment
     * chain and settles the invoice by succeeding, rather than being marked
     * settled by anybody.
     *
     * The invoice is re-read afterwards for the same reason: the payment is
     * what settled it, so the document the checks read must be the one the
     * payment left behind, not the one that existed before it.
     *
     * @param array{tenant: string, product: string, offer: string, holder: string, card?: bool, declined?: array{code: string, reason: string}} $seat
     */
    private function collect(
        array $seat,
        string $tenant,
        string $product,
        string $invoiceId,
        string $buyer,
        string $collector,
    ): Invoice {
        if (($seat['card'] ?? false) === false) {
            return $this->invoicing->markPaid($tenant, $product, $invoiceId, $collector);
        }

        $declined = $seat['declined'] ?? null;

        if ($declined !== null) {
            // The attempt that failed comes first and stays failed for ever:
            // it is the record of what happened, and the screen shows it
            // beside the one that went through.
            $this->collection->declined(
                $this->invoicing->show($tenant, $product, $invoiceId),
                $buyer,
                $declined['code'],
                $declined['reason'],
            );
        }

        $this->collection->collected($this->invoicing->show($tenant, $product, $invoiceId), $buyer);

        return $this->invoicing->show($tenant, $product, $invoiceId);
    }

    /**
     * A project's document and the version it is written in.
     *
     * Two shapes, and the difference is what is known rather than a style:
     * a place-holder is written inline, and a real product document is
     * exported by that product and named by file — Plan's is 42 KB and 35
     * objects, and as a PHP literal it would bury this file.
     *
     * **The version comes from the document that claims one.** A real export
     * says which version wrote it, and seeding it as anything else would be
     * the demonstration asserting something false about its own data. A
     * place-holder claims nothing, so it takes the first version the product
     * accepts — which is what every project here did before one of them was
     * real.
     *
     * @param array<string, mixed> $draft
     *
     * @return array{object, int}
     */
    private function documentOf(array $draft): array
    {
        $product = $draft['product'];
        assert(is_string($product));

        $file = $draft['document_file'] ?? null;

        if (!is_string($file)) {
            $inline = $draft['document'] ?? [];
            assert(is_array($inline));

            return [(object) $inline, DemoWorld::schemaVersionsFor($product)[0]];
        }

        $path = DemoWorld::DOCUMENTS . '/' . $file;
        $raw = file_get_contents($path);

        if ($raw === false) {
            // Raised rather than skipped: a demonstration missing the document
            // it was built to show is a world that seeds green and shows an
            // empty parcel, which is the failure nobody goes looking for.
            throw new RuntimeException('The demonstration document ' . $file . ' is missing.');
        }

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $version = $fixture['schema_version'] ?? null;
        assert(is_int($version));

        if (!in_array($version, DemoWorld::schemaVersionsFor($product), true)) {
            // The product would refuse it at the workspace anyway; refusing
            // here says *which* file and *which* product, which the 422 that
            // came out of a seed pass could not.
            throw new RuntimeException(sprintf(
                '%s is written in schema %d, which %s does not accept.',
                $file,
                $version,
                $product,
            ));
        }

        // Decoded a second time as objects: the workspace stores what it is
        // given, and a JSON object that arrived as a PHP array would be
        // re-encoded as one — which for an empty object is `[]` and not `{}`.
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        assert($document instanceof stdClass && $document->document instanceof stdClass);

        return [$document->document, $version];
    }
}
