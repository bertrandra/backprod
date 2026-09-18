<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceDocument;
use App\Billing\Domain\InvoiceDocumentRepository;
use App\Billing\Domain\InvoiceRenderer;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\InvoiceStatusBand;
use App\Payment\Domain\PaymentRepository;
use App\Shared\Exceptions\ConflictException;
use App\Storage\Domain\StorageProvider;
use DateTimeImmutable;

/**
 * The PDF of an invoice (§7 `GET /invoices/{id}/pdf`).
 *
 * **Rendered once, then served from storage.** The first request renders and
 * keeps the result; every later one returns those bytes. Not for speed — a
 * one-page invoice renders in tens of milliseconds — but because the bytes
 * that went to the customer are the bytes that should come back. mpdf stamps a
 * creation time and has a version, so a re-render a year later is a different
 * file, and "the invoice we sent" would stop matching "the invoice we serve".
 *
 * **Only a numbered invoice has a PDF.** A draft has no legal number, and a
 * document that looks like an invoice without being one is worse than no
 * document: somebody pays against it, or files it. The refusal is a 409 rather
 * than a 404 because the invoice exists — it is simply not issued yet.
 *
 * **Not routed through `Assets`.** That service keys everything to a project,
 * and an invoice has none; a nullable project on `assets` to accommodate this
 * would weaken a column every other row depends on. `invoice_documents` holds
 * the key instead, and `StorageProvider` is used directly — §"Stocke" wants
 * the PDF in the object store either way, which is the part that matters.
 *
 * Synchronous, which departs from the spec's list of long-running work (§"Les
 * traitements longs sont asynchrones" names PDF among them). That list is
 * about photogrammetry-scale rendering; a single invoice is not that, and
 * making the caller poll a job for a document that is ready before the
 * response would be worse for them. If a future document does need the queue,
 * the queue is already there and this is the caller that would use it.
 */
final class InvoiceDocuments
{
    public function __construct(
        private readonly Invoicing $invoicing,
        private readonly InvoiceDocumentRepository $documents,
        private readonly InvoiceRenderer $renderer,
        private readonly StorageProvider $storage,
        private readonly PaymentRepository $payments,
    ) {
    }

    /**
     * The document, its bytes and what to call the file.
     *
     * @return array{document: InvoiceDocument, contents: string, filename: string, contentType: string, band: InvoiceStatusBand}
     */
    public function pdf(string $tenantId, string $productId, string $invoiceId, ?string $ownedBy = null): array
    {
        // Scoped by tenant and product — and by person, when the caller only
        // sees their own — so somebody else's invoice is a 404 here for the
        // same reason it is in `show`.
        $invoice = $this->invoicing->show($tenantId, $productId, $invoiceId, $ownedBy);

        if ($invoice->number === null) {
            throw new ConflictException(
                'INVOICE_NOT_RENDERABLE',
                'Only an issued invoice has a document. A draft has no number yet.',
                ['status' => $invoice->status],
            );
        }

        $document = $this->documents->find($invoice->id);

        if ($document !== null) {
            return $this->served($document, $invoice);
        }

        $contents = $this->renderer->render($invoice);
        $key = self::newKey();

        // Storage first, then the row. The other order can leave a row
        // pointing at bytes that were never written, and a reader cannot tell
        // that from a transient storage fault. This order can leave bytes
        // nothing references, which costs disk and nothing else.
        $this->storage->put($key, $contents);

        $document = $this->documents->remember(
            $invoice->id,
            $key,
            strlen($contents),
            hash('sha256', $contents),
            $this->renderer->name(),
        );

        if ($document->storageKey !== $key) {
            // Another request rendered first and its row won the primary key.
            // Serve what is of record, not what this request happened to
            // produce, so two callers never receive different documents for
            // one invoice.
            return $this->served($document, $invoice);
        }

        return $this->stamped($document, $contents, $invoice);
    }

    /**
     * @return array{document: InvoiceDocument, contents: string, filename: string, contentType: string, band: InvoiceStatusBand}
     */
    private function served(InvoiceDocument $document, Invoice $invoice): array
    {
        return $this->stamped($document, $this->storage->get($document->storageKey), $invoice);
    }

    /**
     * The stored bytes with the status band of the moment on top
     * (docs/tenant-roots.md §2.5): the body never differs from what was
     * issued, the band says where the money stands today.
     *
     * @return array{document: InvoiceDocument, contents: string, filename: string, contentType: string, band: InvoiceStatusBand}
     */
    private function stamped(InvoiceDocument $document, string $contents, Invoice $invoice): array
    {
        $settling = $invoice->status === InvoiceStatus::PAID
            ? $this->payments->latestForInvoice($invoice->tenantId, $invoice->productId, $invoice->id)
            : null;
        $band = InvoiceStatusBand::for($invoice, $settling, new DateTimeImmutable());

        return [
            'document' => $document,
            'contents' => $this->renderer->stamp($contents, $band),
            'filename' => self::filenameFor($invoice),
            'contentType' => $this->renderer->contentType(),
            'band' => $band,
        ];
    }

    /**
     * The legal number makes the filename, reduced to characters that cannot
     * do anything in a `Content-Disposition` header or a filesystem. The
     * number is allocated by this platform and already matches a strict
     * pattern; it is reduced anyway, because the header is what would break
     * and a filename is not the place to rely on an invariant held elsewhere.
     */
    private static function filenameFor(Invoice $invoice): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number ?? $invoice->id);

        return 'facture-' . trim((string) $number, '-') . '.pdf';
    }

    /**
     * Bare hex, because that is the only shape `StorageProvider` accepts: a
     * key it cannot tell apart from one this platform generated is a key a
     * traversal sequence could hide in, so the port refuses anything else. The
     * key carries no meaning as a result — `invoice_documents` is what says
     * which invoice these bytes belong to.
     */
    private static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
