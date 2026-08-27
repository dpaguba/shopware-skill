<?php declare(strict_types=1);

namespace VendorName\MyPlugin\Subscriber;

use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use VendorName\MyPlugin\Service\RelatedArticlesService;

class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RelatedArticlesService $relatedArticlesService,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();

        $articles = $this->relatedArticlesService->findRelated(
            $product->getId(),
            $event->getSalesChannelContext()
        );

        $event->getPage()->addExtension('relatedArticles', $articles);
    }
}
