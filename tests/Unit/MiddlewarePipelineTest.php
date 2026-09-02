<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Http\MiddlewarePipeline;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewareRunsInDeclarationOrderAroundTheFinalHandler(): void
    {
        $pipeline = new MiddlewarePipeline(
            [$this->tagging('a'), $this->tagging('b')],
            $this->finalHandler('end'),
        );

        self::assertSame('a(b(end))', (string) $pipeline->handle($this->request())->getBody());
    }

    /**
     * The pipeline advances its cursor on a clone rather than on itself, so a
     * single instance can be handled repeatedly. If it mutated internal state
     * the second call would start from where the first stopped.
     */
    public function testPipelineCanBeHandledMoreThanOnce(): void
    {
        $pipeline = new MiddlewarePipeline(
            [$this->tagging('a'), $this->tagging('b')],
            $this->finalHandler('end'),
        );

        $first = (string) $pipeline->handle($this->request())->getBody();
        $second = (string) $pipeline->handle($this->request())->getBody();

        self::assertSame($first, $second);
    }

    public function testEmptyPipelineDelegatesStraightToTheFinalHandler(): void
    {
        $pipeline = new MiddlewarePipeline([], $this->finalHandler('end'));

        self::assertSame('end', (string) $pipeline->handle($this->request())->getBody());
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest(uri: 'https://api.test/', method: 'GET');
    }

    private function tagging(string $tag): MiddlewareInterface
    {
        return new class ($tag) implements MiddlewareInterface {
            public function __construct(private readonly string $tag)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $inner = (string) $handler->handle($request)->getBody();

                return new TextResponse(sprintf('%s(%s)', $this->tag, $inner));
            }
        };
    }

    private function finalHandler(string $body): RequestHandlerInterface
    {
        return new class ($body) implements RequestHandlerInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse($this->body);
            }
        };
    }
}
