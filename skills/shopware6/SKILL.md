---
name: shopware6
description: >-
  Shopware 6 development across plugins, apps and themes: DAL entities, migrations, custom fields and entity extensions, Storefront (Twig, SCSS, JS plugin system), Admin (Vue 3, Meteor Admin SDK), Admin API and Store API, cart processors, collectors and validators, payment and shipping handlers, rule builder conditions, CMS elements, App System manifests and webhooks, SEO URLs, mail templates, state machines, message queue, Elasticsearch, HTTP cache, PHPUnit and Jest testing, security and deployment. Use when the user asks to create a Shopware plugin, subscriber, custom entity or admin module, override a Storefront template, decorate a service, write a DAL migration, or handle any other Shopware 6 development task.
when_to_use: >-
  Trigger on any mention of: Shopware 6, sw6, Storefront (Twig/SCSS), Admin (Vue.js/Shopware Admin API), DAL (Data Abstraction Layer), App System, manifest.xml, services.xml, EntityRepository, EntityDefinition, EntityExtension, SubscriberInterface, SalesChannelContext, sw-context-token, sw-access-key, StaticEntityRepository, ProductBuilder, PaymentHandlerInterface, MessageBusInterface, Rule (shopware.rule.definition), CMS element, ElasticsearchHelper, HttpCache, CartProcessorInterface, CartValidatorInterface, CartDataCollectorInterface, PercentagePriceDefinition, AbsolutePriceDefinition, SeoUrlRouteInterface, StateMachineRegistry, ThemeInterface, theme.json, FilesystemOperator, RedisConnectionProvider, RateLimiter, EntityIndexer, InAppPurchase, CheckoutGateway, AbstractPaymentHandler, PaymentHandlerType, Meteor Component Library, mt-button/mt-card/mt-text-field, Pinia Shopware.Store, Vite admin build, storefront:dev-server, Twig UX components, manifest-3.0.xsd, migration from Shopware 6.6 to 6.7, or Shopware-specific CLI commands.
version: 0.3.0
---

# Shopware 6 Development

## Core Architecture

Shopware 6 is a PHP/Symfony platform. Clarify which layer is being targeted before generating code:

| Layer | Tech | Where |
|---|---|---|
| **Core / PHP** | PHP 8.2+, Symfony 7.4 (SW 6.7) / Symfony 7.0 (SW 6.6) | `src/` — Services, DAL, Events |
| **Storefront** | Twig 3, SCSS, Vanilla JS, Vite dev server (6.7.11+) | `src/Resources/views/storefront/` |
| **Admin** | Vue 3, Pinia, Meteor components (`mt-*`), Vite build | `src/Resources/app/administration/` |
| **App System** | manifest.xml (schema 3.0 in 6.7), App Scripts | External server or Twig scripts (no PHP needed) |

**Plugin vs App System:** Use Plugin for self-hosted installs needing direct PHP/DB access. Use App System for SaaS/multi-tenant or when targeting the Shopware Store.

### Which version is this?

Ask or infer the target version before generating code. The current line is **6.7.13.x**; 6.8 is planned
for 2027. 6.7 broke a lot of plugin-facing API, so code that is correct for 6.6 is often wrong for 6.7:

| Topic | SW 6.6 | SW 6.7 |
|---|---|---|
| Payment handler | `Synchronous`/`AsynchronousPaymentHandlerInterface` | `AbstractPaymentHandler` |
| Admin components | `sw-button`, `sw-card`, `sw-text-field` | `mt-button`, `mt-card`, `mt-text-field` |
| Admin state | Vuex `Shopware.State` (deprecated) | Pinia `Shopware.Store` only |
| Admin build | Webpack | Vite |
| `EntityExtension` | `getDefinitionClass()` | plus abstract `getEntityName()` |
| Plugin custom entities | `entities.xml` | removed, use `EntityDefinition` |
| `IdsCollection` | `Shopware\Core\Framework\Test\` | `Shopware\Core\Test\Stub\Framework\` |

Full list in **[references/migration-6.7.md](references/migration-6.7.md)**. When the version is unknown,
target 6.7 and mention what differs on 6.6.

---

## Plugin Structure

```
PluginName/
├── composer.json
├── src/
│   ├── PluginName.php          # Bootstrap class
│   ├── Resources/
│   │   ├── config/
│   │   │   └── services.xml    # Symfony DI container
│   │   ├── views/
│   │   │   └── storefront/     # Twig template overrides
│   │   └── app/
│   │       └── administration/ # Vue.js admin extensions
│   └── Migration/              # Database migrations
└── tests/
```

### Plugin Bootstrap

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName;

use Shopware\Core\Framework\Plugin;

class PluginName extends Plugin {}
```

Only extend `install()`, `activate()`, `deactivate()`, `uninstall()` when lifecycle actions are needed (e.g., creating payment methods, dropping tables on uninstall).

---

## Five Core Workflows

### 1. Register a Service (DI)

```xml
<!-- services.xml -->
<service id="VendorName\PluginName\Service\MyService">
    <argument type="service" id="product.repository"/>
</service>
```

Common tags: `kernel.event_subscriber`, `twig.extension`, `console.command`, `messenger.message_handler`, `shopware.entity.definition`, `shopware.entity.extension`, `shopware.rule.definition`, `shopware.payment.method.sync`, `shopware.payment.method.async`, `shopware.cms.element`.

### 2. Listen to Events (Subscriber)

```php
class ProductSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['product.written' => 'onProductWritten'];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getWriteResults() as $result) {
            $id = $result->getPrimaryKey();
        }
    }
}
```

### 3. DAL Read & Write

```php
// Read
$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('active', true));
$criteria->addAssociation('manufacturer');
$result = $this->productRepository->search($criteria, $context);

// Write
$this->productRepository->upsert([
    ['id' => $id, 'name' => 'New Name'],
], $context);
```

### 4. Override a Storefront Template

```twig
{# Mirrors original path under views/storefront/ #}
{% sw_extends '@Storefront/storefront/page/product-detail/index.html.twig' %}

{% block page_product_detail_content %}
    <div class="my-banner">Custom content</div>
    {{ parent() }}
{% endblock %}
```

### 5. Decorate a Service

```xml
<service id="VendorName\PluginName\Decorator\MyDecorator"
         decorates="original.service.id">
    <argument type="service"
              id="VendorName\PluginName\Decorator\MyDecorator.inner"/>
</service>
```

---

## Karpathy Principles — Clarify Before Coding

Surface these assumptions before generating code:

- **Version?** SW 6.5 vs 6.6 (API and Vue component differences exist)
- **Plugin or App System?** Plugin = PHP server; App = manifest.xml + external/no server
- **Layer?** PHP/Core, Storefront, Admin, or headless/Store API
- **Read or write?** Repository `search()` vs `upsert()`/`create()`/`update()`
- **Entity or extension?** New table vs extending existing entity

Write only the minimum code that solves the problem. Shopware's DI and event system handle most complexity.

---

## DAL Quick Reference

```php
// Criteria
$criteria->addFilter(new EqualsFilter('active', true));
$criteria->addFilter(new ContainsFilter('name', 'shirt'));
$criteria->addFilter(new RangeFilter('price', [RangeFilter::GTE => 10]));
$criteria->addAssociation('manufacturer');
$criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
$criteria->setLimit(25)->setOffset(0);

// Context
$context = Context::createDefaultContext();               // system
// OR inject SalesChannelContext from route / event

// IDs only (faster, no hydration)
$ids = $this->repo->searchIds($criteria, $context)->getIds();
```

---

## Admin Vue.js Quick Reference

```js
// Register module
Shopware.Module.register('my-module', {
    type: 'plugin',
    routes: { index: { component: 'my-module-index', path: 'index' } },
    navigation: [{ label: 'my-module.title', path: 'my.module.index', icon: 'default-shopping-paper-bag' }],
});

// Override existing component
Shopware.Component.override('sw-product-detail', {
    methods: {
        async saveProduct() {
            await this.$super('saveProduct'); // call original
        },
    },
});
```

---

## CLI Commands

```bash
bin/console plugin:install --activate PluginName
bin/console database:migrate --all PluginName
bin/console cache:clear
bin/console plugin:refresh
bin/build-administration.sh
bin/build-storefront.sh
bin/console theme:compile
php vendor/bin/phpunit --testsuite=unit
vendor/bin/phpstan analyse src --level=8
```

---

## Additional Resources

### Reference Files

Load these when working on specific areas:

- **[references/dal.md](references/dal.md)** — DAL: EntityDefinition, Criteria, Aggregations, custom fields, **Entity Extensions** (extend core entities)
- **[references/admin.md](references/admin.md)** — Admin: Vue modules, components, overrides, **naming conventions**, **ACL privileges**, **filter/inline edit**, search config
- **[references/storefront.md](references/storefront.md)** — Storefront: Twig inheritance, SCSS/theme variables, JavaScript plugins, controllers
- **[references/themes.md](references/themes.md)** — Themes: **theme.json** (config fields, colors, fonts, media), SCSS Bootstrap overrides, **theme inheritance**, ThemeInterface, CLI commands
- **[references/cart.md](references/cart.md)** — Cart: **CartDataCollector**, **CartProcessor**, **CartValidator** + custom errors, **discount line items**, price manipulation, Tax Provider
- **[references/seo-mail.md](references/seo-mail.md)** — SEO: **SeoUrlRoute**, sitemap URL provider; Mail: **custom mail templates** (migration + send); **Documents** (custom PDF types); **Order State Machine** (transitions, events)
- **[references/plugin-structure.md](references/plugin-structure.md)** — Full plugin anatomy: services.xml, lifecycle hooks, composer.json, console commands, scheduled tasks
- **[references/api.md](references/api.md)** — Admin API & Store API: CRUD, bulk, filters; **context token lifecycle**, **Cart/Checkout/Account Store API**, TypeScript client pattern
- **[references/testing.md](references/testing.md)** — PHPUnit unit/integration, **StaticEntityRepository**, **ProductBuilder**, Jest, Cypress, assertSame vs assertEquals
- **[references/app-system.md](references/app-system.md)** — App System: **manifest.xml**, **webhook HMAC verification**, **registration handshake**, **App Scripts** (Twig-based, no server)
- **[references/security.md](references/security.md)** — Security: route scopes, **CSRF protection**, input validation, authorization by customer, SQL injection prevention
- **[references/integrations.md](references/integrations.md)** — Integrations: **Payment Handler** (`AbstractPaymentHandler`), **shipping costs** (cart processor, not a calculator tag), **CMS Elements**, **Rule Builder** conditions, Flow Builder events
- **[references/performance.md](references/performance.md)** — Performance: **HTTP Cache** (tags, invalidation), **Message Queue** (async processing), **Elasticsearch/OpenSearch**, object cache
- **[references/devops.md](references/devops.md)** — DevOps: **structured logging**, PHPStan, php-cs-fixer, CI/CD, deployment, debugging, **media handling**, upgrade safety
- **[references/migration-6.7.md](references/migration-6.7.md)** — **6.6 to 6.7 migration**: breaking changes across Core/DAL, Admin, Storefront, cache, API, hosting, plus what 6.7.x added (Vite dev server, Twig UX components, MCP server)
- **[references/advanced.md](references/advanced.md)** — Advanced: **PHP Attributes entities** (SW 6.6.3+), **Flysystem** (public/private file storage), **Redis** (cache/queue), **Rate Limiter** (compiler pass + RateLimiter service), **Data Indexer**, **Field Inheritance** (variants), **In-App Purchases**

### Examples

Working code examples in `examples/`:

- **[examples/custom-entity/](examples/custom-entity/)** — Runnable plugin: Definition, Entity, Collection, Migration, versioned FK to `product`, uninstall cleanup
- **[examples/storefront-subscriber/](examples/storefront-subscriber/)** — Runnable plugin: page subscriber, sales channel repository, Twig override, snippets
