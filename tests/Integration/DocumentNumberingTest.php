<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Billing\Infrastructure\PostgresCreditNoteRepository;
use App\Billing\Infrastructure\PostgresInvoiceRepository;
use App\Commerce\Infrastructure\PostgresOfferLineDetails;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A gapless series belongs to whoever issues it (2026-09-25).
 *
 * The demonstration world proves the end of this end to end — three
 * organisations, each selling seats to its own people, each numbering from
 * one. What it cannot prove is the half that has no seats in it: that the
 * platform's own series is still a series, and that a correction lands in
 * the series of the document it corrects. Both are here, against the
 * database that enforces them.
 *
 * Written against the adapters rather than through HTTP on purpose. The rule
 * being checked is the one the SQL implements — `IS NOT DISTINCT FROM` for
 * the platform's null issuer, and a unique index that treats those nulls as
 * the same issuer — and a route in front of it would only make a failure
 * harder to read.
 */
#[CoversClass(PostgresInvoiceRepository::class)]
#[CoversClass(PostgresCreditNoteRepository::class)]
final class DocumentNumberingTest extends DatabaseTestCase
{
    private PostgresInvoiceRepository $invoices;
    private PostgresCreditNoteRepository $creditNotes;

    private string $product = '';
    private string $acme = '';
    private string $globex = '';

    protected function setUp(): void
    {
        parent::setUp();

        $offers = new PostgresOfferLineDetails($this->connection);
        $this->invoices = new PostgresInvoiceRepository($this->connection, $offers);
        $this->creditNotes = new PostgresCreditNoteRepository($this->connection, $this->invoices, $offers);

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex SA', 'globex') RETURNING id");
    }

    /**
     * The defect this closes, in the words it appeared in: Initech raised one
     * invoice and it came out `2026-000005`, announcing four documents to a
     * customer that Initech had never written — and leaving Acme's own series
     * missing the numbers that had gone to other companies.
     */
    public function testTwoOrganisationsEachNumberFromOne(): void
    {
        $year = date('Y');

        self::assertSame($year . '-000001', $this->issue($this->acme)->number);
        self::assertSame($year . '-000002', $this->issue($this->acme)->number);

        // Globex has issued nothing, so Globex is at one. Under a single
        // platform-wide counter this read 000003.
        self::assertSame($year . '-000001', $this->issue($this->globex)->number);
        self::assertSame($year . '-000002', $this->issue($this->globex)->number);

        // And Acme's own series is unbroken: 1, 2, 3 with nothing of
        // Globex's in the middle of it.
        self::assertSame($year . '-000003', $this->issue($this->acme)->number);
    }

    /**
     * The platform is an issuer too, and its series is the one whose issuer
     * is null. `= NULL` matches nothing, so a naive query would hand it
     * `000001` for ever and the second document would fail on the index —
     * which is why the adapter asks `IS NOT DISTINCT FROM`.
     */
    public function testThePlatformKeepsASeriesOfItsOwn(): void
    {
        $year = date('Y');

        self::assertSame($year . '-000001', $this->issue(null)->number);

        // An organisation issuing in between takes nothing from it.
        self::assertSame($year . '-000001', $this->issue($this->acme)->number);

        self::assertSame($year . '-000002', $this->issue(null)->number);
        self::assertSame($year . '-000003', $this->issue(null)->number);
    }

    /**
     * Not a belt-and-braces check: the numbering reads a maximum under a
     * table lock, and the index is what makes a mistake there a refusal
     * rather than two documents with one number. It matters most for the
     * platform, whose issuer is null — a plain unique index would consider
     * every such row distinct and say nothing at all.
     */
    public function testTheDatabaseRefusesTwoDocumentsWithOneNumberInOneSeries(): void
    {
        $issued = $this->issue(null);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, issuer_tenant_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units, issued_at)
                VALUES (:tenant, :product, NULL, :number, 'ISSUED', 'EUR', 100, 20, 120, now())
                SQL,
            ['tenant' => $this->acme, 'product' => $this->product, 'number' => $issued->number],
        );
    }

    /**
     * A credit note corrects one company's document, so it belongs in that
     * company's series. Deciding again at correction time could put Acme's
     * correction in the platform's series, and then Acme's books would record
     * an invoice with no correction against it.
     */
    public function testACreditNoteFollowsTheSeriesOfTheInvoiceItCorrects(): void
    {
        $year = date('Y');

        // The platform corrects one of its own first, so that a credit note
        // numbered 1 in Acme's series cannot pass by accident.
        $platform = $this->issue(null);
        $note = $this->creditNotes->issue($platform, $platform->lines, $platform->supplier, $platform->customer, 'test', null);
        self::assertSame('AV' . $year . '-000001', $note->number);

        $theirs = $this->issue($this->acme);
        $correction = $this->creditNotes->issue($theirs, $theirs->lines, $theirs->supplier, $theirs->customer, 'test', null);

        // One in Acme's series, not two in the platform's.
        self::assertSame('AV' . $year . '-000001', $correction->number);
        self::assertSame(
            $this->acme,
            $this->connection->fetchOne('SELECT issuer_tenant_id FROM credit_notes WHERE id = :id', ['id' => $correction->id]),
        );
    }

    private function issue(?string $issuerTenantId): Invoice
    {
        return $this->invoices->issue(
            // The isolation context is the organisation either way; who
            // *issues* is the separate question this whole file is about, so
            // both are Acme's rows and only some are Acme's documents.
            $this->acme,
            $this->product,
            $issuerTenantId,
            null,
            [InvoiceLine::of(1, 'A month of Atlas', 1, Money::of(2900, 'EUR'), Money::zero('EUR'), 2000, null)],
            ['legal_name' => 'Whoever issued it', 'country_code' => 'FR'],
            ['legal_name' => 'Whoever bought it', 'country_code' => 'FR'],
            'FR',
            null,
            null,
            'Payable on receipt.',
            null,
        );
    }

    private function id(string $sql): string
    {
        $id = $this->connection->fetchOne($sql);

        self::assertIsString($id);

        return $id;
    }
}
