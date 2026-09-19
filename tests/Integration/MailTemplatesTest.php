<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Notification\Domain\Notifier;
use App\Notification\Service\MailTester;
use App\Notification\Service\MailWording;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The words the platform's mails say are the platform administrator's to
 * change, and the mail host can be tried from the same screen (2026-09-19).
 */
#[CoversNothing]
final class MailTemplatesTest extends DatabaseApiTestCase
{
    private const ADMIN = ['Authorization' => 'Bearer ola-token'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['sub-ola', 'ola@platform.test', 'PLATFORM_ADMIN'], ['sub-sam', 'sam@platform.test', 'SUPPORT_ADMIN']] as [$subject, $email, $role]) {
            $user = $this->id('INSERT INTO users (auth_subject, email) VALUES (:s, :e) RETURNING id', ['s' => $subject, 'e' => $email]);
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
        ]);
    }

    public function testTheDefaultsAreShownWithTheirPlaceholdersAndCanBeChangedAndReset(): void
    {
        $shown = $this->decode($this->request('GET', '/api/v1/staff/mail/templates', self::ADMIN));
        $templates = $this->listIn($shown, 'templates');
        self::assertSame(array_keys(MailWording::DEFAULTS), array_column($templates, 'type'));
        self::assertSame(['link', 'email'], $templates[0]['placeholders'] ?? null);
        self::assertFalse($templates[0]['customised'] ?? null);
        // No DSN in the test environment: recorded, never sent.
        self::assertFalse($shown['live'] ?? null);

        // The administrator's own words, in their own language.
        $set = $this->request('PUT', '/api/v1/staff/mail/templates', self::ADMIN, $this->json([
            'templates' => ['account.password_reset' => ['subject' => 'Nouveau mot de passe', 'body' => "Cliquez ici : {link}\nCe lien expire dans trente minutes."]],
        ]));
        self::assertSame(200, $set->getStatusCode());
        $changed = $this->listIn($this->decode($set), 'templates');
        self::assertSame('Nouveau mot de passe', $changed[0]['subject'] ?? null);
        self::assertTrue($changed[0]['customised'] ?? null);
        self::assertFalse($changed[1]['customised'] ?? null);

        // And they are what a real notice renders with.
        $this->request('POST', '/api/v1/auth/password/forgot', [], $this->json(['email' => 'ola@platform.test']));
        // (ola has no membership, so no notice is raised — the mechanism is
        // proven at the unit level; here the stored words are what matter.)
        $stored = $this->connection->fetchOne("SELECT value->'en'->'account.password_reset'->>'subject' FROM platform_settings WHERE key = 'mail_templates'");
        self::assertSame('Nouveau mot de passe', $stored);

        // French words of their own (ADR-050), beside English's; a language
        // with none shows English's — what a person in it would receive.
        $french = $this->request('PUT', '/api/v1/staff/mail/templates', self::ADMIN, $this->json([
            'locale' => 'fr',
            'templates' => ['account.invitation' => ['subject' => 'Bienvenue', 'body' => 'Votre lien : {link}']],
        ]));
        self::assertSame(200, $french->getStatusCode());
        self::assertSame('fr', $this->decode($french)['locale'] ?? null);
        $fr = $this->listIn($this->decode($french), 'templates');
        self::assertSame('Bienvenue', $fr[1]['subject'] ?? null);
        self::assertTrue($fr[1]['customised'] ?? null);
        self::assertSame('Nouveau mot de passe', $fr[0]['subject'] ?? null);
        self::assertFalse($fr[0]['customised'] ?? null);
        $italian = $this->listIn($this->decode($this->request('GET', '/api/v1/staff/mail/templates?locale=it', self::ADMIN)), 'templates');
        self::assertSame('Nouveau mot de passe', $italian[0]['subject'] ?? null);
        self::assertSame(['en', 'fr', 'es', 'de', 'it'], $this->decode($this->request('GET', '/api/v1/staff/mail/templates?locale=it', self::ADMIN))['locales'] ?? null);

        // Left out of the next save, it goes back to the default.
        // (English's save leaves French as it was.)
        $reset = $this->request('PUT', '/api/v1/staff/mail/templates', self::ADMIN, $this->json(['templates' => new \stdClass()]));
        self::assertSame(200, $reset->getStatusCode());
        $back = $this->listIn($this->decode($reset), 'templates');
        self::assertFalse($back[0]['customised'] ?? null);
        self::assertSame(MailWording::DEFAULTS['account.password_reset']['subject'], $back[0]['subject'] ?? null);
        $frAfter = $this->listIn($this->decode($this->request('GET', '/api/v1/staff/mail/templates?locale=fr', self::ADMIN)), 'templates');
        self::assertSame('Bienvenue', $frAfter[1]['subject'] ?? null);
    }

    public function testATestIsRefusedPlainlyWhereNoMailCanLeave(): void
    {
        $refused = $this->request('POST', '/api/v1/staff/mail/test', self::ADMIN, $this->json(['type' => 'account.password_reset']));

        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('MAIL_NOT_CONFIGURED', $this->errorOf($refused)['code'] ?? null);

        $unknown = $this->request('POST', '/api/v1/staff/mail/test', self::ADMIN, $this->json(['type' => 'payment.failed']));
        self::assertSame(400, $unknown->getStatusCode());
    }

    public function testATestGoesToTheCallerRenderedFromTheTemplateWhenTheHostIsLive(): void
    {
        $sent = [];
        $host = new class ($sent) implements Notifier {
            /** @param list<array{string, string, string}> $sent */
            public function __construct(public array &$sent)
            {
            }

            public function channel(): string
            {
                return 'EMAIL';
            }

            public function isLive(): bool
            {
                return true;
            }

            public function send(string $address, string $subject, string $body, array $payload): string
            {
                $this->sent[] = [$address, $subject, $body];

                return 'msg-1';
            }
        };

        $this->override([
            MailTester::class => new MailTester($host, $this->wording(), 'https://example.test'),
        ]);

        $response = $this->request('POST', '/api/v1/staff/mail/test', self::ADMIN, $this->json(['type' => 'account.invitation']));

        self::assertSame(200, $response->getStatusCode());
        // To the caller's own address — as the identity chain knows it, which
        // the fake provider derives from the subject.
        $to = $this->decode($response)['to'] ?? null;
        self::assertIsString($to);
        self::assertStringContainsString('ola', $to);
        self::assertSame('msg-1', $this->decode($response)['provider_message_id'] ?? null);
        self::assertCount(1, $sent);
        [$sentTo, $subject, $body] = $sent[0];
        self::assertSame($to, $sentTo);
        self::assertStringStartsWith('[Test] ', $subject);
        // The sample link, filled in — no `{link}` left to read.
        self::assertStringContainsString('https://example.test/sign-in?reset=SAMPLE-TOKEN', $body);
        self::assertStringNotContainsString('{link}', $body);
    }

    public function testOnlyThePlatformAdministratorEditsOrTests(): void
    {
        $support = ['Authorization' => 'Bearer sam-token'];

        self::assertSame(403, $this->request('GET', '/api/v1/staff/mail/templates', $support)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v1/staff/mail/templates', $support, $this->json(['templates' => new \stdClass()]))->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v1/staff/mail/test', $support, $this->json(['type' => 'account.password_reset']))->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/v1/staff/mail/templates')->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function listIn(array $body, string $key): array
    {
        $list = $body[$key] ?? null;
        self::assertIsArray($list);
        $rows = [];
        foreach ($list as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $k => $v) {
                $typed[(string) $k] = $v;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private function wording(): MailWording
    {
        $wording = $this->container()->get(MailWording::class);
        self::assertInstanceOf(MailWording::class, $wording);

        return $wording;
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
