<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ProductCapabilities;
use App\Product\Domain\ProductFeature;
use App\Shared\Database\Row;
use App\Shared\Exceptions\BadRequestException;
use Doctrine\DBAL\Connection;

final class PostgresProductCapabilities implements ProductCapabilities
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function declare(string $productId, array $capabilities): array
    {
        $codes = array_map(static fn (ProductFeature $c): string => $c->code, $capabilities);

        $this->refuseUnknown($codes);

        // One transaction: a product redeploying declares its whole list, and
        // a failure halfway would leave it having stopped shipping everything
        // it had not got round to re-declaring.
        return $this->connection->transactional(function () use ($productId, $capabilities): array {
            $this->connection->executeStatement(
                'DELETE FROM product_features WHERE product_id = :product',
                ['product' => $productId],
            );

            foreach ($capabilities as $capability) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO product_features (product_id, code, name, enabled)
                        VALUES (:product, :code, :name, :enabled)
                        SQL,
                    [
                        'product' => $productId,
                        'code' => $capability->code,
                        'name' => $capability->name,
                        'enabled' => $capability->enabled ? 'true' : 'false',
                    ],
                );
            }

            return $this->declared($productId);
        });
    }

    /**
     * @param list<string> $codes
     */
    private function refuseUnknown(array $codes): void
    {
        // The whole list in one query, and the whole answer in one refusal:
        // a program integrating for the first time should learn every code
        // it got wrong, not the first one.
        $known = $this->connection->fetchFirstColumn('SELECT code FROM features ORDER BY code');
        $vocabulary = [];

        foreach ($known as $code) {
            if (is_string($code)) {
                $vocabulary[] = $code;
            }
        }

        $unknown = array_values(array_unique(array_diff($codes, $vocabulary)));

        if ($unknown === []) {
            return;
        }

        // The platform's vocabulary comes back with the refusal. It is not
        // commercial information — prices and offers are, and none of them
        // is here — and a refusal that says only "no" turns an integration
        // into a guessing game against a list only the console can see.
        throw new BadRequestException(
            'FEATURE_CODE_UNKNOWN',
            'The platform knows no feature by those codes. A capability is added to the platform s list by a person, not by a program.',
            ['unknown' => $unknown, 'known' => $vocabulary],
        );
    }

    /**
     * @return list<ProductFeature>
     */
    private function declared(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT code, name, enabled FROM product_features WHERE product_id = :product ORDER BY code',
            ['product' => $productId],
        );

        return array_map(
            static fn (array $row): ProductFeature => new ProductFeature(
                Row::string($row, 'code'),
                Row::string($row, 'name'),
                Row::boolean($row, 'enabled'),
            ),
            $rows,
        );
    }
}
