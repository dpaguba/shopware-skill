---
name: shopware6
description: >-
  Shopware 6 development for plugins, apps and themes, targeting 6.7 and flagging what differs on 6.6.
  Use when creating or changing a Shopware 6 plugin, app or theme: services.php DI config, event
  subscribers, service decoration, DAL entity definitions, migrations, custom fields and entity
  extensions; Storefront Twig overrides, SCSS and JS plugins; Admin modules with Vue 3, Pinia and
  Meteor mt-* components; Admin API and Store API calls; cart processors, collectors and validators,
  AbstractPaymentHandler, shipping, rule conditions, CMS elements and flows; manifest.xml, webhooks and
  App Scripts; SEO URLs, mail templates, documents, state machines, message queue, scheduled tasks,
  OpenSearch and HTTP cache; PHPUnit, Jest and Playwright tests; deployment; or upgrading from 6.6 to
  6.7 and preparing code for 6.8.
license: MIT
metadata:
  version: "0.4.0"
  checked-against: "shopware/shopware v6.7.15.1"
---

# Shopware 6 Development

## Core Architecture

Shopware 6 is a PHP/Symfony platform. Clarify which layer is being targeted before generating code:

| Layer | Tech | Where |
|---|---|---|
| **Core / PHP** | PHP 8.2+, Symfony 7.4 (SW 6.7) / Symfony 7.0 (SW 6.6) | `src/`: services, DAL, events |
| **Storefront** | Twig 3, SCSS, Vanilla JS, Vite dev server (6.7.11+) | `src/Resources/views/storefront/` |
| **Admin** | Vue 3, Pinia, Meteor components (`mt-*`), Vite build | `src/Resources/app/administration/` |
| **App System** | manifest.xml (schema 3.0 in 6.7), App Scripts | External server or Twig scripts (no PHP needed) |

**Plugin vs App System:** Use Plugin for self-hosted installs needing direct PHP/DB access. Use App System for SaaS/multi-tenant or when targeting the Shopware Store.

### Which version is this?

Ask or infer the target version before generating code. The current line is **6.7.15.x**; 6.8 is planned
for 2027. 6.7 broke a lot of plugin-facing API, so code that is correct for 6.6 is often wrong for 6.7:

| Topic | SW 6.6 | SW 6.7 |
|---|---|---|
| Payment handler | `Synchronous`/`AsynchronousPaymentHandlerInterface` | `AbstractPaymentHandler`, tag `shopware.payment.method` |
| Admin components | `sw-button`, `sw-card`, `sw-text-field` | `mt-button`, `mt-card`, `mt-text-field` (`sw-*` are deprecated wrappers until 6.8) |
| Admin state | Vuex `Shopware.State` | Pinia `Shopware.Store` (all core stores; `Shopware.State` deprecated, removed in 6.8) |
| Admin build | Webpack | Vite |
| `EntityExtension` | `getDefinitionClass()` (deprecated in 6.6.10) or `getEntityName()` | abstract `getEntityName()` only, `getDefinitionClass()` removed |
| Plugin custom entities | `entities.xml` | removed, use `EntityDefinition` |
| `IdsCollection` | `Shopware\Core\Framework\Test\` | `Shopware\Core\Test\Stub\Framework\` |
| DI config | `services.xml` | `services.php` (XML deprecated since 6.7.14, removed in 6.8) |

Full list in **[references/migration-6.7.md](references/migration-6.7.md)**. When the version is unknown,
target 6.7 and mention what differs on 6.6.

New 6.7 code should already avoid what 6.8 removes. The cases that come up most:

- DI and routes in `services.php` / `routes.php`, never XML
- read search results through `$result->getEntities()`; `EntitySearchResult` stops being a collection in 6.8
- `mt-empty-state` instead of `sw-empty-state`, `$t` instead of `$tc`
- native DOM APIs instead of the Storefront `DomAccess` helper

The full table is under "Prepare for 6.8 now" in `references/migration-6.7.md`.

---

## Plugin Structure

```
PluginName/
├── composer.json
├── src/
│   ├── PluginName.php          # Bootstrap class
│   ├── Resources/
│   │   ├── config/
│   │   │   ├── services.php    # Symfony DI container
│   │   │   └── config.xml      # Plugin settings form (stays XML)
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

```php
<?php declare(strict_types=1);
// src/Resources/config/services.php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\PluginName\Service\MyService;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(MyService::class)
        ->args([service('product.repository')]);
};
```

XML service config (`services.xml`) still loads on 6.7 but logs a deprecation since 6.7.14 and fails on
6.8. `services.php` works on 6.6 and 6.7, so use it for new plugins.

Common tags: `kernel.event_subscriber`, `twig.extension`, `console.command`, `messenger.message_handler`, `shopware.entity.definition`, `shopware.entity.extension`, `shopware.rule.definition`, `shopware.payment.method`, `shopware.cms.data_resolver`.

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

Register it with `->tag('kernel.event_subscriber')`.

### 3. DAL Read & Write

```php
// Read
$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('active', true));
$criteria->addAssociation('manufacturer');
$products = $this->productRepository->search($criteria, $context)->getEntities();

// Write
$this->productRepository->upsert([
    ['id' => $id, 'name' => 'New Name'],
], $context);
```

Iterate `getEntities()`, not the search result itself: in 6.8 `EntitySearchResult` is no longer iterable.

### 4. Override a Storefront Template

```twig
{# src/Resources/views/storefront/page/content/product-detail.html.twig, mirrors the core path #}
{% sw_extends '@Storefront/storefront/page/content/product-detail.html.twig' %}

{% block page_content %}
    <div class="my-banner">Custom content</div>
    {{ parent() }}
{% endblock %}
```

The product page renders through the CMS layout. `page/product-detail/index.html.twig` was removed in 6.6,
so an override of it is silently ignored.

### 5. Decorate a Service

```php
$services->set(MyDecorator::class)
    ->decorate(OriginalService::class)
    ->args([service('.inner')]);
```

`MyDecorator` extends the same abstract class as the original and delegates to the inner service for
everything it does not change.

---

## Clarify Before Coding

Surface these assumptions before generating code:

- **Version?** 6.6 or 6.7, and does it have to keep working on 6.8? (payment, Admin and DI differ)
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
$context = Context::createCLIContext();                   // system scope, CLI and scheduled tasks
// OR take Context / SalesChannelContext from the route, event or message
// (Context::createDefaultContext() is @internal, keep it to tests)

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
    navigation: [{ label: 'my-module.title', path: 'my.module.index', icon: 'regular-shopping-bag' }],
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

- **[references/dal.md](references/dal.md)**: DAL: EntityDefinition, Criteria, Aggregations, custom fields, **Entity Extensions** (extend core entities)
- **[references/admin.md](references/admin.md)**: Admin: Vue modules, components, overrides, **naming conventions**, **ACL privileges**, **filter/inline edit**, search config
- **[references/storefront.md](references/storefront.md)**: Storefront: Twig inheritance, SCSS/theme variables, JavaScript plugins, controllers
- **[references/themes.md](references/themes.md)**: Themes: **theme.json** (config fields, colors, fonts, media), SCSS Bootstrap overrides, **theme inheritance**, ThemeInterface, CLI commands
- **[references/cart.md](references/cart.md)**: Cart: **CartDataCollector**, **CartProcessor**, **CartValidator** + custom errors, **discount line items**, price manipulation, Tax Provider
- **[references/seo-mail.md](references/seo-mail.md)**: SEO: **SeoUrlRoute**, sitemap URL provider; Mail: **custom mail templates** (migration + send); **Documents** (custom PDF types, document generation v2); **Order State Machine** (transitions, events)
- **[references/plugin-structure.md](references/plugin-structure.md)**: Full plugin anatomy: services.php, lifecycle hooks, composer.json, console commands, scheduled tasks
- **[references/api.md](references/api.md)**: Admin API & Store API: CRUD, bulk, filters; **context token lifecycle**, **Cart/Checkout/Account Store API**, TypeScript client pattern
- **[references/testing.md](references/testing.md)**: PHPUnit unit/integration, **StaticEntityRepository**, **ProductBuilder**, Jest, E2E, assertSame vs assertEquals
- **[references/app-system.md](references/app-system.md)**: App System: **manifest.xml**, **webhook HMAC verification**, **registration handshake**, **App Scripts** (Twig-based, no server)
- **[references/security.md](references/security.md)**: Security: route scopes, **SameSite cookies instead of CSRF tokens** (tokens removed in 6.5), input validation, authorization by customer, SQL injection prevention
- **[references/integrations.md](references/integrations.md)**: Integrations: **Payment Handler** (`AbstractPaymentHandler`), **shipping costs** (cart processor, not a calculator tag), **CMS Elements**, **Rule Builder** conditions, Flow Builder events
- **[references/performance.md](references/performance.md)**: Performance: **HTTP Cache** (tags, invalidation), **Message Queue** (async processing), **Elasticsearch/OpenSearch**, object cache
- **[references/devops.md](references/devops.md)**: DevOps: **structured logging**, PHPStan, php-cs-fixer, CI/CD, deployment, debugging, **media handling**, upgrade safety
- **[references/migration-6.7.md](references/migration-6.7.md)**: **6.6 to 6.7 migration**: breaking changes across Core/DAL, Admin, Storefront, cache, API, hosting; what 6.7.x added (XML to PHP DI, Vite dev server, Twig UX components, MCP server, document generation v2, Admin SFCs); **what to change now for 6.8**
- **[references/advanced.md](references/advanced.md)**: Advanced: **PHP Attributes entities** (SW 6.6.3+), **Flysystem** (public/private file storage), **Redis** (cache/queue), **Rate Limiter** (compiler pass + RateLimiter service), **Data Indexer**, **Field Inheritance** (variants), **In-App Purchases**

### Examples

Working code examples in `examples/`:

- **[examples/custom-entity/](examples/custom-entity/)**: Runnable plugin: Definition, Entity, Collection, Migration, versioned FK to `product`, uninstall cleanup
- **[examples/storefront-subscriber/](examples/storefront-subscriber/)**: Runnable plugin: page subscriber, sales channel repository, Twig override, snippets
