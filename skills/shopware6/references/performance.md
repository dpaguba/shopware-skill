# Performance: HTTP Cache, Message Queue, Elasticsearch

---

## HTTP Cache

> **SW 6.7 cache rework.** Invalidation is now **delayed**: tags are collected and processed by a worker
> instead of being flushed inside the request, so a change is not visible in the cache the microsecond it
> is written. Store-API route caching was **removed** entirely, and header and footer are rendered through
> **ESI**, which means variables that used to be available in those templates are gone. Fine-grained
> caching was removed as well. Varnish setups now require the **XKeys** module.
>
> The `CACHE_REWORK` feature flag (default behaviour in 6.8) enables cache policies, drops the `logged-in` /
> `cart-filled` cache states, keeps only price-relevant rule ids in `sw-cache-hash`, and lets Store API GET
> routes marked `_httpCache` use the regular HTTP cache.


Shopware's HTTP cache (based on Symfony's `HttpCache`) eliminates PHP execution for cached pages.

### Enable Caching on Custom Routes

```php
use Shopware\Storefront\Controller\StorefrontController;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class MyController extends StorefrontController
{
    #[Route(
        path: '/my-cached-page',
        name: 'frontend.my-plugin.cached',
        methods: ['GET'],
        defaults: ['_httpCache' => ['maxAge' => 3600]] // s-maxage in seconds; `true` = default TTL / policy
    )]
    public function cachedPage(Request $request, SalesChannelContext $context): Response
    {
        return $this->renderStorefront('@MyPlugin/storefront/page/cached.html.twig');
    }
}
```

Set the TTL through the `_httpCache` route default. `CacheResponseSubscriber` overwrites `s-maxage` with the attribute value or the default TTL / policy, so `$response->setSharedMaxAge()` in the controller has no effect.

### Cache Invalidation via Tags

Tag the response while it is built. Shopware stores the tags with the cache entry and, with Varnish, sends them as `xkey` itself:

```php
use Shopware\Core\Framework\Adapter\Cache\Event\AddCacheTagEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class MyEntityPageLoader
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function load(string $id): void
    {
        // ... load data

        $this->eventDispatcher->dispatch(new AddCacheTagEvent('my-entity-' . $id));
    }
}
```

Invalidate from PHP (e.g., after an entity update). Core already invalidates `product-<id>` and other core tags on writes:

```php
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;

class MyEntityWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheInvalidator $cacheInvalidator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return ['my_entity.written' => 'onMyEntityWritten'];
    }

    public function onMyEntityWritten(EntityWrittenEvent $event): void
    {
        $tags = array_map(fn (string $id) => 'my-entity-' . $id, $event->getIds());

        // delayed by default; pass force: true to purge immediately
        $this->cacheInvalidator->invalidate($tags);
    }
}
```

### Cache Configuration

```env
# Enable HTTP cache
SHOPWARE_HTTP_CACHE_ENABLED=1
# Deprecated, removed in 6.8: use cache policies (below)
SHOPWARE_HTTP_DEFAULT_TTL=7200

# Disable cache for development
SHOPWARE_HTTP_CACHE_ENABLED=0
```

Reverse proxy (Varnish with XKey) is configured in YAML:

```yaml
# config/packages/shopware.yaml
shopware:
    http_cache:
        reverse_proxy:
            enabled: true
            hosts: ['http://varnish']
            max_parallel_invalidations: 3
```

Cache policies (active with `CACHE_REWORK` or `v6.8.0.0`) replace `SHOPWARE_HTTP_DEFAULT_TTL`, `shopware.http_cache.stale_while_revalidate` and `stale_if_error`. Policy headers also reach browsers, which cannot be purged by tag:

```yaml
shopware:
    http_cache:
        policies:
            my_cacheable:
                headers:
                    cache_control:
                        public: true
                        s_maxage: 7200
                        stale_while_revalidate: 120
                        stale_if_error: 360
                    no_vary_search: 'key-order'   # 6.7.15+, omit the key to send no header
        default_policies:
            storefront:
                cacheable: my_cacheable
        route_policies:
            'frontend.my-plugin.cached': my_cacheable
```

Never list content-changing query parameters (`p`, `order`, `search`, filters) in `no_vary_search` `params`. Since 6.7.15 the `sw-include-seo-urls` header is part of `HttpCacheVariantHeaders::HEADERS` (emitted in `Vary`, folded into the cache key); custom cache keys should include it.

---

## Message Queue (Async Processing)

Use the message queue to offload heavy operations from HTTP request cycles.

### Message Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\MessageQueue;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

// AsyncMessageInterface routes to the `async` transport; without it the message is handled synchronously
class SyncProductMessage implements AsyncMessageInterface
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

        // Returns immediately, processing happens in background
        $this->messageBus->dispatch(new SyncProductMessage($productId, ''));

        return new JsonResponse(['queued' => true]);
    }
}
```

### Register Handler in services.php

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\PluginName\MessageQueue\SyncProductMessageHandler;
use VendorName\PluginName\Service\ExternalApiService;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(SyncProductMessageHandler::class)
        ->args([
            service(ExternalApiService::class),
            service('logger'),
        ])
        ->tag('messenger.message_handler');
};
```

### Run Workers

```bash
# Consume all Shopware transports. List `webhook` first: required with WEBHOOKS_REWORK and in 6.8,
# harmless before (it forwards to async)
bin/console messenger:consume webhook async low_priority --time-limit=300 --memory-limit=512M

# Scheduled tasks
bin/console scheduled-task:run

# Retry messages from the `failed` transport
bin/console messenger:failed:retry
```

With CLI workers in production, disable the admin worker: `shopware.admin_worker.enable_admin_worker: false`. If you override `shopware.admin_worker.transports`, keep `webhook` first (default `["webhook", "async", "low_priority"]`).

### Low Priority Queue

For non-urgent background jobs, implement `LowPriorityMessageInterface`. Core already defines the `low_priority` transport (`MESSENGER_TRANSPORT_LOW_PRIORITY_DSN`) and routes the interface to it:

```php
use Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface;

class LowPriorityMessage implements LowPriorityMessageInterface
{
    public function __construct(public readonly string $id) {}
}
```

Explicit routing still works for classes you cannot change:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            'VendorName\PluginName\MessageQueue\SyncProductMessage': async
```

---

## Elasticsearch / OpenSearch

For shops with 10,000+ products. ES replaces MySQL for product search and listing queries.

### Environment Configuration

```env
OPENSEARCH_URL="http://elasticsearch:9200"
SHOPWARE_ES_ENABLED=1
SHOPWARE_ES_INDEXING_ENABLED=1
SHOPWARE_ES_INDEXING_BATCH_SIZE=100
SHOPWARE_ES_INDEX_PREFIX=shopware
# Throw exception instead of falling back to MySQL on ES failure
SHOPWARE_ES_THROW_EXCEPTION=1
# Administration search (separate cluster config)
ADMIN_OPENSEARCH_URL="http://elasticsearch:9200"
SHOPWARE_ADMIN_ES_ENABLED=1
```

### Re-index After Configuration

```bash
# Trigger full reindex (queued; add --no-queue to index synchronously)
bin/console es:index

# Indexing messages run on the async transport
bin/console messenger:consume async

# Check index status
bin/console es:status
```

Since 6.7.15, `system:install` creates empty storefront indices and aliases when ES is enabled and reachable, so search no longer fails with `index_not_found_exception` before the first `es:index`. Since 6.7.14, a mapping update that needs a new analyzer schedules a reindex into a fresh index, and multi-word searches get a phrase boost (`elasticsearch.search.boost.*`, may shift scores against `core.search.minScore`).

### Check ES Availability Before Custom Queries

```php
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;

class MySearchService
{
    public function __construct(
        private readonly ElasticsearchHelper $elasticsearchHelper,
        private readonly ProductDefinition $productDefinition,
        private readonly EntityRepository $productRepository,
    ) {}

    public function search(string $term, Context $context): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->setTerm($term);

        // Opt in: without this state the DAL always queries MySQL.
        // The product listing/search/suggest routes set it themselves.
        $criteria->addState(Criteria::STATE_ELASTICSEARCH_AWARE);

        // true = this search will be answered by ES (enabled, entity indexed, criteria ES-aware)
        $usesEs = $this->elasticsearchHelper->allowSearch($this->productDefinition, $context, $criteria);

        return $this->productRepository->search($criteria, $context);
    }
}
```

### Extend the Product Index

Decorate `ElasticsearchProductDefinition`; a second `shopware.es.definition` for `product` would conflict with it:

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Elasticsearch;

use OpenSearchDSL\BuilderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;

class MyProductEsDefinition extends AbstractElasticsearchDefinition
{
    public function __construct(
        private readonly AbstractElasticsearchDefinition $decorated,
    ) {}

    public function getEntityDefinition(): EntityDefinition
    {
        return $this->decorated->getEntityDefinition();
    }

    public function buildTermQuery(Context $context, Criteria $criteria): BuilderInterface
    {
        return $this->decorated->buildTermQuery($context, $criteria);
    }

    public function getMapping(Context $context): array
    {
        $mapping = $this->decorated->getMapping($context);

        $mapping['properties']['my_plugin_score'] = [
            'type' => 'float',
        ];

        return $mapping;
    }

    public function fetch(array $ids, Context $context): array
    {
        $documents = $this->decorated->fetch($ids, $context);

        foreach ($documents as $id => &$doc) {  // keyed by product id
            $doc['my_plugin_score'] = $this->calculateScore($id);
        }

        return $documents;
    }
}
```

Register as decorator (the `shopware.es.definition` tag moves to the decorator):

```php
use Shopware\Elasticsearch\Product\ElasticsearchProductDefinition;
use VendorName\PluginName\Elasticsearch\MyProductEsDefinition;

$services->set(MyProductEsDefinition::class)
    ->decorate(ElasticsearchProductDefinition::class)
    ->args([service('.inner')]);
```

Run `bin/console es:index` after changing the mapping.

---

## DAL Performance Tips

```php
// Load only needed fields (reduces data transfer); resetFields() (6.7.14+) drops the allowlist again
$criteria->addFields(['id', 'name', 'price', 'stock']);

// Avoid N+1: use addAssociation instead of loading in loop
foreach ($products->getEntities() as $product) {
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

Iterate and count via `$result->getEntities()`: in 6.8 `EntitySearchResult` is no longer an `EntityCollection` (no `foreach ($result ...)`, `count($result)`, `first()`).

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
            $item->tag('my-plugin-data');   // cache.object is tag-aware

            return $this->fetchFromExternalApi($key);
        });
    }

    public function invalidate(string $key): void
    {
        $this->cache->delete('my_plugin.' . $key);
    }
}
```

Inject `cache.object` (tag-aware, cleared by `CacheInvalidator::invalidate(['my-plugin-data'])`) or `cache.app`:

```php
use VendorName\PluginName\Service\MyService;

$services->set(MyService::class)
    ->args([service('cache.object')]);
```
