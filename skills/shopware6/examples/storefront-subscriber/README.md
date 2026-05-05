# Example: Storefront Page Subscriber

Inject custom data into the product detail page and display it in Twig.

## Goal

Show a "related articles" box on product pages, loaded from a custom service.

## Files

```
src/
├── Subscriber/
│   └── ProductPageSubscriber.php
├── Service/
│   └── RelatedArticlesService.php
└── Resources/
    ├── config/services.xml
    └── views/storefront/page/product-detail/
        └── index.html.twig
```

## 1. Subscriber

```php
<?php declare(strict_types=1);

namespace VendorName\MyPlugin\Subscriber;

use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use VendorName\MyPlugin\Service\RelatedArticlesService;

class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RelatedArticlesService $relatedArticlesService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product  = $event->getPage()->getProduct();
        $context  = $event->getSalesChannelContext();

        $articles = $this->relatedArticlesService->findRelated($product->getId(), $context);

        $event->getPage()->addExtension('relatedArticles', $articles);
    }
}
```

## 2. Service

```php
<?php declare(strict_types=1);

namespace VendorName\MyPlugin\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RelatedArticlesService
{
    public function __construct(
        private readonly EntityRepository $productRepository,
    ) {}

    public function findRelated(string $productId, SalesChannelContext $context): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(4);
        // In real code: add relevance-based filter here

        return $this->productRepository->search($criteria, $context->getContext());
    }
}
```

## 3. services.xml

```xml
<service id="VendorName\MyPlugin\Subscriber\ProductPageSubscriber">
    <argument type="service" id="VendorName\MyPlugin\Service\RelatedArticlesService"/>
    <tag name="kernel.event_subscriber"/>
</service>

<service id="VendorName\MyPlugin\Service\RelatedArticlesService">
    <argument type="service" id="sales_channel.product.repository"/>
</service>
```

## 4. Twig Template Override

```twig
{# src/Resources/views/storefront/page/product-detail/index.html.twig #}
{% sw_extends '@Storefront/storefront/page/product-detail/index.html.twig' %}

{% block page_product_detail %}
    {{ parent() }}

    {% if page.extensions.relatedArticles is not empty %}
    <div class="container">
        <h2 class="h4 mt-4 mb-3">Related Articles</h2>
        <div class="row">
            {% for product in page.extensions.relatedArticles %}
            <div class="col-6 col-md-3 mb-3">
                <a href="{{ seoUrl('frontend.detail.page', { productId: product.id }) }}">
                    {% if product.cover.media %}
                        <img src="{{ product.cover.media.url }}"
                             alt="{{ product.name }}"
                             class="img-fluid">
                    {% endif %}
                    <p class="mt-2">{{ product.name }}</p>
                </a>
            </div>
            {% endfor %}
        </div>
    </div>
    {% endif %}
{% endblock %}
```
