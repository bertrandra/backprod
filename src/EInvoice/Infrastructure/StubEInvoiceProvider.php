<?php

declare(strict_types=1);

namespace App\EInvoice\Infrastructure;

use App\Billing\Domain\Invoice;
use App\EInvoice\Domain\EInvoiceProvider;
use App\EInvoice\Domain\PlatformEvent;
use App\EInvoice\Domain\SubmittedDocument;
use App\EInvoice\Domain\TransmissionStatus;
use App\Shared\Exceptions\UnauthenticatedException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * An approved platform that is honestly not one.
 *
 * It exists so the whole transmission path is exercised end to end without a
 * real PDP, and so the first real adapter has a worked example. It is named
 * "stub" in configuration and in `einvoice_transmissions.provider`, so a row
 * it produced can never be mistaken for a real transmission — which matters
 * more here than for payments, because a fake transmission record could
 * otherwise be mistaken for evidence of compliance.
 */
final class StubEInvoiceProvider implements EInvoiceProvider
{
    public const NAME = 'stub';
    public const SIGNATURE_HEADER = 'X-Einvoice-Signature';

    public function __construct(private readonly string $signingSecret)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function submit(Invoice $invoice): SubmittedDocument
    {
        // A real adapter maps the invoice onto its platform's format here —
        // §25.1 lists what must travel, and all of it is already on the
        // invoice's own snapshot.
        return new SubmittedDocument(
            'stub_doc_' . substr(hash('sha256', $invoice->id), 0, 24),
            TransmissionStatus::SUBMITTED,
        );
    }

    public function verify(string $rawBody, array $headers): void
    {
        $supplied = self::header($headers, self::SIGNATURE_HEADER);

        if ($supplied === null) {
            throw new UnauthenticatedException();
        }

        if (!hash_equals(hash_hmac('sha256', $rawBody, $this->signingSecret), $supplied)) {
            throw new UnauthenticatedException();
        }
    }

    public function parse(string $rawBody): ?PlatformEvent
    {
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return null;
        }

        $id = self::text($decoded, 'id');
        $type = self::mapType(self::text($decoded, 'type'));
        $document = self::text($decoded, 'document_id');

        if ($id === null || $type === null || $document === null) {
            return null;
        }

        return new PlatformEvent(
            $id,
            $type,
            $document,
            self::moment(self::text($decoded, 'occurred_at')),
            self::text($decoded, 'rejection_code'),
            self::text($decoded, 'rejection_reason'),
            array_filter([
                'id' => $id,
                'type' => $type,
                'document_id' => $document,
                'rejection_code' => self::text($decoded, 'rejection_code'),
            ], static fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * Test and developer tooling only — the same computation as verification,
     * which is exactly why a real adapter has no equivalent: there, only the
     * platform can produce one.
     */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->signingSecret);
    }

    private static function mapType(?string $type): ?string
    {
        return match ($type) {
            'document.submitted' => PlatformEvent::SUBMITTED,
            'document.accepted' => PlatformEvent::ACCEPTED,
            'document.rejected' => PlatformEvent::REJECTED,
            default => null,
        };
    }

    /**
     * @param array<array-key, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function text(array $decoded, string $field): ?string
    {
        $value = $decoded[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function moment(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value, new DateTimeZone('UTC'));

        return $moment === false ? null : $moment;
    }
}
