<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Validation\Locale;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The language a public page answers in (2026-09-24).
 *
 * A signed-in person's language is on their profile, and the request context
 * carries it. A **stranger has no profile**, and the three public reads — the
 * shop window, one offer, a product's story — are read by strangers.
 *
 * So it is `Accept-Language`, and it has to be a header rather than a query
 * parameter for the reason ADR-050 removed `?lang=` on 2026-09-23: a
 * parameter travels inside a link, so a page somebody shared would impose
 * *their* language on whoever opened it next. A header cannot be shared by
 * accident. It is the reader saying which language they are reading in, now —
 * which is exactly what the language picker on the storefront means when
 * somebody uses it.
 *
 * `Locale::of` answers the default for anything it does not recognise, so a
 * browser's full `fr-FR,fr;q=0.9,en;q=0.8` — or a header somebody made up —
 * reads as English rather than as an error. These are shop windows: they do
 * not refuse people over a header.
 *
 * Here rather than copied into each controller because it was copied into
 * each controller first, and a fallback rule with three homes is a fallback
 * rule with three answers.
 */
final class ReaderLanguage
{
    public static function of(ServerRequestInterface $request): string
    {
        return Locale::of($request->getHeaderLine('Accept-Language'));
    }
}
