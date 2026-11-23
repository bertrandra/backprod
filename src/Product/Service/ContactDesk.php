<?php

declare(strict_types=1);

namespace App\Product\Service;

use App\Product\Domain\ContactRequest;
use App\Product\Domain\ContactRequests;
use App\Product\Domain\ProductRepository;
use App\Product\Domain\ProductShowcase;
use App\Product\Domain\ShowcaseBlock;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\TooManyRequestsException;
use App\Throttle\Domain\RateLimiter;

/**
 * What a visitor sends through a product's contact form, and what support
 * does with it (2026-11-23).
 *
 * **A form is only where the page offers one.** The request is refused
 * unless the product's *published* page carries a `CONTACT` band: a public
 * endpoint that accepted messages for any product code would be a mailbox
 * for every product, including those that never asked for one.
 *
 * **Refused politely, and kept out cheaply.** A hidden field a person never
 * fills (`website`) answers a robot that fills every field with what looks
 * like success and keeps nothing — telling it why would teach it. Each
 * address may send a few an hour, on top of the limit every public route
 * already has.
 */
final class ContactDesk
{
    /** How many requests one address may send to one product in an hour. */
    public const PER_HOUR = 5;

    private const WINDOW = 3600;

    public function __construct(
        private readonly ContactRequests $requests,
        private readonly ProductRepository $products,
        private readonly ProductShowcase $showcase,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * Keep what was sent. Null where the hidden field was filled: nothing
     * kept, and the caller answers as though it had been.
     */
    public function receive(
        string $productCode,
        string $name,
        string $email,
        string $message,
        bool $consented,
        string $trap,
        string $locale,
        string $from,
    ): ?ContactRequest {
        $page = $this->showcase->published($productCode);
        $form = null;

        foreach ($page->blocks ?? [] as $block) {
            if ($block->block === ShowcaseBlock::CONTACT) {
                $form = $block;
            }
        }

        if ($page === null || $form === null) {
            throw new NotFoundException('This page takes no messages.', [], 'CONTACT_NOT_OFFERED');
        }

        $decision = $this->limiter->hit('contact|' . $productCode . '|' . $from, self::WINDOW, self::PER_HOUR);

        if (!$decision->allowed()) {
            throw new TooManyRequestsException($decision->retryAfterSeconds, self::PER_HOUR, self::WINDOW);
        }

        if (trim($trap) !== '') {
            return null;
        }

        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > ContactRequest::LONGEST_EMAIL) {
            throw self::invalid('email', 'an email address somebody can answer');
        }

        if (!$consented) {
            throw self::invalid('consent', 'true: the visitor agreed to be answered');
        }

        $said = $form->contentIn($locale)['consent'] ?? '';
        $consent = is_string($said) ? $said : '';

        return $this->requests->add($page->productId, trim($name), $email, trim($message), $consent, $locale);
    }

    /**
     * @return array{requests: list<ContactRequest>, total: int, open: int}
     */
    public function page(?string $productCode, int $limit, int $offset): array
    {
        $productId = null;

        if ($productCode !== null) {
            $productId = $this->products->findByCode($productCode)->id
                ?? throw new NotFoundException('No such product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $this->requests->page($productId, $limit, $offset);
    }

    public function handle(string $id, string $staffUserId): ContactRequest
    {
        return $this->requests->handle($id, $staffUserId)
            ?? throw new NotFoundException('No such request.', [], 'CONTACT_REQUEST_NOT_FOUND');
    }

    private static function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', [
            'field' => $field,
            'requirement' => $requirement,
        ]);
    }
}
