<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Domain\AmountText;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceRenderer;
use App\Billing\Domain\Money;
use App\Billing\Domain\TaxRecord;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * An invoice as a PDF, through mpdf.
 *
 * **Everything printed is escaped.** A legal name, a street and a line
 * description are all customer-supplied, and mpdf parses what it is handed as
 * HTML — so an unescaped `<` is a rendering bug at best and, since mpdf can be
 * asked to fetch things, worse than that at worst. `self::text()` is the only way
 * a value reaches the markup, and it is applied at the point of use rather than
 * on a sanitised copy, because a copy is something a later edit can forget to
 * use.
 *
 * **Nothing is read but the invoice.** No catalogue, no tenant, no clock: the
 * invoice already carries its parties, its lines and its per-rate tax as
 * snapshots, which is what makes the same invoice render the same document
 * however much later it is asked for.
 *
 * **The bytes are not reproducible, which is why they are stored.** mpdf stamps
 * the creation time into the PDF and offers no way to fix it, so rendering the
 * same invoice twice gives two different files. That is not a problem to solve
 * here: `invoice_documents` keeps the first render and every later request
 * returns it, so the document of record is a stored artefact rather than
 * something re-derived and hoped to match.
 *
 * mpdf writes temporary font caches, so it is given an explicit directory
 * rather than left to find one. Two renders running at once each get their own.
 */
final class MpdfInvoiceRenderer implements InvoiceRenderer
{
    private const CREATOR = 'backprod';

    public function __construct(private readonly string $temporaryDirectory)
    {
    }

    public function name(): string
    {
        return 'mpdf/' . Mpdf::VERSION;
    }

    public function contentType(): string
    {
        return 'application/pdf';
    }

    public function render(Invoice $invoice): string
    {
        $directory = rtrim($this->temporaryDirectory, '/') . '/mpdf';

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create a temporary directory for rendering.');
        }

        try {
            $mpdf = new Mpdf([
                'tempDir' => $directory,
                'format' => 'A4',
                'margin_top' => 16,
                'margin_bottom' => 18,
                'margin_left' => 16,
                'margin_right' => 16,
            ]);

            // The document's own metadata, so a file saved out of a browser is
            // identifiable without opening it.
            $mpdf->SetTitle(self::documentTitle($invoice));
            $mpdf->SetAuthor(self::partyField($invoice->supplier, 'legal_name') ?? '');
            $mpdf->SetCreator(self::CREATOR);

            $mpdf->WriteHTML(self::stylesheet(), HTMLParserMode::HEADER_CSS);
            $mpdf->WriteHTML(self::body($invoice), HTMLParserMode::HTML_BODY);

            // mpdf's Output is documented to return a string for
            // STRING_RETURN, but its signature says nothing, so the promise is
            // checked rather than trusted.
            $bytes = $mpdf->Output('', Destination::STRING_RETURN);
        } catch (MpdfException $e) {
            // The message can name a font path or a temporary directory, so it
            // is not propagated to the caller (§31 keeps internals out of
            // responses). The exception chain carries it to the log.
            throw new RuntimeException('Failed to render the invoice document.', 0, $e);
        }

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Failed to render the invoice document.');
        }

        return $bytes;
    }

    private static function documentTitle(Invoice $invoice): string
    {
        return 'Facture ' . ($invoice->number ?? $invoice->id);
    }

    private static function stylesheet(): string
    {
        // Points, not pixels: this is paper. No web fonts and no external
        // anything — mpdf would try to fetch it, and a renderer that makes
        // network calls turns a missing host into a failed invoice.
        return <<<'CSS'
            body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #111; }
            h1 { font-size: 16pt; margin: 0 0 2pt 0; }
            .muted { color: #555; }
            .parties { width: 100%; margin-top: 14pt; }
            .parties td { vertical-align: top; width: 50%; padding: 0; }
            .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #666; }
            table.lines { width: 100%; border-collapse: collapse; margin-top: 16pt; }
            table.lines th { font-size: 7.5pt; text-transform: uppercase; text-align: left;
                             border-bottom: 0.6pt solid #333; padding: 4pt 3pt; }
            table.lines td { padding: 4pt 3pt; border-bottom: 0.3pt solid #ddd; }
            .right { text-align: right; }
            table.totals { margin-top: 10pt; width: 46%; border-collapse: collapse; }
            table.totals td { padding: 2.5pt 3pt; }
            table.totals tr.grand td { border-top: 0.6pt solid #333; font-weight: bold; }
            .terms { margin-top: 16pt; font-size: 8pt; }
            CSS;
    }

    private static function body(Invoice $invoice): string
    {
        $rows = '';

        foreach ($invoice->lines as $line) {
            $rows .= self::lineRow($line);
        }

        return '<h1>' . self::text(self::documentTitle($invoice)) . '</h1>'
            . '<div class="muted">' . self::dates($invoice) . '</div>'
            . self::parties($invoice)
            . '<table class="lines">'
            . '<thead><tr>'
            . '<th>Description</th><th class="right">Qté</th><th class="right">P.U. HT</th>'
            . '<th class="right">Remise</th><th class="right">HT</th>'
            . '<th class="right">TVA</th><th class="right">TTC</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . self::totals($invoice)
            . self::taxBreakdown($invoice)
            . self::terms($invoice);
    }

    private static function lineRow(InvoiceLine $line): string
    {
        return '<tr>'
            . '<td>' . self::text($line->description) . '</td>'
            . '<td class="right">' . self::text((string) $line->quantity) . '</td>'
            . '<td class="right">' . self::amount($line->unitPrice) . '</td>'
            . '<td class="right">' . self::amount($line->discount) . '</td>'
            . '<td class="right">' . self::amount($line->net) . '</td>'
            . '<td class="right">' . self::percentage($line->vatRateBasisPoints) . '</td>'
            . '<td class="right">' . self::amount($line->gross) . '</td>'
            . '</tr>';
    }

    private static function dates(Invoice $invoice): string
    {
        $parts = [];

        if ($invoice->issuedAt !== null) {
            $parts[] = 'Émise le ' . $invoice->issuedAt->format('d/m/Y');
        }

        if ($invoice->dueAt !== null) {
            $parts[] = 'échéance le ' . $invoice->dueAt->format('d/m/Y');
        }

        if ($invoice->periodStart !== null && $invoice->periodEnd !== null) {
            $parts[] = sprintf(
                'période du %s au %s',
                $invoice->periodStart->format('d/m/Y'),
                $invoice->periodEnd->format('d/m/Y'),
            );
        }

        return self::text(implode(' · ', $parts));
    }

    private static function parties(Invoice $invoice): string
    {
        return '<table class="parties"><tr>'
            . '<td><div class="label">Émetteur</div>' . self::party($invoice->supplier) . '</td>'
            . '<td><div class="label">Client</div>' . self::party($invoice->customer) . '</td>'
            . '</tr></table>';
    }

    /**
     * A party's mandatory mentions, in postal order.
     *
     * A field that is absent is omitted rather than printed empty: a supplier
     * not liable for VAT legitimately has no number, and a blank line labelled
     * "TVA" reads as a missing value rather than an inapplicable one.
     *
     * @param array<string, mixed> $party
     */
    private static function party(array $party): string
    {
        $lines = [];

        foreach (['legal_name', 'address_line1', 'address_line2'] as $field) {
            $value = self::partyField($party, $field);

            if ($value !== null) {
                $lines[] = self::text($value);
            }
        }

        $town = array_filter([
            self::partyField($party, 'postal_code'),
            self::partyField($party, 'city'),
        ]);

        if ($town !== []) {
            $lines[] = self::text(implode(' ', $town));
        }

        foreach (['country_code' => '', 'registration_number' => 'SIREN/SIRET ', 'vat_number' => 'TVA '] as $field => $prefix) {
            $value = self::partyField($party, $field);

            if ($value !== null) {
                $lines[] = self::text($prefix . $value);
            }
        }

        return implode('<br>', $lines);
    }

    /**
     * @param array<string, mixed> $party
     */
    private static function partyField(array $party, string $field): ?string
    {
        $value = $party[$field] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function totals(Invoice $invoice): string
    {
        return '<table class="totals">'
            . '<tr><td>Total HT</td><td class="right">' . self::amount($invoice->net) . '</td></tr>'
            . '<tr><td>TVA</td><td class="right">' . self::amount($invoice->vat) . '</td></tr>'
            . '<tr class="grand"><td>Total TTC</td><td class="right">'
            . self::amount($invoice->gross) . '</td></tr>'
            . '</table>';
    }

    /**
     * VAT per rate, which is a mandatory mention when more than one applies —
     * and printed even when one does, because a reader checking the total
     * should not have to derive the base it was taken on.
     */
    private static function taxBreakdown(Invoice $invoice): string
    {
        if ($invoice->taxes === []) {
            return '';
        }

        $rows = '';

        foreach ($invoice->taxes as $tax) {
            $rows .= self::taxRow($tax);
        }

        return '<table class="lines">'
            . '<thead><tr><th>Ventilation TVA</th><th class="right">Base HT</th>'
            . '<th class="right">Taux</th><th class="right">TVA</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    private static function taxRow(TaxRecord $tax): string
    {
        return '<tr>'
            . '<td>' . self::text($tax->jurisdiction) . '</td>'
            . '<td class="right">' . self::amount($tax->taxable) . '</td>'
            . '<td class="right">' . self::percentage($tax->rateBasisPoints) . '</td>'
            . '<td class="right">' . self::amount($tax->tax) . '</td>'
            . '</tr>';
    }

    private static function terms(Invoice $invoice): string
    {
        if ($invoice->paymentTerms === null) {
            return '';
        }

        return '<div class="terms">' . self::text($invoice->paymentTerms) . '</div>';
    }

    private static function amount(Money $money): string
    {
        return self::text(AmountText::money($money));
    }

    private static function percentage(int $basisPoints): string
    {
        return self::text(AmountText::rate($basisPoints));
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
