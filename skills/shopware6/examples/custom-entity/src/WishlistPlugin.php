<?php declare(strict_types=1);

namespace VendorName\WishlistPlugin;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class WishlistPlugin extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Since SW 6.7 the plugin migrations are removed from the migration table before
        // uninstall() runs, so dropping the table here lets a reinstall replay the migration.
        $this->container->get(Connection::class)
            ->executeStatement('DROP TABLE IF EXISTS `wishlist_item`');
    }
}
