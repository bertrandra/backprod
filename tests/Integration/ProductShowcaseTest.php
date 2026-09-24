<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The story a product tells on its own page (2026-09-24,
 * `docs/home-showcase-spec.md`).
 *
 * Three claims, each against the real database: the platform's own staff
 * write it and nobody else; a draft is invisible to strangers while a
 * published page is visible to everybody; and a retired product keeps its
 * page, which is the operator's decision in §11.3 and the one most likely
 * to be quietly undone by somebody adding an `active` filter.
 */
#[CoversNothing]
final class ProductShowcaseTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $plan = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = $this->id("INSERT INTO products (code, name, active) VALUES ('plan', 'Plan', true) RETURNING id");
        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
        ]);
    }

    // --- Who writes it ----------------------------------------------------------

    public function testOnlyThePlatformWritesTheStory(): void
    {
        // Support reaches every other /staff route. What a product says
        // about itself is not support's, and no tenant has this at all:
        // a page written by one customer would be read by every other
        // customer of the same product (§11.1).
        self::assertSame(403, $this->read('sam-token')->getStatusCode());
        self::assertSame(403, $this->write([self::headline()], 'sam-token')->getStatusCode());
    }

    public function testAFreshProductHasAnEmptyStoryRatherThanAnError(): void
    {
        $response = $this->read();

        self::assertSame(200, $response->getStatusCode());
        $story = $this->decode($response);

        self::assertSame([], $story['blocks'] ?? null);
        self::assertArrayHasKey('published_at', $story);
        self::assertNull($story['published_at']);
    }

    // --- Writing ----------------------------------------------------------------

    public function testTheStoryIsReplacedWholly(): void
    {
        $this->write([
            self::headline(),
            ['block' => 'STEPS', 'position' => 10, 'content' => ['title' => 'Draw the parcel']],
            ['block' => 'STEPS', 'position' => 20, 'content' => ['title' => 'The terrace follows']],
        ]);

        // Sent again with one step: the console says what the page says, so
        // the band it dropped is gone rather than left behind.
        $written = $this->write([
            self::headline(),
            ['block' => 'STEPS', 'position' => 10, 'content' => ['title' => 'Draw the parcel']],
        ]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());
        self::assertSame(
            ['HEADLINE', 'STEPS'],
            array_column($this->listIn($written, 'blocks'), 'block'),
        );
    }

    public function testABandIsRefusedRatherThanStoredWithAFieldNoBandRenders(): void
    {
        $refused = $this->write([
            ['block' => 'STEPS', 'content' => ['title' => 'Draw', 'headline' => 'Not a step field']],
        ]);

        // Refused rather than dropped: a field this endpoint stored and no
        // band rendered would be an operator's afternoon spent writing into
        // a hole.
        self::assertSame(400, $refused->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($refused)['code'] ?? null);
    }

    public function testPricingIsNotABandSomebodyCanWrite(): void
    {
        // It is a position in the order and reads the catalogue. A row for
        // it would be a row somebody could type a price into, which is the
        // one thing §9 forbids outright.
        $refused = $this->write([['block' => 'PRICING', 'content' => []]]);

        self::assertSame(400, $refused->getStatusCode());
    }

    public function testAPageHasOneHeadline(): void
    {
        $refused = $this->write([self::headline(), self::headline()]);

        self::assertSame(400, $refused->getStatusCode());
        self::assertStringContainsString(
            'HEADLINE',
            json_encode($this->errorOf($refused)['details'] ?? []) ?: '',
        );
    }

    public function testABandSaysItselfInEveryLanguageItSpeaks(): void
    {
        $written = $this->write([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace', 'subline' => 'And print the file.'],
                'translations' => ['fr' => ['headline' => 'Dessinez une terrasse']],
            ],
        ]);

        $block = $this->listIn($written, 'blocks')[0] ?? null;

        self::assertIsArray($block);
        // The console is answered with all of them: it is the only place
        // that can finish a half-translated page.
        self::assertSame(
            ['fr' => ['headline' => 'Dessinez une terrasse']],
            $block['translations'] ?? null,
        );

        // English is refused by name: it lives on the block itself, and a
        // second home for it would let the two disagree.
        $refused = $this->write([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace'],
                'translations' => ['en' => ['headline' => 'Something else']],
            ],
        ]);

        self::assertSame(400, $refused->getStatusCode());
    }

    // --- Publishing -------------------------------------------------------------

    public function testADraftIsInvisibleToStrangersAndAPublishedPageIsNot(): void
    {
        $this->write([self::headline()]);

        // Written and not published: one answer for "no such product",
        // "never published" and "does not exist", so an unpublished page is
        // not a way to learn that a product exists.
        self::assertSame(404, $this->public()->getStatusCode());

        self::assertSame(200, $this->publish(true)->getStatusCode());

        $page = $this->public();

        self::assertSame(200, $page->getStatusCode());

        $showcase = $this->decode($page)['showcase'] ?? null;

        self::assertIsArray($showcase);

        $product = $showcase['product'] ?? null;
        self::assertIsArray($product);
        self::assertSame('Plan', $product['name'] ?? null);
        self::assertTrue($product['active'] ?? null);

        $blocks = $showcase['blocks'] ?? null;
        self::assertIsArray($blocks);
        $first = $blocks[0] ?? null;
        self::assertIsArray($first);
        $content = $first['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame('Draw a terrace', $content['headline'] ?? null);

        // And it comes down again, because a page that could go up and
        // never come down would be a decision nobody could undo.
        self::assertSame(200, $this->publish(false)->getStatusCode());
        self::assertSame(404, $this->public()->getStatusCode());
    }

    public function testNothingIsNotAStory(): void
    {
        $refused = $this->publish(true);

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('SHOWCASE_INCOMPLETE', $this->errorOf($refused)['code'] ?? null);
    }

    public function testOneLanguageIsEnoughToPublishAndItIsEnglish(): void
    {
        // §11.2: a page publishes with the English filled and nothing else.
        // Waiting for five would leave the shop window dark for a product
        // that has something to say in one.
        $this->write([self::headline()]);

        self::assertSame(200, $this->publish(true)->getStatusCode());
        self::assertSame(200, $this->public()->getStatusCode());
    }

    // --- A retired product ------------------------------------------------------

    public function testARetiredProductKeepsItsPage(): void
    {
        $this->write([self::headline()]);
        $this->publish(true);

        $this->connection->executeStatement(
            'UPDATE products SET active = false WHERE id = :id',
            ['id' => $this->plan],
        );

        $page = $this->public();

        // The operator's decision (§11.3), and the one most likely to be
        // quietly undone by somebody adding an `active` filter to the read.
        self::assertSame(200, $page->getStatusCode());

        $showcase = $this->decode($page)['showcase'] ?? null;

        self::assertIsArray($showcase);

        $product = $showcase['product'] ?? null;
        self::assertIsArray($product);
        // What lets the prices band say "No longer sold" rather than
        // showing an empty band or a Buy that leads to a refusal.
        self::assertFalse($product['active'] ?? null);
    }

    // --- The trail --------------------------------------------------------------

    public function testWritingAndPublishingAreDifferentActsInTheTrail(): void
    {
        $this->write([self::headline()]);
        $this->publish(true);
        $this->publish(false);

        self::assertSame(
            ['WRITE', 'PUBLISH', 'WITHDRAW'],
            $this->connection->fetchFirstColumn(
                "SELECT action FROM staff_access_log WHERE resource_type = 'showcase' ORDER BY occurred_at, id",
            ),
        );

        $row = $this->connection->fetchAssociative(
            "SELECT tenant_id, product_id, permission FROM staff_access_log WHERE resource_type = 'showcase' LIMIT 1",
        );

        self::assertIsArray($row);
        // A product's own shop window is nobody's tenant.
        self::assertNull($row['tenant_id']);
        self::assertSame($this->plan, $row['product_id']);
        self::assertSame('staff.products.manage', $row['permission']);
    }

    public function testMerelyReadingTheStoryIsNotRecorded(): void
    {
        $this->read();
        $this->public();

        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM staff_access_log'));
    }

    // --- Helpers ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function headline(): array
    {
        return ['block' => 'HEADLINE', 'content' => ['headline' => 'Draw a terrace']];
    }

    private function read(string $token = 'ola-token'): ResponseInterface
    {
        return $this->request(
            'GET',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ' . $token],
        );
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function write(array $blocks, string $token = 'ola-token'): ResponseInterface
    {
        return $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ' . $token],
            $this->json(['blocks' => $blocks]),
        );
    }

    private function publish(bool $published): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/products/' . $this->plan . '/showcase/publish',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['published' => $published]),
        );
    }

    /** No token at all: this is the page a stranger reads. */
    private function public(): ResponseInterface
    {
        return $this->request('GET', '/api/v1/public/products/plan/showcase');
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
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
