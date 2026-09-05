<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Context\ClientAddress;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who a request is counted against (§31).
 *
 * This is "never trust a client-supplied tenant id" applied to the network,
 * and it is worth its own tests because getting it wrong is silent both ways:
 * believe the header and the limiter has a bypass anybody can use; ignore it
 * behind a real proxy and every client on earth shares one bucket.
 *
 * The spoofing cases come first (§37.4).
 */
#[CoversClass(ClientAddress::class)]
final class ClientAddressTest extends TestCase
{
    public function testAForwardedHeaderFromAnUntrustedConnectionIsIgnored(): void
    {
        $request = $this->from('203.0.113.9')->withHeader(ClientAddress::HEADER, '1.1.1.1');

        // Anybody may send this header. Believing it would mean a fresh
        // identity per request and an allowance that never runs out.
        self::assertSame('203.0.113.9', ClientAddress::of($request, []));
    }

    public function testAForwardedHeaderIsIgnoredWhenTheProxyListIsEmpty(): void
    {
        $request = $this->from('10.0.0.1')->withHeader(ClientAddress::HEADER, '1.1.1.1');

        self::assertSame('10.0.0.1', ClientAddress::of($request, []));
    }

    public function testASpoofedChainIsWalkedFromTheRightNotTheLeft(): void
    {
        // The client sent "1.1.1.1" itself and our proxy appended the address
        // it actually saw. Reading the chain left to right would take the
        // attacker's word for it.
        $request = $this->from('10.0.0.1')
            ->withHeader(ClientAddress::HEADER, '1.1.1.1, 203.0.113.9');

        self::assertSame('203.0.113.9', ClientAddress::of($request, ['10.0.0.1']));
    }

    public function testATrustedProxyIsBelieved(): void
    {
        $request = $this->from('10.0.0.1')->withHeader(ClientAddress::HEADER, '203.0.113.9');

        self::assertSame('203.0.113.9', ClientAddress::of($request, ['10.0.0.1']));
    }

    public function testOurOwnProxiesAreSkippedUntilSomebodyElseIsFound(): void
    {
        $request = $this->from('10.0.0.1')
            ->withHeader(ClientAddress::HEADER, '203.0.113.9, 10.0.0.2, 10.0.0.3');

        self::assertSame('203.0.113.9', ClientAddress::of($request, ['10.0.0.1', '10.0.0.2', '10.0.0.3']));
    }

    /**
     * A chain naming nobody but us. The nearest connection is the most that
     * can honestly be said.
     */
    public function testAChainOfOnlyOurOwnProxiesFallsBackToTheConnection(): void
    {
        $request = $this->from('10.0.0.1')->withHeader(ClientAddress::HEADER, '10.0.0.2');

        self::assertSame('10.0.0.1', ClientAddress::of($request, ['10.0.0.1', '10.0.0.2']));
    }

    /**
     * Counted, not exempted: an unattributable request is still a request,
     * and a free pass for one is a bypass for all of them.
     */
    public function testARequestWithNoAddressAtAllIsStillGivenABucket(): void
    {
        $request = new ServerRequest(uri: 'https://api.test/api/v1/health', method: 'GET');

        self::assertSame(ClientAddress::UNKNOWN, ClientAddress::of($request, []));
    }

    private function from(string $address): ServerRequestInterface
    {
        return new ServerRequest(
            serverParams: ['REMOTE_ADDR' => $address],
            uri: 'https://api.test/api/v1/health',
            method: 'GET',
        );
    }
}
