<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\ShowcaseSections;
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

    /**
     * A picture may be described on every band that can carry one, and on
     * no other (2026-09-28).
     *
     * `alt` is a band field rather than a column on the picture, so it goes
     * through the same translation mechanism as every sentence on the page.
     * That only works where the band accepts it — and a band that shows no
     * picture must keep refusing it, or an operator writes a description of
     * nothing and the loop above stops meaning anything.
     */
    public function testEveryBandThatShowsAPictureTakesADescriptionOfIt(): void
    {
        $written = $this->write([
            ['block' => 'STEPS', 'content' => ['title' => 'Draw', 'alt' => 'The parcel, traced']],
            ['block' => 'USE_CASE', 'content' => ['who' => 'A landscaper', 'alt' => 'At a laptop']],
        ]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $described = [];

        foreach ($this->listIn($written, 'blocks') as $block) {
            self::assertIsArray($block);
            self::assertIsString($block['block'] ?? null);
            self::assertIsArray($block['content'] ?? null);
            $described[$block['block']] = $block['content']['alt'] ?? null;
        }

        self::assertSame('The parcel, traced', $described['STEPS'] ?? null);
        self::assertSame('At a laptop', $described['USE_CASE'] ?? null);

        // The questions band shows no picture, so a description of one is a
        // field no band renders — refused, not stored.
        $refused = $this->write([
            ['block' => 'QUESTION', 'content' => ['question' => 'Can I cancel?', 'answer' => 'Yes.', 'alt' => 'Of what?']],
        ]);

        self::assertSame(400, $refused->getStatusCode());
    }

    /**
     * The order of the page belongs to the product (2026-09-28).
     *
     * It was a constant compiled into the frontend bundle, so an operator
     * who wanted the prices above the questions could do nothing about it
     * without a rebuild and a redeploy.
     */
    public function testAProductDecidesTheOrderOfItsOwnSections(): void
    {
        // Never reordered: the default, answered in full rather than as an
        // absence the client would have to know the meaning of.
        $fresh = $this->request('GET', '/api/v1/staff/products/' . $this->plan . '/showcase', ['Authorization' => 'Bearer ola-token']);
        self::assertSame(200, $fresh->getStatusCode());
        self::assertSame(
            ShowcaseSections::DEFAULT_ORDER,
            $this->decode($fresh)['sections'] ?? null,
        );

        $chosen = ['QUESTION', 'PRICING', 'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'DEMO', 'PROOF', 'CTA', 'FEATURE', 'STATS', 'TRUST', 'COMPARE', 'TOUR', 'ANNOUNCE', 'VIDEO', 'HOTSPOT', 'VERSUS', 'CONTACT'];

        $written = $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => [self::headline()], 'sections' => $chosen]),
        );

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());
        self::assertSame($chosen, $this->decode($written)['sections'] ?? null);

        // And a stranger reads it: the order *is* the page, and a reader
        // given the bands without it would be given the database's order,
        // which is nobody's decision.
        $this->publish(true);

        $public = $this->request('GET', '/api/v1/public/products/plan/showcase', []);
        self::assertSame(200, $public->getStatusCode());

        $showcase = $this->decode($public)['showcase'] ?? null;
        self::assertIsArray($showcase);
        self::assertSame($chosen, $showcase['sections'] ?? null);
    }

    /**
     * An order that is not a permutation is refused, and an absent one
     * leaves the page alone.
     *
     * The second half is the one that matters: the translation desk writes
     * one sentence through this same operation by re-reading the story and
     * sending the blocks back, and it carries no order. If absent meant
     * "the default", translating a headline would reorder the page.
     */
    public function testAnOrderIsAPermutationAndAnAbsentOneChangesNothing(): void
    {
        $chosen = ['QUESTION', 'PRICING', 'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'DEMO', 'PROOF', 'CTA', 'FEATURE', 'STATS', 'TRUST', 'COMPARE', 'TOUR', 'ANNOUNCE', 'VIDEO', 'HOTSPOT', 'VERSUS', 'CONTACT'];

        $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => [self::headline()], 'sections' => $chosen]),
        );

        // A write that says nothing about the order: the order stays.
        $again = $this->write([self::headline()]);
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($chosen, $this->decode($again)['sections'] ?? null);

        // A subset, a section named twice, and one nobody has heard of.
        foreach ([
            ['HEADLINE', 'STEPS'],
            // The right length, so what is refused is the content and not
            // the count: the last section replaced by a repeat, then by a
            // name nobody has heard of.
            [...array_slice(ShowcaseSections::DEFAULT_ORDER, 0, -1), 'HEADLINE'],
            [...array_slice(ShowcaseSections::DEFAULT_ORDER, 0, -1), 'TESTIMONIAL'],
        ] as $sections) {
            $refused = $this->request(
                'PUT',
                '/api/v1/staff/products/' . $this->plan . '/showcase',
                ['Authorization' => 'Bearer ola-token'],
                $this->json(['blocks' => [self::headline()], 'sections' => $sections]),
            );

            self::assertSame(400, $refused->getStatusCode(), json_encode($sections) ?: '');
        }

        // And none of the refusals moved it.
        $story = $this->request('GET', '/api/v1/staff/products/' . $this->plan . '/showcase', ['Authorization' => 'Bearer ola-token']);
        self::assertSame($chosen, $this->decode($story)['sections'] ?? null);
    }

    /**
     * A band carries its own heading, and `PRICING` is why it is a table of
     * its own (2026-09-28).
     */
    public function testEveryBandCarriesItsOwnHeadingAndPricingCarriesOnlyOne(): void
    {
        $written = $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'blocks' => [self::headline()],
                'bands' => [
                    'STEPS' => [
                        'content' => ['eyebrow' => 'The tutorial', 'title' => 'Trace, place, print.'],
                        'translations' => ['fr' => ['title' => 'Tracer, poser, imprimer.']],
                    ],
                    // The one section with a heading and no row anybody can
                    // write: a row for it would be a row somebody could type
                    // a price into.
                    'PRICING' => ['content' => ['title' => 'The first plan costs nothing.']],
                ],
            ]),
        );

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $bands = $this->decode($written)['bands'] ?? null;
        self::assertIsArray($bands);
        self::assertIsArray($bands['STEPS'] ?? null);
        self::assertSame('Trace, place, print.', $this->sentenceAt($bands, ['STEPS', 'content', 'title']));
        self::assertSame('Tracer, poser, imprimer.', $this->sentenceAt($bands, ['STEPS', 'translations', 'fr', 'title']));
        self::assertIsArray($bands['PRICING'] ?? null);

        // A band never retitled is absent, not an empty title: the page then
        // reads the words its component was written with.
        self::assertArrayNotHasKey('QUESTION', $bands);

        // And a stranger reads them resolved into one language.
        $this->publish(true);
        $public = $this->request('GET', '/api/v1/public/products/plan/showcase', ['Accept-Language' => 'fr']);
        self::assertSame(200, $public->getStatusCode());

        $showcase = $this->decode($public)['showcase'] ?? null;
        self::assertIsArray($showcase);
        self::assertIsArray($showcase['bands'] ?? null);
        // Field by field: the title is French, the eyebrow was never
        // translated and stays English rather than disappearing.
        self::assertSame('Tracer, poser, imprimer.', $this->sentenceAt($showcase, ['bands', 'STEPS', 'title']));
        self::assertSame('The tutorial', $this->sentenceAt($showcase, ['bands', 'STEPS', 'eyebrow']));
    }

    /**
     * An absent `bands` leaves the titles alone — the same rule the order
     * follows, and load-bearing for the same reason: the translation desk
     * writes one sentence through this operation carrying neither.
     */
    public function testAnAbsentHeadingMapChangesNothingAndAnEmptyOneRemovesIt(): void
    {
        $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'blocks' => [self::headline()],
                'bands' => ['STEPS' => ['content' => ['title' => 'Trace, place, print.']]],
            ]),
        );

        $again = $this->write([self::headline()]);
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(
            'Trace, place, print.',
            $this->sentenceAt($this->decode($again), ['bands', 'STEPS', 'content', 'title']),
        );

        // Sent empty, the set is replaced: the band goes back to its default.
        $cleared = $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => [self::headline()], 'bands' => new \stdClass()]),
        );

        self::assertSame(200, $cleared->getStatusCode(), (string) $cleared->getBody());
        self::assertSame([], (array) ($this->decode($cleared)['bands'] ?? null));
    }

    /**
     * An icon is a code and not a sentence, so it has no French.
     */
    public function testAnIconIsRefusedInATranslationAndWhenItIsNotOneWeDraw(): void
    {
        $written = $this->write([
            ['block' => 'PROBLEM', 'content' => ['title' => 'Half a day per job', 'icon' => 'clock']],
        ]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        // A name this platform cannot draw is refused rather than stored and
        // silently ignored by the page.
        $unknown = $this->write([
            ['block' => 'PROBLEM', 'content' => ['title' => 'Half a day', 'icon' => 'teapot']],
        ]);
        self::assertSame(400, $unknown->getStatusCode());

        // And a locale may not carry one at all: the field is not a sentence.
        $translated = $this->write([
            [
                'block' => 'PROBLEM',
                'content' => ['title' => 'Half a day', 'icon' => 'clock'],
                'translations' => ['fr' => ['title' => 'Une demi-journée', 'icon' => 'cross']],
            ],
        ]);
        self::assertSame(400, $translated->getStatusCode());
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

    public function testAStrangerReadsTheirOwnLanguage(): void
    {
        $this->write([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace', 'subline' => 'And print the file.'],
                // French says the headline and nothing else, which is the
                // ordinary state of a page somebody is working through.
                'translations' => ['fr' => ['headline' => 'Dessinez une terrasse']],
            ],
        ]);
        $this->publish(true);

        // A header, not `?lang=` (ADR-050): a query parameter travels in a
        // link, so a shared page could impose a language on whoever opened
        // it next. A header cannot be shared by accident.
        $french = $this->headlineOf($this->public('fr'));

        self::assertSame('Dessinez une terrasse', $french['headline'] ?? null);
        // **Field by field**: the French headline with the English subline,
        // because answering the whole English row over one missing field
        // would throw away the sentence that *was* translated.
        self::assertSame('And print the file.', $french['subline'] ?? null);

        // A language nobody wrote reads English, and so does a header the
        // platform does not know — a shop window does not refuse people
        // over an `Accept-Language`.
        self::assertSame('Draw a terrace', $this->headlineOf($this->public('de'))['headline'] ?? null);
        self::assertSame('Draw a terrace', $this->headlineOf($this->public('kl'))['headline'] ?? null);
        self::assertSame('Draw a terrace', $this->headlineOf($this->public())['headline'] ?? null);
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

    // --- The pictures -----------------------------------------------------------

    public function testAPictureIsPublicWhileThePageIsAndNotBefore(): void
    {
        $asset = $this->upload(self::png(), 'terrace.png');

        self::assertSame(201, $asset->getStatusCode(), (string) $asset->getBody());

        $picture = $this->decode($asset)['asset'] ?? null;
        self::assertIsArray($picture);
        // The sniffed type, never the claim: the upload sent no
        // Content-Type at all and the bytes are a PNG.
        self::assertSame('image/png', $picture['content_type'] ?? null);

        $assetId = $picture['id'] ?? null;
        self::assertIsString($assetId);

        $this->write([
            [
                'block' => 'HEADLINE',
                'content' => ['headline' => 'Draw a terrace', 'alt' => 'The terrace, drawn'],
                'asset_id' => $assetId,
            ],
        ]);

        // A draft's picture is nobody's: the page is not published, so the
        // bytes are not served.
        self::assertSame(404, $this->picture($assetId)->getStatusCode());

        $this->publish(true);

        $served = $this->picture($assetId);

        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        // Never improved upon by a browser, and never rendered as a
        // document: this is the platform's own origin.
        self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(self::png(), (string) $served->getBody());

        // And the page hands the reader an address rather than an id.
        $showcase = $this->decode($this->public())['showcase'] ?? null;
        self::assertIsArray($showcase);
        $blocks = $showcase['blocks'] ?? null;
        self::assertIsArray($blocks);
        $first = $blocks[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(
            '/api/v1/public/products/plan/showcase/assets/' . $assetId,
            $first['image'] ?? null,
        );

        // Taken down, and the picture goes with it.
        $this->publish(false);
        self::assertSame(404, $this->picture($assetId)->getStatusCode());
    }

    public function testAPageShowsPicturesAndNotWhateverWasSent(): void
    {
        // A zip announces itself as nothing at all; the bytes are what is
        // read. There is no such thing as an `<img src="…zip">`, so this is
        // refused before anything is written — and said with the type that
        // was found rather than with a constraint's name.
        $refused = $this->upload("PK\x03\x04" . str_repeat("\0", 64), 'archive.zip');

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('NOT_A_PICTURE', $this->errorOf($refused)['code'] ?? null);
    }

    public function testOnlyThePlatformUploadsAPicture(): void
    {
        self::assertSame(403, $this->upload(self::png(), 'terrace.png', 'sam-token')->getStatusCode());
    }

    // --- The band that shows the product working -----------------------------

    public function testADemoRowTakesEveryFieldTheConsoleOffers(): void
    {
        // Every field of `BAND_FIELDS.DEMO`, in one request. The console sent
        // exactly this and got `no such field on a DEMO`: `ratio` was offered
        // by the editor, read by the page and declared nowhere in this
        // endpoint, so saving the home page was impossible from the day the
        // band shipped. The demonstration worked because the seeder writes
        // rows straight to the table and never meets this validator.
        $written = $this->write([[
            'block' => 'DEMO',
            'content' => [
                'caption' => 'The terrace, in three dimensions',
                'embed_url' => 'https://plan.example/?x={width}&y={height}',
                'ratio' => '4:3',
            ],
        ]]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $stored = $this->demoContentOf($this->read());
        self::assertSame('4:3', $stored['ratio'] ?? null);
        self::assertSame('https://plan.example/?x={width}&y={height}', $stored['embed_url'] ?? null);
    }

    public function testAShapeNobodyDrawsIsRefused(): void
    {
        // A closed set, like `PROBLEM.icon`: the page holds a box of this
        // shape before anything loads, and a free field would eventually hold
        // `4/3`, `1.333` and "four to three".
        $refused = $this->write([[
            'block' => 'DEMO',
            'content' => ['caption' => 'A demonstration', 'embed_url' => 'https://plan.example/', 'ratio' => '21:9'],
        ]]);

        self::assertSame(400, $refused->getStatusCode());
        // The field is named with its row, which is what the console shows:
        // `blocks[0].content.ratio` tells an operator which band to look at.
        $details = $this->errorOf($refused)['details'] ?? null;
        self::assertIsArray($details);
        self::assertSame('blocks[0].content.ratio', $details['field'] ?? null);
    }

    public function testAShapeHasNoFrench(): void
    {
        // Refused in a translation, for the reason an icon is: a shape has no
        // French, and a locale that could hold one would be a second place the
        // band's shape is decided.
        $refused = $this->write([[
            'block' => 'DEMO',
            'content' => ['caption' => 'A demonstration', 'embed_url' => 'https://plan.example/'],
            'translations' => ['fr' => ['caption' => 'Une démonstration', 'ratio' => '4:3']],
        ]]);

        self::assertSame(400, $refused->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------------

    /**
     * Four bands that sell (2026-11-20): who trusts it, its numbers, what it
     * includes, and a last call to action.
     */
    public function testTheFourBandsThatSellAreWrittenAndReadBack(): void
    {
        $written = $this->write([
            self::headline(),
            ['block' => 'TRUST', 'content' => ['name' => 'Atelier Durand']],
            ['block' => 'STATS', 'content' => ['value' => '3 min', 'label' => 'to a printable plan', 'detail' => 'Measured on 200 projects.']],
            ['block' => 'FEATURE', 'content' => ['title' => 'Exports to DXF', 'body' => 'With its layers.', 'icon' => 'layers']],
            ['block' => 'CTA', 'content' => ['title' => 'Draw your first terrace', 'body' => 'Free to start.'], 'translations' => ['fr' => ['title' => 'Dessinez votre première terrasse']]],
        ]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $kinds = [];

        foreach ($this->listIn($written, 'blocks') as $block) {
            self::assertIsArray($block);
            $kinds[] = $block['block'] ?? null;
        }

        // Whatever order the rows come back in: the page orders bands by
        // `sections`, never by the order of this list.
        sort($kinds);
        self::assertSame(['CTA', 'FEATURE', 'HEADLINE', 'STATS', 'TRUST'], $kinds);

        // A figure is text with its unit in it, and what it counts is
        // required: a number with nothing beside it says nothing.
        $bare = $this->write([['block' => 'STATS', 'content' => ['value' => '3 min']]]);
        self::assertSame(400, $bare->getStatusCode());

        // The icon comes from the closed set a problem draws from, and a
        // code is refused in a translation: an icon has no French.
        $drawn = $this->write([['block' => 'FEATURE', 'content' => ['title' => 'Exports', 'icon' => 'rocket']]]);
        self::assertSame(400, $drawn->getStatusCode());

        $translated = $this->write([[
            'block' => 'FEATURE',
            'content' => ['title' => 'Exports', 'icon' => 'check'],
            'translations' => ['fr' => ['icon' => 'check']],
        ]]);
        self::assertSame(400, $translated->getStatusCode());

        // A logo's name is what a screen reader says for it, so the row
        // takes no second description.
        $described = $this->write([['block' => 'TRUST', 'content' => ['name' => 'Durand', 'alt' => 'Their logo']]]);
        self::assertSame(400, $described->getStatusCode());
    }

    /**
     * Two bands that show (2026-11-21): a tour of the product in tabs, and
     * one plot before and after.
     */
    public function testATourAndAComparisonAreWrittenAndAComparisonHasTwoSides(): void
    {
        $before = ['block' => 'COMPARE', 'position' => 10, 'content' => ['label' => 'Before', 'embed_url' => 'https://plan.example.test/?file=1&x={width}&y={height}', 'ratio' => '4:3']];
        $after = ['block' => 'COMPARE', 'position' => 20, 'content' => ['label' => 'After', 'caption' => 'With the terrace.', 'embed_url' => 'https://plan.example.test/?file=2&x={width}&y={height}'], 'translations' => ['fr' => ['label' => 'Après']]];

        $written = $this->write([
            self::headline(),
            ['block' => 'TOUR', 'content' => ['title' => 'The cadastral plot', 'body' => 'With its real boundaries.', 'alt' => 'A plot outlined on the aerial photo']],
            $before,
            $after,
        ]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        // Two sides and no third: a comparison of three is a gallery.
        $three = $this->write([self::headline(), $before, $after, ['block' => 'COMPARE', 'position' => 30, 'content' => ['label' => 'Later', 'embed_url' => 'https://plan.example.test/?file=3']]]);
        self::assertSame(400, $three->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($three)['code'] ?? null);

        // A side is an address or a picture, like a demo row: neither is
        // a side with nothing to show.
        $empty = $this->write([self::headline(), ['block' => 'COMPARE', 'content' => ['label' => 'Before']]]);
        self::assertSame(400, $empty->getStatusCode());

        // The address is held to what a demo's is: https, and nothing else.
        $unsafe = $this->write([self::headline(), ['block' => 'COMPARE', 'content' => ['label' => 'Before', 'embed_url' => 'javascript:alert(1)']]]);
        self::assertSame(400, $unsafe->getStatusCode());

        // And it is a code-like field: refused in a translation.
        $translated = $this->write([self::headline(), [
            'block' => 'COMPARE',
            'content' => ['label' => 'Before', 'embed_url' => 'https://plan.example.test/?file=1'],
            'translations' => ['fr' => ['embed_url' => 'https://plan.example.test/?file=9']],
        ]]);
        self::assertSame(400, $translated->getStatusCode());
    }

    public function testAPageHasOneCallToAction(): void
    {
        $twice = $this->write([
            self::headline(),
            ['block' => 'CTA', 'position' => 10, 'content' => ['title' => 'Start now']],
            ['block' => 'CTA', 'position' => 20, 'content' => ['title' => 'Or start later']],
        ]);

        // In words, before the index says it with a constraint's name.
        self::assertSame(400, $twice->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($twice)['code'] ?? null);
    }

    /**
     * How a band is laid out is a code beside its heading (2026-11-20): one
     * of the layouts that band has, in English only.
     */
    public function testABandIsLaidOutOneOfTheWaysItHas(): void
    {
        /** @param array<string, mixed> $bands */
        $put = fn (array $bands): ResponseInterface => $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => [self::headline()], 'bands' => $bands]),
        );

        $written = $put([
            'HEADLINE' => ['content' => ['layout' => 'centered']],
            'STEPS' => ['content' => ['title' => 'Three moves', 'layout' => 'timeline']],
        ]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $stored = $this->decode($written)['bands'] ?? null;
        self::assertIsArray($stored);
        // A layout with no title beside it is still a band that said
        // something, so it is stored and answered.
        self::assertSame('centered', $this->sentenceAt($stored, ['HEADLINE', 'content', 'layout']));
        self::assertSame('timeline', $this->sentenceAt($stored, ['STEPS', 'content', 'layout']));

        // A stranger is told, in whatever language they read: a layout has
        // no translation to fall back from.
        $this->publish(true);
        $public = $this->request('GET', '/api/v1/public/products/plan/showcase', ['Accept-Language' => 'fr']);
        $showcase = $this->decode($public)['showcase'] ?? null;
        self::assertIsArray($showcase);
        self::assertSame('centered', $this->sentenceAt($showcase, ['bands', 'HEADLINE', 'layout']));

        foreach ([
            // A layout this band does not have.
            ['HEADLINE' => ['content' => ['layout' => 'timeline']]],
            // A band that is drawn one way.
            ['QUESTION' => ['content' => ['layout' => 'grid']]],
            // A layout in a translation.
            ['STEPS' => ['content' => ['title' => 'Three moves'], 'translations' => ['fr' => ['layout' => 'timeline']]]],
        ] as $refused) {
            $answer = $put($refused);

            self::assertSame(400, $answer->getStatusCode(), json_encode($refused) ?: '');
        }

        // None of the refusals moved it.
        $after = $this->decode($this->read())['bands'] ?? null;
        self::assertIsArray($after);
        self::assertSame('centered', $this->sentenceAt($after, ['HEADLINE', 'content', 'layout']));
    }

    /**
     * Whether the page draws its menu is a code on the headline's heading
     * (2026-11-22), and on no other.
     */
    public function testAPageMayHideItsMenu(): void
    {
        /** @param array<string, mixed> $bands */
        $put = fn (array $bands): ResponseInterface => $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => [self::headline()], 'bands' => $bands]),
        );

        $written = $put(['HEADLINE' => ['content' => ['menu' => 'hidden']]]);

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $stored = $this->decode($written)['bands'] ?? null;
        self::assertIsArray($stored);
        self::assertSame('hidden', $this->sentenceAt($stored, ['HEADLINE', 'content', 'menu']));

        $this->publish(true);
        $public = $this->request('GET', '/api/v1/public/products/plan/showcase', ['Accept-Language' => 'fr']);
        $showcase = $this->decode($public)['showcase'] ?? null;
        self::assertIsArray($showcase);
        self::assertSame('hidden', $this->sentenceAt($showcase, ['bands', 'HEADLINE', 'menu']));

        foreach ([
            // A value the page cannot draw.
            ['HEADLINE' => ['content' => ['menu' => 'tabs']]],
            // A section that does not carry it.
            ['STEPS' => ['content' => ['menu' => 'hidden']]],
            // In a translation.
            ['HEADLINE' => ['content' => ['menu' => 'hidden'], 'translations' => ['fr' => ['menu' => 'hidden']]]],
        ] as $refused) {
            $answer = $put($refused);

            self::assertSame(400, $answer->getStatusCode(), json_encode($refused) ?: '');
        }

        // A comparison may be laid out side by side, the layout it gained
        // the same day.
        $beside = $put(['COMPARE' => ['content' => ['layout' => 'beside']]]);
        self::assertSame(200, $beside->getStatusCode(), (string) $beside->getBody());
    }

    /**
     * The four bands of 2026-11-23 take their codes checked for what they
     * are, and an announcement past its last day is not read out.
     */
    public function testTheNewBandsTakeOnlyWhatTheyCanDraw(): void
    {
        /** @param list<array<string, mixed>> $blocks */
        $put = fn (array $blocks, array $bands = []): ResponseInterface => $this->request(
            'PUT',
            '/api/v1/staff/products/' . $this->plan . '/showcase',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['blocks' => [self::headline(), ...$blocks], 'bands' => (object) $bands]),
        );

        $written = $put(
            [
                ['block' => 'ANNOUNCE', 'position' => 10, 'content' => ['text' => 'Old news', 'until' => '2000-01-31']],
                ['block' => 'VIDEO', 'position' => 10, 'content' => ['caption' => 'Two minutes', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
                ['block' => 'VERSUS', 'position' => 10, 'content' => ['criterion' => 'Your plot in 3D', 'ours' => 'yes', 'first' => 'no']],
                ['block' => 'HOTSPOT', 'position' => 10, 'content' => ['title' => 'The terrace', 'x' => '20', 'y' => '35.5']],
            ],
            ['VERSUS' => ['content' => ['columns' => 'An architect; A spreadsheet'], 'translations' => ['fr' => ['columns' => 'Un architecte; Un tableur']]]],
        );

        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        $this->publish(true);
        $public = $this->decode($this->request('GET', '/api/v1/public/products/plan/showcase', ['Accept-Language' => 'fr']))['showcase'] ?? null;
        self::assertIsArray($public);
        $blocks = $public['blocks'] ?? null;
        self::assertIsArray($blocks);
        $kinds = array_map(static fn (mixed $block): mixed => is_array($block) ? ($block['block'] ?? null) : null, $blocks);

        // Past its last day, the news is not read out; the rest is.
        self::assertNotContains('ANNOUNCE', $kinds);
        self::assertContains('VIDEO', $kinds);
        self::assertContains('HOTSPOT', $kinds);
        self::assertSame('Un architecte; Un tableur', $this->sentenceAt($public, ['bands', 'VERSUS', 'columns']));

        // News still news today is read out.
        $put([['block' => 'ANNOUNCE', 'position' => 10, 'content' => ['text' => 'New', 'until' => '2999-12-31', 'link_url' => '#prices']]]);
        $again = $this->decode($this->request('GET', '/api/v1/public/products/plan/showcase'))['showcase'] ?? null;
        self::assertIsArray($again);
        $news = array_values(array_filter(
            is_array($again['blocks'] ?? null) ? $again['blocks'] : [],
            static fn (mixed $block): bool => is_array($block) && ($block['block'] ?? null) === 'ANNOUNCE',
        ));
        self::assertCount(1, $news);
        self::assertIsArray($news[0]);
        self::assertSame('New', $this->sentenceAt($news[0], ['content', 'text']));

        foreach ([
            // A film from anywhere else, or none at all.
            ['block' => 'VIDEO', 'position' => 10, 'content' => ['caption' => 'x', 'video_url' => 'https://elsewhere.test/film.mp4']],
            ['block' => 'VIDEO', 'position' => 10, 'content' => ['caption' => 'x']],
            // A day that is not one.
            ['block' => 'ANNOUNCE', 'position' => 10, 'content' => ['text' => 'x', 'until' => '2026-02-30']],
            // A link that is neither https nor a place on the page.
            ['block' => 'ANNOUNCE', 'position' => 10, 'content' => ['text' => 'x', 'link_url' => 'javascript:alert(1)']],
            // A verdict that is not one.
            ['block' => 'VERSUS', 'position' => 10, 'content' => ['criterion' => 'x', 'ours' => 'maybe']],
            // A point off the picture, or nowhere.
            ['block' => 'HOTSPOT', 'position' => 10, 'content' => ['title' => 'x', 'x' => '120', 'y' => '10']],
            ['block' => 'HOTSPOT', 'position' => 10, 'content' => ['title' => 'x', 'x' => '10']],
            // A code in a translation.
            ['block' => 'HOTSPOT', 'position' => 10, 'content' => ['title' => 'x', 'x' => '10', 'y' => '10'], 'translations' => ['fr' => ['x' => '20']]],
        ] as $refused) {
            self::assertSame(400, $put([$refused])->getStatusCode(), json_encode($refused) ?: '');
        }

        // Columns belong to the comparison alone.
        self::assertSame(400, $put([], ['STEPS' => ['content' => ['columns' => 'A; B']]])->getStatusCode());
    }

    /**
     * A visitor writes through the page's form (2026-11-23): only where the
     * published page offers one, with the consent kept as it was read, a
     * trap that keeps nothing, a limit per address, and support reading and
     * answering.
     */
    public function testAVisitorWritesThroughThePageAndSupportAnswers(): void
    {
        $send = fn (array $body, string $language = 'en'): ResponseInterface => $this->contact($body, $language);
        $message = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Does it draw a pergola?', 'consent' => true];

        // No form on the page, or no page at all: nobody's mailbox.
        self::assertSame(404, $send($message)->getStatusCode());

        $written = $this->write([
            self::headline(),
            [
                'block' => 'CONTACT',
                'position' => 10,
                'content' => ['consent' => 'I agree to be contacted.'],
                'translations' => ['fr' => ['consent' => 'J’accepte d’être recontacté.']],
            ],
        ]);
        self::assertSame(200, $written->getStatusCode(), (string) $written->getBody());

        // Written but not published is still no form.
        self::assertSame(404, $send($message)->getStatusCode());
        $this->publish(true);

        self::assertSame(202, $send($message, 'fr')->getStatusCode());
        // The trap: the same answer, and nothing kept.
        self::assertSame(202, $send([...$message, 'website' => 'https://spam.test'])->getStatusCode());
        // No consent, no address that answers.
        self::assertSame(400, $send([...$message, 'consent' => false])->getStatusCode());
        self::assertSame(400, $send([...$message, 'email' => 'not an address'])->getStatusCode());

        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM contact_requests'));
        // Kept as it was read, in the language it was read in.
        self::assertSame('J’accepte d’être recontacté.', $this->connection->fetchOne('SELECT consent FROM contact_requests'));

        // Five an hour from one address, the refused ones included.
        self::assertSame(202, $send($message)->getStatusCode());
        self::assertSame(429, $send($message)->getStatusCode());

        // Support reads them, those to answer first, and answers one.
        $list = $this->request('GET', '/api/v1/staff/contact-requests?product=plan', ['Authorization' => 'Bearer sam-token']);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $page = $this->decode($list);
        self::assertSame(2, $page['total'] ?? null);
        self::assertSame(2, $page['open'] ?? null);
        $requests = $page['requests'] ?? null;
        self::assertIsArray($requests);
        self::assertIsArray($requests[0] ?? null);
        $id = $requests[0]['id'] ?? null;
        self::assertIsString($id);

        $handled = $this->request('POST', '/api/v1/staff/contact-requests/' . $id . '/handled', ['Authorization' => 'Bearer sam-token']);
        self::assertSame(200, $handled->getStatusCode(), (string) $handled->getBody());

        $after = $this->decode($this->request('GET', '/api/v1/staff/contact-requests', ['Authorization' => 'Bearer sam-token']));
        self::assertSame(1, $after['open'] ?? null);

        self::assertSame(404, $this->request('POST', '/api/v1/staff/contact-requests/not-an-id/handled', ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        // A stranger reads nothing.
        self::assertSame(401, $this->request('GET', '/api/v1/staff/contact-requests')->getStatusCode());
    }

    /**
     * A visitor's message, sent the way the page's form sends it.
     *
     * @param array<array-key, mixed> $body
     */
    private function contact(array $body, string $language): ResponseInterface
    {
        $fields = [];

        foreach ($body as $field => $value) {
            $fields[(string) $field] = $value;
        }

        return $this->request(
            'POST',
            '/api/v1/public/products/plan/contact',
            ['Accept-Language' => $language],
            $this->json($fields),
        );
    }

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
     * One sentence out of a nest of decoded JSON, narrowed at every step.
     *
     * `$body['bands']['STEPS']['content']['title']` is four offsets on
     * `mixed`, which PHPStan refuses and is right to: any one of them could
     * be a string and the failure would read as "title is null" rather than
     * "bands is not an object". This says which level went wrong.
     *
     * @param array<array-key, mixed> $from
     * @param list<string>            $path
     */
    private function sentenceAt(array $from, array $path): ?string
    {
        $at = $from;

        foreach ($path as $step) {
            self::assertIsArray($at, 'expected an object at ' . $step);

            if (!array_key_exists($step, $at)) {
                return null;
            }

            $at = $at[$step];
        }

        return is_string($at) ? $at : null;
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

    /**
     * No token at all: this is the page a stranger reads.
     *
     * `$language` goes in `Accept-Language`, because a stranger has no
     * profile to read a language from and `?lang=` was removed by ADR-050 —
     * a query parameter travels in a shared link and would impose a
     * language on whoever opened it next.
     */
    private function public(?string $language = null): ResponseInterface
    {
        return $this->request(
            'GET',
            '/api/v1/public/products/plan/showcase',
            $language === null ? [] : ['Accept-Language' => $language],
        );
    }

    /**
     * The resolved content of the published page's headline band.
     *
     * @return array<string, mixed>
     */
    /**
     * The one DEMO row's content, read back.
     *
     * @return array<string, mixed>
     */
    private function demoContentOf(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        // The staff read answers the story at the top level; `showcase` is
        // the public page's shape, which is a different reader's question.
        $blocks = $this->decode($response)['blocks'] ?? null;
        self::assertIsArray($blocks);

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['block'] ?? null) === 'DEMO') {
                $content = $block['content'] ?? null;
                self::assertIsArray($content);

                /** @var array<string, mixed> $content */
                return $content;
            }
        }

        self::fail('The story carries no DEMO row.');
    }

    /**
     * @return array<string, mixed>
     */
    private function headlineOf(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        $showcase = $this->decode($response)['showcase'] ?? null;
        self::assertIsArray($showcase);

        $blocks = $showcase['blocks'] ?? null;
        self::assertIsArray($blocks);

        $first = $blocks[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('HEADLINE', $first['block'] ?? null);

        $content = $first['content'] ?? null;
        self::assertIsArray($content);

        /** @var array<string, mixed> $content */
        return $content;
    }

    /** Nor for its pictures. */
    private function picture(string $assetId): ResponseInterface
    {
        return $this->request('GET', '/api/v1/public/products/plan/showcase/assets/' . $assetId);
    }

    private function upload(string $bytes, string $filename, string $token = 'ola-token'): ResponseInterface
    {
        // The bytes **are** the body, and no Content-Type is sent: whatever
        // a request claims, the stored type is the one sniffed.
        return $this->request(
            'POST',
            '/api/v1/staff/products/' . $this->plan . '/assets',
            ['Authorization' => 'Bearer ' . $token, 'X-Filename' => $filename],
            $bytes,
        );
    }

    /** The smallest PNG that is really a PNG, so the sniffer agrees. */
    private static function png(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        self::assertIsString($bytes);

        return $bytes;
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
