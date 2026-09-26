<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Everything the operator wrote, in every language it has (2026-09-26).
 *
 * The console could already translate each of these rows one at a time, each
 * behind the form that owns it. What no route could answer is the question
 * somebody asks when a language is half finished — *what is missing in
 * Italian?* — because the answer spans `features` and `offers`, two tables
 * with nothing else in common.
 *
 * **This is the operator's words and not the application's.** Field labels,
 * buttons and error wording live in the bundle's JSON catalogues, keyed by the
 * English and proved complete by `gate:i18n` (ADR-050,
 * `docs/translatable-fields-spec.md` §1.5). They are not here, and this read is
 * not a reason to move them.
 *
 * A read, and only a read: writing goes back through `renameFeature` and
 * `renameStaffOffer`, which already carry the permission, the validation and
 * the trail. The tests below go through those, because a desk whose count is
 * right and whose write is unreachable is a desk that cannot finish anything.
 */
#[CoversNothing]
final class ConsoleTranslationDeskTest extends DatabaseApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Two products, so an offer's code being unique only *within* one is
        // something the desk is measured against rather than assumed away.
        $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->id("INSERT INTO products (code, name, active) VALUES ('orbit', 'Orbit', true) RETURNING id");

        $admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

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

    // --- Helpers ---------------------------------------------------------------

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
