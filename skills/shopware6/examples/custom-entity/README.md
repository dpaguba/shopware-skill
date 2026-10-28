# Example: Complete Custom Entity Plugin

A minimal but runnable plugin with one custom DAL entity: `wishlist_item`, a customer/product pair.

Every file in this directory is real source, not a snippet. Copy the tree into
`custom/plugins/WishlistPlugin/` and run `bin/console plugin:refresh`, then
`bin/console plugin:install --activate WishlistPlugin` (`--refresh` and `--activate` cannot be combined).

## Files

| File | Purpose |
|---|---|
| [composer.json](composer.json) | Plugin metadata, `shopware-plugin-class`, PSR-4 autoload |
| [src/WishlistPlugin.php](src/WishlistPlugin.php) | Bootstrap, drops the table on uninstall when user data is not kept |
| [src/Migration/Migration1746000000CreateWishlistItemTable.php](src/Migration/Migration1746000000CreateWishlistItemTable.php) | Schema |
| [src/Core/Content/WishlistItem/WishlistItemDefinition.php](src/Core/Content/WishlistItem/WishlistItemDefinition.php) | Field definitions |
| [src/Core/Content/WishlistItem/WishlistItemEntity.php](src/Core/Content/WishlistItem/WishlistItemEntity.php) | Entity class |
| [src/Core/Content/WishlistItem/WishlistItemCollection.php](src/Core/Content/WishlistItem/WishlistItemCollection.php) | Typed collection |
| [src/Resources/config/services.php](src/Resources/config/services.php) | Registers the definition (PHP config; `services.xml` is deprecated since 6.7.14 and removed in 6.8) |

## Three things this example gets right

**Versioned foreign keys.** `product` has a composite primary key `(id, version_id)`. A foreign key to it
needs both columns in SQL (`product_id`, `product_version_id`) and a
`ReferenceVersionField(ProductDefinition::class)` in the definition. A constraint on `product.id` alone is a
non-standard foreign key: MySQL 8.4 rejects it with `restrict_fk_on_non_standard_key=ON`, and
`bin/console dal:validate` reports it since 6.7.13. `customer` is not versioned, so its FK stays single-column.

**Migration timestamp range.** Since SW 6.7 `getCreationTimestamp()` is validated: it must be at least 1
and below 2147483647 (32-bit max int). A larger value used to be clamped silently, which made migration
order non-deterministic.

**Uninstall cleanup.** Since SW 6.7 plugin migrations are deleted from the migration table *before*
`uninstall()` runs, so dropping the table there lets a later reinstall replay the migration cleanly.

## Usage

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

$items = $this->wishlistItemRepository->search($criteria, $context)->getEntities();
```

`productVersionId` does not need to be passed: DAL fills it with `Defaults::LIVE_VERSION`.

## Attribute-based alternative

Since SW 6.6.3 the same entity can be declared with PHP attributes instead of a definition class, which
removes the entity and collection boilerplate. See `references/advanced.md`. Note that the DAL attribute
classes (except the base `Field`) were made `final` in 6.7, so they cannot be extended.
