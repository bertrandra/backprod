<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\InvoiceDocuments;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/invoices/{invoiceId}/pdf.
 *
 * Behind `billing.read`, the same permission that returns the invoice as JSON:
 * the PDF carries nothing the JSON does not, so a second permission would only
 * be a second thing to get wrong. The lookup is scoped by tenant and product,
 * so another company's invoice answers 404 rather than 403.
 *
 * `Content-Disposition: attachment` and `nosniff` for the reasons
 * `DownloadAssetController` gives: this response is not JSON, and a browser
 * deciding for itself what to do with bytes served from this origin is how a
 * stored cross-site scripting hole gets built. A PDF viewer is a scripting
 * engine, so this applies even though these bytes are ours.
 */
final class ShowInvoicePdfController implements RouteHandler
{
    public function __construct(private readonly InvoiceDocuments $documents)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::readable($request);

        $rendered = $this->documents->pdf(
            $context->tenantId,
            $context->productId,
            BillingRoute::invoiceId($request),
            $context->documentsOf(),
        );

        $stream = new Stream('php://temp', 'wb+');
        $stream->write($rendered['contents']);
        $stream->rewind();

        return new Response($stream, 200, [
            'Content-Type' => $rendered['contentType'],
            'Content-Length' => (string) strlen($rendered['contents']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $rendered['filename']),
            // The document never changes once rendered; the status band on
            // top does (docs/tenant-roots.md §2.5), so the tag names both and
            // a paid invoice is fetched afresh. Private, because it is one
            // customer's invoice and a shared cache holding it would serve it
            // to the next person through the proxy.
            'Cache-Control' => 'private, max-age=3600',
            'ETag' => '"' . $rendered['document']->checksum . '-' . substr(hash('sha256', $rendered['band']->text), 0, 12) . '"',
            'X-Invoice-Status' => $rendered['band']->kind,
        ]);
    }
}
