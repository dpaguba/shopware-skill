# Example: Complete Custom Entity Plugin

This example shows a minimal but complete custom entity: `WishlistItem` — stores a product + customer pair.

## Files

```
src/
├── WishlistPlugin.php
├── Migration/
│   └── Migration1746000000CreateWishlistItemTable.php
├── Core/Content/WishlistItem/
│   ├── WishlistItemDefinition.php
│   ├── WishlistItemEntity.php
│   └── WishlistItemCollection.php
└── Resources/config/
    └── services.xml
```

## 1. Migration

```php
<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1746000000CreateWishlistItemTable extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1746000000; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `wishlist_item` (
                `id`          BINARY(16)  NOT NULL,
                `customer_id` BINARY(16)  NOT NULL,
                `product_id`  BINARY(16)  NOT NULL,
                `created_at`  DATETIME(3) NOT NULL,
                `updated_at`  DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `fk.wishlist_item.customer_id` (`customer_id`),
                KEY `fk.wishlist_item.product_id`  (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void {}
}
```

## 2. Definition

```php
<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin\Core\Content\WishlistItem;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class WishlistItemDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'wishlist_item';

    public function getEntityName(): string { return self::ENTITY_NAME; }
    public function getEntityClass(): string { return WishlistItemEntity::class; }
    public function getCollectionClass(): string { return WishlistItemCollection::class; }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id'),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

## 3. Entity

```php
<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin\Core\Content\WishlistItem;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class WishlistItemEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;
    protected string $productId;

    public function getCustomerId(): string { return $this->customerId; }
    public function getProductId(): string { return $this->productId; }
}
```

## 4. Collection

```php
<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin\Core\Content\WishlistItem;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<WishlistItemEntity> */
class WishlistItemCollection extends EntityCollection
{
    protected function getExpectedClass(): string { return WishlistItemEntity::class; }
}
```

## 5. services.xml

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services
           http://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <service id="VendorName\WishlistPlugin\Core\Content\WishlistItem\WishlistItemDefinition">
            <tag name="shopware.entity.definition"/>
        </service>
    </services>
</container>
```

## Usage

After installing and running migrations:

```php
// Inject: wishlist_item.repository
$this->wishlistItemRepository->create([
    [
        'id'         => Uuid::randomHex(),
        'customerId' => $customerId,
        'productId'  => $productId,
    ],
], $context);

$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('customerId', $customerId));
$criteria->addAssociation('product');
$items = $this->wishlistItemRepository->search($criteria, $context);
```
