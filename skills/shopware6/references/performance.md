# Performance — HTTP Cache, Message Queue, Elasticsearch

---

## HTTP Cache

> **SW 6.7 cache rework.** Invalidation is now **delayed**: tags are collected and processed by a worker
> instead of being flushed inside the request, so a change is not visible in the cache the microsecond it
> is written. Store-API route caching was **removed** entirely, and header and footer are rendered through
> **ESI**, which means variables that used to be available in those templates are gone. Fine-grained
> caching was removed as well. Varnish setups now require the **XKeys** module.


Shopware's HTTP cache (based on Symfony's `HttpCache`) eliminates PHP execution for cached pages.

### Enable Caching on Custom Routes

```php
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Framework\Cache\Annotation\HttpCache;

#[Route(defaults: ['_routeScope' => ['storefront'], '_httpCache' => true])]
class MyController extends StorefrontController
{
    #[Route(
        path: '/my-cached-page',
        name: 'frontend.my-plugin.cached',
        methods: ['GET'],
        defaults: ['_httpCache' => true]
    )]
    public function cachedPage(Request $request, SalesChannelContext $context): Response
    {
        $response = $this->renderStorefront('@MyPlugin/storefront/page/cached.html.twig');

        $response->setSharedMaxAge(3600); // 1 hour

        return $response;
    }
}
```

### Cache Invalidation via Tags

Tag responses so they can be invalidated when related data changes:

```php
use Shopware\Core\Framework\Adapter\Cache\CacheIdLoader;
use Shopware\Storefront\Framework\Cache\CacheResponseSubscriber;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class ProductCachingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ResponseEvent::class => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        $productIds = ['abc123', 'def456'];
        $tags = array_map(fn($id) => 'product-' . $id, $productIds);

        $response->headers->set(
            'xkey',
            implode(' ', $tags)
        );
    }
}
```

Invalidate from PHP (e.g., after product update):

```php
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;

class ProductWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheInvalidator $cacheInvalidator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return ['product.written' => 'onProductWritten'];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        $ids = array_map(
            fn($result) => 'product-' . $result->getPrimaryKey(),
            $event->getWriteResults()
        );

        $this->cacheInvalidator->invalidate($ids);
    }
}
```

### Cache Configuration (.env)

```env
# Enable HTTP cache
SHOPWARE_HTTP_CACHE_ENABLED=1
SHOPWARE_HTTP_DEFAULT_TTL=7200

# Varnish / Reverse proxy
SHOPWARE_HTTP_CACHE_HOSTS=varnish:80

# Disable cache for development
SHOPWARE_HTTP_CACHE_ENABLED=0
```

---

## Message Queue (Async Processing)

Use the message queue to offload heavy operations from HTTP request cycles.

### Message Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\MessageQueue;

class SyncProductMessage
{
    public function __construct(
        public readonly string $productId,
        public readonly string $contextToken,
    ) {}
}
```

### Message Handler

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\MessageQueue;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncProductMessageHandler
{
    public function __construct(
        private readonly ExternalApiService $externalApi,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncProductMessage $message): void
    {
        try {
            $this->externalApi->syncProduct($message->productId);

            $this->logger->info('Product synced', ['productId' => $message->productId]);
        } catch (\Throwable $e) {
            $this->logger->error('Product sync failed', [
                'productId' => $message->productId,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // Re-throw to trigger retry/dead-letter
        }
    }
}
```

### Dispatch from Controller or Subscriber

```php
use Symfony\Component\Messenger\MessageBusInterface;

class MyController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function syncAction(Request $request): JsonResponse
    {
        $productId = $request->request->get('productId');

        // Returns immediately — processing happens in background
        $this->messageBus->dispatch(new SyncProductMessage($productId, ''));

        return new JsonResponse(['queued' => true]);
    }
}
```

### Register Handler in services.xml

```xml
<service id="VendorName\PluginName\MessageQueue\SyncProductMessageHandler">
    <argument type="service" id="VendorName\PluginName\Service\ExternalApiService"/>
    <argument type="service" id="logger"/>
    <tag name="messenger.message_handler"/>
</service>
```

### Run Workers

```bash
# Consume all queues
bin/console messenger:consume async

# Consume specific queue with time limit
bin/console messenger:consume async --time-limit=300

# With failure transport
bin/console messenger:consume async failed --limit=50
```

### Low Priority Queue

For non-urgent background jobs, route to a separate low-priority transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
            low_priority:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: low_priority

        routing:
            'VendorName\PluginName\MessageQueue\SyncProductMessage': async
            'VendorName\PluginName\MessageQueue\LowPriorityMessage': low_priority
```

---

## Elasticsearch / OpenSearch

For shops with 10,000+ products. ES replaces MySQL for product search and listing queries.

### Environment Configuration

```env
SHOPWARE_ES_ENABLED=1
SHOPWARE_ES_HOSTS="elasticsearch:9200"
SHOPWARE_ES_INDEXING_BATCH_SIZE=100
SHOPWARE_ES_INDEX_PREFIX=shopware
# Throw exception instead of falling back to MySQL on ES failure
SHOPWARE_ES_THROW_EXCEPTION=1
```

### Re-index After Configuration

```bash
# Trigger full reindex
bin/console es:index

# Run the indexing worker
bin/console messenger:consume elasticsearch

# Check index status
bin/console es:status
```

### Check ES Availability Before Custom Queries

```php
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;

class MySearchService
{
    public function __construct(
        private readonly ElasticsearchHelper $elasticsearchHelper,
        private readonly EntityRepository $productRepository,
    ) {}

    public function search(string $term, Context $context): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->setTerm($term);

        // The product listing/search routes use ES automatically when enabled
        // For custom queries, check availability first
        if (!$this->elasticsearchHelper->allowSearch(ProductDefinition::class, $context, $criteria)) {
            // ES not available — fall back to DAL/MySQL
            return $this->productRepository->search($criteria, $context);
        }

        // ES handles it via AbstractProductSearchRoute / AbstractProductListingRoute
        return $this->productRepository->search($criteria, $context);
    }
}
```

### Custom ES Definition (extend product index)

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Elasticsearch;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use OpenSearch\Client;

class MyPluginProductExtension extends AbstractElasticsearchDefinition
{
    public function __construct(
        private readonly ProductDefinition $productDefinition,
    ) {}

    public function getEntityDefinition(): ProductDefinition
    {
        return $this->productDefinition;
    }

    public function getMapping(Context $context): array
    {
        $mapping = parent::getMapping($context);

        $mapping['properties']['my_plugin_score'] = [
            'type' => 'float',
        ];

        return $mapping;
    }

    public function fetch(array $ids, Context $context): array
    {
        $documents = parent::fetch($ids, $context);

        foreach ($documents as &$doc) {
            $doc['my_plugin_score'] = $this->calculateScore($doc['id']);
        }

        return $documents;
    }
}
```

Register:

```xml
<service id="VendorName\PluginName\Elasticsearch\MyPluginProductExtension">
    <argument type="service" id="Shopware\Core\Content\Product\ProductDefinition"/>
    <tag name="shopware.es.definition"/>
</service>
```

---

## DAL Performance Tips

```php
// Load only needed fields (reduces data transfer)
$criteria->addFields(['id', 'name', 'price', 'stock']);

// Avoid N+1: use addAssociation instead of loading in loop
foreach ($products as $product) {
    $manufacturer = $product->getManufacturer(); // triggers N queries
}

$criteria->addAssociation('manufacturer');
$products = $this->productRepository->search($criteria, $context);

// Use searchIds when you only need IDs
$idResult = $this->productRepository->searchIds($criteria, $context);
$ids = $idResult->getIds(); // no entity hydration overhead

$criteria->setLimit(100);
$criteria->setOffset($page * 100);

// Use aggregations instead of loading all entities just to count
$criteria->addAggregation(new CountAggregation('count', 'id'));
$result = $this->productRepository->search($criteria, $context);
$count = $result->getAggregations()->get('count')->getCount();
```

---

## Object Cache (Symfony Cache)

```php
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MyService
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getExpensiveData(string $key): array
    {
        return $this->cache->get('my_plugin.' . $key, function (ItemInterface $item): array {
            $item->expiresAfter(3600); // 1 hour

            return $this->fetchFromExternalApi($key);
        });
    }

    public function invalidate(string $key): void
    {
        $this->cache->delete('my_plugin.' . $key);
    }
}
```

Inject `cache.object_cache` or `cache.app` in services.xml:

```xml
<service id="VendorName\PluginName\Service\MyService">
    <argument type="service" id="cache.object_cache"/>
</service>
```
