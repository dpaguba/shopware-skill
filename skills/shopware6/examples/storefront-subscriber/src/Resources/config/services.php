<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\MyPlugin\Service\RelatedArticlesService;
use VendorName\MyPlugin\Subscriber\ProductPageSubscriber;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RelatedArticlesService::class)
        ->args([service('sales_channel.product.repository')]);

    $services->set(ProductPageSubscriber::class)
        ->args([service(RelatedArticlesService::class)])
        ->tag('kernel.event_subscriber');
};
