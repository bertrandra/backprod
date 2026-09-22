<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Notification\Domain\MailTemplates;
use App\Notification\Domain\Notification;
use App\Notification\Service\MailWording;
use App\Shared\Validation\Locale;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A mail is read in the reader's language (ADR-050, 2026-09-22).
 *
 * The first claim is a gate as much as a test: every language the platform
 * speaks has every mail's words, with the same placeholders — a language
 * missing one would fall back to English silently, which is the bug this
 * fixes. The second is the resolution order: the reader's language first,
 * and the administrator's English never reaching a French inbox.
 */
#[CoversClass(MailWording::class)]
final class MailWordingTest extends TestCase
{
    public function testEveryLanguageHasEveryMailWithTheSamePlaceholders(): void
    {
        foreach (Locale::ALL as $locale) {
            if ($locale === Locale::DEFAULT) {
                continue;
            }

            // Through the resolver, so the claim is about what a reader
            // gets rather than about the shape of a constant.
            foreach (MailWording::DEFAULTS as $type => $english) {
                $words = MailWording::defaultFor($type, $locale);
                self::assertNotNull($words);
                self::assertNotSame($english['subject'], $words['subject'], sprintf('%s has no words of its own for %s: a person reading in it would receive English.', $locale, $type));

                // The link is what the person acts on; a translation that
                // lost it would be a mail with nothing to click.
                foreach ($english['placeholders'] as $placeholder) {
                    if (str_contains($english['body'], '{' . $placeholder . '}')) {
                        self::assertStringContainsString('{' . $placeholder . '}', $words['body'], sprintf('%s/%s lost {%s}.', $locale, $type, $placeholder));
                    }
                }
            }
        }
    }

    public function testTheReadersLanguageWinsAndEnglishsOverrideStaysEnglishs(): void
    {
        $wording = new MailWording(new class () implements MailTemplates {
            public function overrides(): array
            {
                return ['en' => ['account.password_reset' => ['subject' => 'Custom English', 'body' => 'Go: {link}']]];
            }

            public function save(array $overrides): void
            {
            }
        });

        $reset = $this->notification('account.password_reset', ['link' => 'https://x.test/r']);

        // English: the administrator's words.
        self::assertSame(['Custom English', 'Go: https://x.test/r'], $wording->for($reset, 'en'));
        // French: the platform's French, not the administrator's English.
        [$subject, $body] = $wording->for($reset, 'fr') ?? ['', ''];
        self::assertSame(MailWording::WORDS['fr']['account.password_reset']['subject'], $subject);
        self::assertStringContainsString('https://x.test/r', $body);
        self::assertStringNotContainsString('{link}', $body);
        // A language the platform does not speak reads as English.
        self::assertSame(['Custom English', 'Go: https://x.test/r'], $wording->for($reset, 'xx'));
        // A type with no words of its own has none in any language.
        self::assertNull($wording->for($this->notification('payment.failed', []), 'fr'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function notification(string $type, array $payload): Notification
    {
        $now = new DateTimeImmutable();

        return new Notification('n-1', 't-1', 'p-1', 'u-1', $type, 'ACCOUNT', $payload, null, false, $now, null);
    }
}
