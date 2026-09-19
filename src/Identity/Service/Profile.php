<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Product\Service\ProductCatalogue;
use App\Shared\Exceptions\UnprocessableEntityException;
use App\Shared\Validation\Locale;
use App\User\Domain\PlatformUser;
use App\User\Domain\UserRepository;

/**
 * The caller's own account.
 *
 * Thin, but it exists so controllers orchestrate nothing themselves: when
 * profile changes grow an audit trail or a privacy export (§26.1), they
 * attach here rather than to an HTTP handler.
 */
final class Profile
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProductCatalogue $catalogue,
    ) {
    }

    public function of(string $userId): ?PlatformUser
    {
        return $this->users->find($userId);
    }

    public function rename(string $userId, ?string $displayName): ?PlatformUser
    {
        $this->users->updateDisplayName($userId, $displayName);

        return $this->users->find($userId);
    }

    /**
     * The product this person's screens open in when the address names none.
     *
     * Only one they hold: a default the person cannot act in would open every
     * screen on a refusal. Null clears it, and the shell then chooses among
     * what they have.
     *
     * @throws UnprocessableEntityException PRODUCT_NOT_HELD
     */
    /** The language they read in (ADR-050). Refused unless the platform speaks it. */
    public function chooseLocale(string $userId, string $locale): ?PlatformUser
    {
        if (!Locale::isKnown($locale)) {
            throw new UnprocessableEntityException(
                'LOCALE_UNKNOWN',
                'The platform does not speak that language.',
                ['locale' => $locale, 'known' => Locale::ALL],
            );
        }

        $this->users->updateLocale($userId, $locale);

        return $this->users->find($userId);
    }

    public function chooseDefaultProduct(string $userId, ?string $productCode): ?PlatformUser
    {
        $productId = null;

        if ($productCode !== null) {
            foreach ($this->catalogue->available($userId) as $product) {
                if ($product->code === $productCode) {
                    $productId = $product->id;
                }
            }

            if ($productId === null) {
                throw new UnprocessableEntityException(
                    'PRODUCT_NOT_HELD',
                    'A default product is one you are a member of.',
                    ['product' => $productCode],
                );
            }
        }

        $this->users->updateDefaultProduct($userId, $productId);

        return $this->users->find($userId);
    }

    /**
     * The default product's code, if the person still holds it — a default
     * pointing at a product they have since left is not offered, and a
     * client choosing from this list never chooses one they cannot act in.
     */
    public function defaultProductCode(string $userId): ?string
    {
        $user = $this->users->find($userId);

        if ($user?->defaultProductId === null) {
            return null;
        }

        foreach ($this->catalogue->available($userId) as $product) {
            if ($product->id === $user->defaultProductId) {
                return $product->code;
            }
        }

        return null;
    }
}
