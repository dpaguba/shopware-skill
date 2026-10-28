<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1746000000CreateWishlistItemTable extends MigrationStep
{
    /**
     * Since SW 6.7 the returned value is validated: it must be at least 1 and below 2147483647
     * (32-bit max int), otherwise the migration is rejected. Larger values used to be clamped,
     * which gave several migrations the same timestamp and made their order random.
     */
    public function getCreationTimestamp(): int
    {
        return 1746000000;
    }

    public function update(Connection $connection): void
    {
        // product has the composite primary key (id, version_id), so its FK covers both columns.
        // A FK on product.id alone is non-standard and rejected by MySQL 8.4.
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `wishlist_item` (
                `id`                 BINARY(16)  NOT NULL,
                `customer_id`        BINARY(16)  NOT NULL,
                `product_id`         BINARY(16)  NOT NULL,
                `product_version_id` BINARY(16)  NOT NULL,
                `created_at`         DATETIME(3) NOT NULL,
                `updated_at`         DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `fk.wishlist_item.customer_id` (`customer_id`),
                KEY `fk.wishlist_item.product_id`  (`product_id`, `product_version_id`),
                CONSTRAINT `fk.wishlist_item.customer_id` FOREIGN KEY (`customer_id`)
                    REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.wishlist_item.product_id` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
