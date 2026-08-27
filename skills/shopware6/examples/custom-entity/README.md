# Example: Complete Custom Entity Plugin

A minimal but runnable plugin with one custom DAL entity: `wishlist_item`, a customer/product pair.

Every file in this directory is real source, not a snippet. Copy the tree into
`custom/plugins/WishlistPlugin/` and run `bin/console plugin:install --activate WishlistPlugin`.

## Files

| File | Purpose |
|---|---|
| [composer.json](composer.json) | Plugin metadata, `shopware-plugin-class`, PSR-4 autoload |
| [src/WishlistPlugin.php](src/WishlistPlugin.php) | Bootstrap, drops the table on uninstall when user data is not kept |
| [src/Migration/Migration1746000000CreateWishlistItemTable.php](src/Migration/Migration1746000000CreateWishlistItemTable.php) | Schema |
| [src/Core/Content/WishlistItem/WishlistItemDefinition.php](src/Core/Content/WishlistItem/WishlistItemDefinition.php) | Field definitions |
| [src/Core/Content/WishlistItem/WishlistItemEntity.php](src/Core/Content/WishlistItem/WishlistItemEntity.php) | Entity class |
| [src/Core/Content/WishlistItem/WishlistItemCollection.php](src/Core/Content/WishlistItem/WishlistItemCollection.php) | Typed collection |
| [src/Resources/config/services.xml](src/Resources/config/services.xml) | Registers the definition |

## Three things this example gets right

**Versioned foreign keys.** `product` has a composite primary key `(id, version_id)`. A foreign key to it
needs both columns in SQL and a `ReferenceVersionField(ProductDefinition::class)` in the definition.
Pointing a plain `FkField` at `product.id` alone produces a schema that DAL validation rejects.

**Migration timestamp range.** Since SW 6.7 `getCreationTimestamp()` is validated to be between 1 and
2147483647. A larger value used to be clamped silently, which made migration order non-deterministic.

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

$items = $this->wishlistItemRepository->search($criteria, $context);
```

`productVersionId` does not need to be passed: DAL fills it with `Defaults::LIVE_VERSION`.

## Attribute-based alternative

Since SW 6.6.3 the same entity can be declared with PHP attributes instead of a definition class, which
removes the entity and collection boilerplate. See `references/advanced.md`. Note that the DAL attribute
classes were made `final` in 6.7, so they cannot be extended.
