<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\WishlistPlugin\Core\Content\WishlistItem\WishlistItemDefinition;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(WishlistItemDefinition::class)
        ->tag('shopware.entity.definition');
};
