<?php

declare(strict_types=1);

namespace App\Tax\Infrastructure;

use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\VatNumberCheck;
use App\Tax\Domain\VatNumberValidator;
use Closure;
use JsonException;

/**
 * The European Commission's VIES service, over its REST API (2026-10-29).
 *
 * Chosen by `VAT_NUMBER_VALIDATOR=vies`. One request per check:
 *
 *     POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *     {"countryCode": "FR", "vatNumber": "12345678901"}
 *
 * **It fails closed, and only one answer verifies.** `valid: true` with no
 * error beside it is VALID. `valid: false`, or VIES refusing the input as
 * malformed (`INVALID_INPUT`), is INVALID — the number is wrong, and the
 * customer is the one who can fix it. Everything else is UNAVAILABLE: a
 * member state's register down (`MS_UNAVAILABLE`), VIES busy or timing out
 * (`MS_MAX_CONCURRENT_REQ`, `TIMEOUT`, `SERVICE_UNAVAILABLE`), an error
 * code nobody has seen before, a status that is not 2xx, a body that is not
 * the JSON it should be, no answer at all. Doubt is never a verification —
 * §25.3, and the reason the contract is three-valued.
 *
 * **The prefix is VIES's, not ISO's.** Greece is `EL` (ISO `GR`) and
 * Northern Ireland `XI`, which is no country at all; the mapping is
 * {@see TaxIdentification}'s, so there is one of it. A number typed with
 * `GR` is asked about as `EL`.
 *
 * The wire has the posture of the platform's other outbound calls
 * ({@see \App\Product\Infrastructure\CurlProductManifests}): https only, no
 * redirects, five seconds to connect and ten to answer, a cap on what is
 * read. It is a closure so a test can stand in for it; nothing else ever
 * does.
 */
final class ViesVatNumberValidator implements VatNumberValidator
{
    public const NAME = 'vies';
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /** What a transport says when it got no answer: these, or anything else it names. */
    public const TIMEOUT = 'TIMEOUT';
    public const UNREACHABLE = 'UNREACHABLE';
    public const TLS = 'TLS';

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 10;

    /** An answer is a few hundred bytes; a body bigger than this is not one. */
    private const MAX_BYTES = 65_536;

    /** The codes that are an answer about the number rather than a failure to give one. */
    private const ABOUT_THE_NUMBER = ['VALID', 'INVALID', 'INVALID_INPUT'];

    /** @var Closure(string): array{status: int, body: string, error: string|null} */
    private readonly Closure $transport;

    /**
     * @param (Closure(string): array{status: int, body: string, error: string|null})|null $transport
     *        posts a JSON body to {@see ENDPOINT} and says what came back: the
     *        status and body, or — with status 0 — why nothing did. curl
     *        unless a test says otherwise.
     */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport ?? self::overCurl(...);
    }

    public function check(string $vatNumber): VatNumberCheck
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber) ?? '');

        if (preg_match('/^[A-Z]{2}[A-Z0-9]{2,}$/', $normalised) !== 1) {
            // Not asked: VIES cannot be asked about a number with no country
            // in front of it, and there is no doubt about the answer.
            return $this->answer(VatNumberCheck::INVALID, $normalised, ['reason' => 'The number is not a country prefix followed by a number.']);
        }

        // GR → EL; EL and XI stay themselves (TaxIdentification).
        $prefix = TaxIdentification::prefixForCountry(TaxIdentification::countryForPrefix(substr($normalised, 0, 2)));
        $number = substr($normalised, 2);
        $asked = ['country_code' => $prefix, 'vat_number' => $number];

        try {
            $payload = json_encode(['countryCode' => $prefix, 'vatNumber' => $number], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->answer(VatNumberCheck::UNAVAILABLE, $normalised, $asked + ['reason' => 'The request could not be encoded.']);
        }

        $reply = ($this->transport)($payload);

        if ($reply['error'] !== null || $reply['status'] === 0) {
            return $this->answer(VatNumberCheck::UNAVAILABLE, $normalised, $asked + ['reason' => $reply['error'] ?? self::UNREACHABLE]);
        }

        $asked['http_status'] = $reply['status'];

        try {
            $decoded = json_decode($reply['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return $this->answer(VatNumberCheck::UNAVAILABLE, $normalised, $asked + ['reason' => 'MALFORMED_ANSWER']);
        }

        $errors = self::errorsIn($decoded);
        $asked['errors'] = $errors;

        // VIES's own date and reference for the consultation: the dated proof
        // §25.3 asks to keep, in the service's words rather than ours.
        $requestDate = $decoded['requestDate'] ?? null;
        $requestIdentifier = $decoded['requestIdentifier'] ?? null;

        if (is_string($requestDate)) {
            $asked['request_date'] = $requestDate;
        }

        if (is_string($requestIdentifier) && $requestIdentifier !== '') {
            $asked['request_identifier'] = $requestIdentifier;
        }

        // Any failure to answer outranks everything else said beside it.
        if (array_diff($errors, self::ABOUT_THE_NUMBER) !== []) {
            return $this->answer(VatNumberCheck::UNAVAILABLE, $normalised, $asked + ['reason' => 'SERVICE_ERROR']);
        }

        // Refused as malformed: the one error that is about the number, and
        // VIES answers it with a 400.
        if (in_array('INVALID_INPUT', $errors, true)) {
            return $this->answer(VatNumberCheck::INVALID, $normalised, $asked);
        }

        if ($reply['status'] < 200 || $reply['status'] >= 300) {
            return $this->answer(VatNumberCheck::UNAVAILABLE, $normalised, $asked + ['reason' => 'HTTP_STATUS']);
        }

        $valid = $decoded['valid'] ?? null;

        if ($valid === true && !in_array('INVALID', $errors, true)) {
            return $this->answer(
                VatNumberCheck::VALID,
                $normalised,
                $asked,
                self::stated($decoded['name'] ?? null),
                self::stated($decoded['address'] ?? null),
            );
        }

        if ($valid === false && !in_array('VALID', $errors, true)) {
            return $this->answer(VatNumberCheck::INVALID, $normalised, $asked);
        }

        // No verdict, or one that contradicts itself.
        return $this->answer(VatNumberCheck::UNAVAILABLE, $normalised, $asked + ['reason' => 'MALFORMED_ANSWER']);
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function answer(
        string $outcome,
        string $queried,
        array $evidence,
        ?string $name = null,
        ?string $address = null,
    ): VatNumberCheck {
        return new VatNumberCheck(
            $outcome,
            $name,
            $address,
            ['provider' => self::NAME, 'queried' => $queried] + $evidence + ['checked_at' => gmdate('c')],
            self::NAME,
        );
    }

    /**
     * The error codes an answer carries: `userError`, and each of
     * `errorWrappers[].error`.
     *
     * @param array<mixed> $decoded
     *
     * @return list<string>
     */
    private static function errorsIn(array $decoded): array
    {
        $errors = [];
        $user = $decoded['userError'] ?? null;

        if (is_string($user) && $user !== '') {
            $errors[] = strtoupper($user);
        }

        $wrappers = $decoded['errorWrappers'] ?? null;

        if (is_array($wrappers)) {
            foreach ($wrappers as $wrapper) {
                $error = is_array($wrapper) ? ($wrapper['error'] ?? null) : null;

                // A wrapper with no code is still an error: counted as one
                // nobody recognises, which is UNAVAILABLE.
                $errors[] = is_string($error) && $error !== '' ? strtoupper($error) : 'UNKNOWN';
            }
        }

        return array_values(array_unique($errors));
    }

    /** VIES writes `---` where a member state does not disclose a name or an address. */
    private static function stated(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || $value === '---' ? null : $value;
    }

    /**
     * @return array{status: int, body: string, error: string|null}
     */
    private static function overCurl(string $payload): array
    {
        $handle = curl_init(self::ENDPOINT);

        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => self::UNREACHABLE];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn (
                mixed $resource,
                int $expected,
                int $received,
            ): int => $received > self::MAX_BYTES ? 1 : 0,
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $errno !== 0) {
            return ['status' => 0, 'body' => '', 'error' => match ($errno) {
                CURLE_OPERATION_TIMEDOUT => self::TIMEOUT,
                CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE => self::TLS,
                default => self::UNREACHABLE,
            }];
        }

        return ['status' => is_int($status) ? $status : 0, 'body' => $body, 'error' => null];
    }
}
