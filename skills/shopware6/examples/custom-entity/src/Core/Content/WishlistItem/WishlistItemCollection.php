<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin\Core\Content\WishlistItem;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<WishlistItemEntity>
 */
class WishlistItemCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return WishlistItemEntity::class;
    }
}
