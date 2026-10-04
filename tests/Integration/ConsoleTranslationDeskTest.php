<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
use App\Staff\Domain\StaffRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Everything the operator wrote, in every language it has (2026-09-26).
 *
 * The console could already translate each of these rows one at a time, each
 * behind the form that owns it. What no route could answer is the question
 * somebody asks when a language is half finished — *what is missing in
 * Italian?* — because the answer spans `features`, `offers` and
 * `product_showcase`, three tables with nothing else in common.
 *
 * **This is the operator's words and not the application's.** Field labels,
 * buttons and error wording live in the bundle's JSON catalogues, keyed by the
 * English and proved complete by `gate:i18n` (ADR-050,
 * `docs/translatable-fields-spec.md` §1.5). They are not here, and this read is
 * not a reason to move them.
 *
 * Since 2026-09-26 the **product showcase** is on it too — the marketing copy a
 * stranger reads before buying anything, which is the most read text this
 * platform holds and the one kind of operator sentence the first desk left out.
 * A tally that ignores it says a language is finished while the shop window is
 * still in English.
 *
 * A read, and only a read: writing goes back through `renameFeature`,
 * `renameStaffOffer` and `writeProductStory`, which already carry the
 * permission and the validation. The tests below go through those,
 * because a desk whose count is right and whose write is unreachable is a desk
 * that cannot finish anything.
 */
#[CoversNothing]
final class ConsoleTranslationDeskTest extends DatabaseApiTestCase
{
    private string $atlas;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Two products, so an offer's code being unique only *within* one is
        // something the desk is measured against rather than assumed away.
        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->id("INSERT INTO products (code, name, active) VALUES ('orbit', 'Orbit', true) RETURNING id");

        $admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        $this->admin = $admin;

        $this->appoint($admin, 'PLATFORM_ADMIN');
        $this->appoint($support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
            ]),
        ]);
    }

    // --- Who may read it -------------------------------------------------------

    public function testTheCatalogueVocabularyIsNotSupportsToRead(): void
    {
        // Support reaches every other /staff route. What a customer is sold is
        // called is the catalogue's, and this is the catalogue's own words.
        self::assertSame(403, $this->desk('sam-token')->getStatusCode());
    }

    public function testAFreshInstallationHasAnEmptyDeskRatherThanAnError(): void
    {
        $response = $this->desk();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->listIn($response, 'texts'));
    }

    // --- What a sentence is ----------------------------------------------------

    public function testAFeatureIsTwoSentencesAndAnOfferIsOne(): void
    {
        $this->describedFeature();
        $this->createOffer('atlas', 'pro-monthly', 'Pro, monthly');

        $texts = $this->listIn($this->desk(), 'texts');

        // A row per *field*, which is what lets the screen count sentences
        // rather than records: a feature whose name is translated and whose
        // description is not is one sentence missing, never none.
        self::assertSame(
            [
                ['feature', 'projects', 'name'],
                ['feature', 'projects', 'description'],
                ['offer', 'pro-monthly', 'name'],
            ],
            array_map(
                static fn (array $text): array => [$text['kind'], $text['code'], $text['field']],
                $texts,
            ),
        );
    }

    public function testADescriptionNobodyWroteIsNotASentenceWaitingForATranslator(): void
    {
        $this->createFeature(['code' => 'api', 'name' => 'API access', 'kind' => 'BOOLEAN']);

        $texts = $this->listIn($this->desk(), 'texts');

        // Listing it would count a translation nobody owes, and the tally is
        // the whole reason this screen exists.
        self::assertSame(['name'], array_map(
            static fn (array $text): string => self::said($text, 'field'),
            $texts,
        ));
    }

    public function testAnOfferCarriesItsProductAndAFeatureCarriesNone(): void
    {
        $this->createFeature(['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects']);
        $this->createOffer('atlas', 'pro-monthly', 'Pro, monthly');
        $this->createOffer('orbit', 'pro-monthly', 'Pro for Orbit');

        $byCode = [];

        foreach ($this->listIn($this->desk(), 'texts') as $text) {
            $key = self::said($text, 'kind') . ':' . self::said($text, 'code');
            $byCode[$key . ':' . ($text['product'] === null ? '' : self::said($text, 'product'))] = $text['source'];
        }

        // Two offers, the same code, told apart by the product — and the
        // product is also what `renameStaffOffer` requires, because a staff
        // route resolves no product of its own.
        self::assertSame('Pro, monthly', $byCode['offer:pro-monthly:atlas'] ?? null);
        self::assertSame('Pro for Orbit', $byCode['offer:pro-monthly:orbit'] ?? null);
        // The platform's own vocabulary belongs to no product (ADR-052).
        self::assertSame('Projects', $byCode['feature:projects:'] ?? null);
    }

    // --- What is written, and what is missing ----------------------------------

    public function testOnlyWhatIsWrittenIsListed(): void
    {
        $feature = $this->describedFeature();

        $this->patchFeature($feature, [
            'name' => 'Projects',
            'translations' => [
                'fr' => ['name' => 'Projets', 'description' => 'Combien vous pouvez en garder.'],
                // Spanish says the name and not the description: a language
                // half done is the ordinary state of a catalogue.
                'es' => ['name' => 'Proyectos'],
            ],
        ]);

        $texts = $this->byField($this->listIn($this->desk(), 'texts'));

        // `assertEquals` and not `assertSame`: the desk orders by locale, and
        // the order of a map by language is not a fact anybody depends on.
        self::assertEquals(
            ['es' => 'Proyectos', 'fr' => 'Projets'],
            $texts['feature:projects:name']['translations'] ?? null,
        );
        // Not `['fr' => …, 'es' => '']`: a locale absent is a translation
        // missing, and filling the gaps with empty strings here would make
        // every sentence look translated into every language.
        self::assertSame(
            ['fr' => 'Combien vous pouvez en garder.'],
            $texts['feature:projects:description']['translations'] ?? null,
        );
    }

    public function testASentenceWhoseEnglishWasClearedIsStillListed(): void
    {
        $feature = $this->describedFeature();

        $this->patchFeature($feature, [
            'name' => 'Projects',
            'translations' => ['fr' => ['name' => 'Projets', 'description' => 'Combien vous pouvez en garder.']],
        ]);

        // The English cleared while the French stays: `description: null`
        // leaves the translations where they are.
        $this->patchFeature($feature, ['name' => 'Projects', 'description' => null]);

        $texts = $this->byField($this->listIn($this->desk(), 'texts'));

        // Hiding this row would hide the inconsistency — and would hand the
        // screen a set with the French missing, which the next write, being a
        // replacement, would delete.
        self::assertSame('', $texts['feature:projects:description']['source'] ?? null);
        self::assertSame(
            ['fr' => 'Combien vous pouvez en garder.'],
            $texts['feature:projects:description']['translations'] ?? null,
        );
    }

    // --- The write the desk hands off to ---------------------------------------

    public function testATranslationWrittenThroughTheOwningOperationShowsOnTheDesk(): void
    {
        $offer = $this->createOffer('atlas', 'pro-monthly', 'Pro, monthly');
        $offerId = $this->itemIn($offer, 'offer')['id'] ?? null;

        self::assertIsString($offerId);

        $renamed = $this->request(
            'PATCH',
            '/api/v1/staff/catalogue/offers/' . $offerId . '?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['name' => 'Pro, monthly', 'translations' => ['it' => ['name' => 'Pro, mensile']]]),
        );

        self::assertSame(200, $renamed->getStatusCode());

        $texts = $this->byField($this->listIn($this->desk(), 'texts'));

        // There is no `saveTranslation`, and there must not be: a second way
        // to write the same table is the drift the gates exist to prevent.
        self::assertSame(
            ['it' => 'Pro, mensile'],
            $texts['offer:pro-monthly:name']['translations'] ?? null,
        );
    }

    // --- The product showcase (2026-09-26) -------------------------------------

    public function testEveryStringFieldOfABandIsASentence(): void
    {
        $this->writeStory([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace', 'subline' => 'And know what it costs.'],
            ],
            [
                'block' => 'QUESTION',
                'position' => 20,
                'content' => ['question' => 'Does it export?', 'answer' => 'DXF and PDF.'],
            ],
        ]);

        $texts = $this->listIn($this->desk(), 'texts');

        // A path per field and not a row per band: the field names are the
        // band's own, derived from the stored object, so a sixth band arrives
        // on this desk with nothing changed to make room for it. Ordered by
        // name, which is the only order available to something that refuses to
        // know the five shapes.
        self::assertSame(
            [
                ['showcase', 'atlas', 'HEADLINE.10.headline'],
                ['showcase', 'atlas', 'HEADLINE.10.subline'],
                ['showcase', 'atlas', 'QUESTION.20.answer'],
                ['showcase', 'atlas', 'QUESTION.20.question'],
            ],
            array_map(
                static fn (array $text): array => [$text['kind'], $text['code'], $text['field']],
                $texts,
            ),
        );

        foreach ($texts as $text) {
            // The **product's** id, because `writeProductStory` replaces the
            // whole story: there is no route that takes a band's id, so one
            // here would be an identifier nothing accepts. And no product
            // badge: the story is the product's, and `code` already says which.
            self::assertSame($this->atlas, $text['id'] ?? null);
            self::assertArrayHasKey('product', $text);
            self::assertNull($text['product']);
        }
    }

    public function testAValueThatIsNotAStringIsNotASentence(): void
    {
        // Written straight into the table, because the endpoint refuses it:
        // `ShowcaseBlocks` accepts strings only. What this proves is that the
        // desk does not fall over — or offer a box — for a row a future band
        // storing a list would produce, and that a translator is never handed a
        // JSON fragment to translate.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_showcase (product_id, block, position, content)
                VALUES (:product, 'STEPS', 10, CAST(:content AS jsonb))
                SQL,
            [
                'product' => $this->atlas,
                'content' => $this->json(['title' => 'Trace the outline', 'body' => ['one', 'two']]),
            ],
        );

        // Nothing a reader would ever see either: a story resolves a translated
        // field only when it is a non-blank string, so a translation of a list
        // would be work that changes nothing on any screen.
        self::assertSame(['STEPS.10.title'], array_map(
            static fn (array $text): string => self::said($text, 'field'),
            $this->listIn($this->desk(), 'texts'),
        ));
    }

    public function testABandTranslatedInOneLanguageIsMissingInTheOther(): void
    {
        $this->writeStory([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace', 'subline' => 'And know what it costs.'],
                // Italian on the headline and nowhere else: a page half
                // translated is the ordinary state of one somebody is working
                // through (home-showcase-spec §11.2).
                'translations' => ['it' => ['headline' => 'Disegna una terrazza']],
            ],
        ]);

        $texts = $this->byField($this->listIn($this->desk(), 'texts'));

        self::assertSame(
            ['it' => 'Disegna una terrazza'],
            $texts['showcase:atlas:HEADLINE.10.headline']['translations'] ?? null,
        );
        // Not `['it' => '']`: a locale absent is a translation missing, which is
        // the fact the tally counts. One sentence short in Italian, not none.
        self::assertSame([], $texts['showcase:atlas:HEADLINE.10.subline']['translations'] ?? null);
    }

    public function testATranslationWrittenThroughTheStoryShowsOnTheDesk(): void
    {
        $this->writeStory([
            ['block' => 'HEADLINE', 'content' => ['headline' => 'Draw a terrace']],
        ]);

        // The write that follows a save on the desk is this one, whole: every
        // band, its content and all four languages. There is no route that
        // writes one band, and there must not be.
        $this->writeStory([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace'],
                'translations' => ['it' => ['headline' => 'Disegna una terrazza']],
            ],
        ]);

        $texts = $this->byField($this->listIn($this->desk(), 'texts'));

        self::assertSame(
            ['it' => 'Disegna una terrazza'],
            $texts['showcase:atlas:HEADLINE.10.headline']['translations'] ?? null,
        );
    }

    public function testASentenceDroppedFromTheEnglishIsStillListed(): void
    {
        $this->writeStory([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace', 'subline' => 'And know what it costs.'],
                'translations' => [
                    'fr' => ['headline' => 'Dessinez une terrasse', 'subline' => 'Et sachez ce qu’elle coûte.'],
                ],
            ],
        ]);

        // The English subline dropped while the French one stays.
        $this->writeStory([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace'],
                'translations' => [
                    'fr' => ['headline' => 'Dessinez une terrasse', 'subline' => 'Et sachez ce qu’elle coûte.'],
                ],
            ],
        ]);

        $texts = $this->byField($this->listIn($this->desk(), 'texts'));

        // Listed, with nothing above the box. A story resolves field by field,
        // so that French subline is on a French reader's screen with no English
        // it corresponds to — the same defect as a feature description the
        // operator cleared, and worth seeing for the same reason.
        self::assertSame('', $texts['showcase:atlas:HEADLINE.10.subline']['source'] ?? null);
        self::assertSame(
            ['fr' => 'Et sachez ce qu’elle coûte.'],
            $texts['showcase:atlas:HEADLINE.10.subline']['translations'] ?? null,
        );
    }

    public function testAnUnpublishedStoryIsOnTheDesk(): void
    {
        $this->writeStory([
            ['block' => 'HEADLINE', 'content' => ['headline' => 'Draw a terrace']],
        ]);

        // Never published, and that is the point: a draft is the story most
        // likely to be half translated, and a desk that waited for publication
        // would hide the work while it was still work.
        self::assertNull($this->connection->fetchOne(
            'SELECT showcase_published_at FROM products WHERE id = :id',
            ['id' => $this->atlas],
        ));
        self::assertSame(['HEADLINE.10.headline'], array_map(
            static fn (array $text): string => self::said($text, 'field'),
            $this->listIn($this->desk(), 'texts'),
        ));
    }

    // --- Two permissions, and three combinations -------------------------------

    public function testTheCatalogueWithoutTheProductsGetsTheCatalogueOnly(): void
    {
        $this->somethingOfEach();
        $this->holding([StaffPermission::CATALOG_MANAGE]);

        // Exactly the answer this desk gave before the showcase joined it. The
        // stories are withheld rather than shown unwritable: the story read
        // keeps drafts behind `staff.products.manage`, and a tally counting
        // sentences somebody would be refused is a number they cannot work
        // through.
        self::assertSame(['feature', 'offer'], $this->kindsOnTheDesk());
    }

    public function testTheProductsWithoutTheCatalogueGetsTheStoriesOnly(): void
    {
        $this->somethingOfEach();
        $this->holding([StaffPermission::PRODUCTS_MANAGE]);

        // A read they are owed, and one the whole route refused until now:
        // whoever writes a product's page is who the showcase has answered to
        // since it was built (home-showcase-spec §11.1).
        self::assertSame(['showcase'], $this->kindsOnTheDesk());
    }

    public function testHoldingBothCountsEverythingInOneTally(): void
    {
        $this->somethingOfEach();
        $this->holding([StaffPermission::CATALOG_MANAGE, StaffPermission::PRODUCTS_MANAGE]);

        // Which is what PLATFORM_ADMIN holds, and the whole point of the
        // screen: one number for how finished a language is.
        self::assertSame(['feature', 'offer', 'showcase'], $this->kindsOnTheDesk());
    }

    public function testNeitherIsRefused(): void
    {
        $this->somethingOfEach();
        $this->holding([StaffPermission::TENANTS_READ]);

        $refused = $this->desk();

        self::assertSame(403, $refused->getStatusCode());

        $error = $this->itemIn($refused, 'error');
        $details = $error['details'] ?? null;

        self::assertIsArray($details);
        // Named, and named as one: `details.permission` holds a permission
        // code, and two joined into a sentence would be a string no catalogue
        // contains.
        self::assertSame(StaffPermission::CATALOG_MANAGE, $details['permission'] ?? null);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * A feature, an offer and a story, so a filtered answer is visibly
     * filtered.
     */
    private function somethingOfEach(): void
    {
        $this->createFeature(['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects']);
        $this->createOffer('atlas', 'pro-monthly', 'Pro, monthly');
        $this->writeStory([
            ['block' => 'HEADLINE', 'content' => ['headline' => 'Draw a terrace']],
        ]);
    }

    /**
     * Which kinds of sentence the desk answered, deduplicated and in order.
     *
     * @return list<string>
     */
    private function kindsOnTheDesk(): array
    {
        $kinds = [];

        foreach ($this->listIn($this->desk(), 'texts') as $text) {
            $kinds[self::said($text, 'kind')] = true;
        }

        return array_keys($kinds);
    }

    /**
     * The platform administrator, holding exactly these permissions and no
     * others, for the rest of this test.
     *
     * The repository is replaced rather than the grant revoked: the three
     * permissions this desk is about are PLATFORM_ADMIN's and nobody else's, so
     * there is no role to borrow that holds one and not the other — and
     * `platform_role_permissions` is reference data the migrations write, which
     * `TestDatabase::reset` deliberately leaves alone, so a revoked row would
     * still be revoked in the next test. What is under test here is the
     * controller's decision given a set of permissions; which role holds which
     * is the migrations' business and `gate:roles`'.
     *
     * @param list<string> $permissions
     */
    private function holding(array $permissions): void
    {
        $this->override([
            StaffRepository::class => new class ($this->admin, $permissions) implements StaffRepository {
                /**
                 * @param list<string> $permissions
                 */
                public function __construct(
                    private readonly string $staff,
                    private readonly array $permissions,
                ) {
                }

                public function find(string $userId): ?StaffIdentity
                {
                    return $userId === $this->staff
                        ? new StaffIdentity($userId, ['PLATFORM_ADMIN'], $this->permissions)
                        : null;
                }
            },
        ]);
    }

    /**
     * A product's whole story, through the operation that owns it.
     *
     * PUT and not PATCH, and that is the difference the desk has to live with:
     * this replaces every band, its content and all four of its languages, so
     * a screen editing one sentence of it has to hold — or re-read — all of it.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function writeStory(array $blocks): ResponseInterface
    {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->atlas . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => $blocks]),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    private function desk(string $token = 'ola-token'): ResponseInterface
    {
        return $this->request('GET', '/api/v1/staff/translations', ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createFeature(array $body): ResponseInterface
    {
        $response = $this->request(
            'POST',
            '/api/v1/staff/features',
            ['Authorization' => 'Bearer ola-token'],
            $this->json($body),
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchFeature(string $featureId, array $body): ResponseInterface
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/staff/features/' . $featureId,
            ['Authorization' => 'Bearer ola-token'],
            $this->json($body),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * A plan and an offer in one product, through the console's own routes.
     */
    private function createOffer(string $product, string $code, string $name): ResponseInterface
    {
        $plan = $this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=' . $product,
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'pro-' . $product, 'name' => 'Pro', 'rank' => 10]),
        );

        self::assertSame(201, $plan->getStatusCode(), (string) $plan->getBody());

        $planId = $this->itemIn($plan, 'plan')['id'] ?? null;

        self::assertIsString($planId);

        $offer = $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers?product=' . $product,
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'code' => $code,
                'name' => $name,
                'plan_id' => $planId,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
            ]),
        );

        self::assertSame(201, $offer->getStatusCode(), (string) $offer->getBody());

        return $offer;
    }

    /**
     * A feature with both of its sentences written.
     *
     * Two calls rather than one, and not by choice: `createFeature` takes a
     * code, a name, a kind and a unit, and a description is only ever written
     * by `renameFeature`. Worth saying where somebody would otherwise read the
     * two calls as ceremony.
     */
    private function describedFeature(): string
    {
        $feature = $this->featureId($this->createFeature([
            'code' => 'projects',
            'name' => 'Projects',
            'kind' => 'QUOTA',
            'unit' => 'projects',
        ]));

        $this->patchFeature($feature, [
            'name' => 'Projects',
            'description' => 'How many you may keep at once.',
        ]);

        return $feature;
    }

    private function featureId(ResponseInterface $response): string
    {
        $id = $this->itemIn($response, 'feature')['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    /**
     * The desk by `kind:code:field`, which is how a test names one sentence.
     *
     * @param list<array<string, mixed>> $texts
     *
     * @return array<string, array<string, mixed>>
     */
    private function byField(array $texts): array
    {
        $byField = [];

        foreach ($texts as $text) {
            $byField[self::said($text, 'kind') . ':' . self::said($text, 'code') . ':' . self::said($text, 'field')] = $text;
        }

        return $byField;
    }

    /**
     * One string out of a decoded row, asserted rather than cast.
     *
     * `(string)` on `mixed` is how a response that answered `null` where a
     * string was required passes as an empty string, which is a test that
     * proves nothing.
     *
     * @param array<string, mixed> $row
     */
    private static function said(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        self::assertIsString($value, $key . ' is not a string');

        return $value;
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($identifier);

        return $identifier;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemIn(ResponseInterface $response, string $key): array
    {
        $item = $this->decode($response)[$key] ?? null;

        self::assertIsArray($item, $key . ' missing from ' . (string) $response->getBody());

        /** @var array<string, mixed> $item */
        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listIn(ResponseInterface $response, string $key): array
    {
        $rows = $this->decode($response)[$key] ?? null;

        self::assertIsArray($rows);

        $items = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $items[] = $row;
        }

        return $items;
    }
}
