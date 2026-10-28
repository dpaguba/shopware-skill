# Shopware 6.7: Breaking Changes and New Capabilities

Reference for code written against 6.5/6.6 that must run on 6.7, for the features 6.7 added, and for
what to change now so the code survives 6.8. Checked against **6.7.15.1**. The next major, 6.8, is
planned for **2027**; Shopware has not published a month. 6.6 stays in extended support until 6.8 ships.

---

## Platform requirements

| | SW 6.6 | SW 6.7 |
|---|---|---|
| PHP | 8.2, 8.3 | 8.2 – 8.5 |
| Symfony | 7.0 | 7.4 |
| Doctrine DBAL | 3.x | 4.x |
| PHPUnit | 9/10 | 11.x |
| Admin build | Webpack | **Vite** |
| Admin state | Vuex | **Pinia** (`Shopware.State` deprecated, removed in 6.8) |
| Admin components | `sw-*` | **Meteor `mt-*`** (`sw-*` wrappers deprecated) |
| App manifest schema | `manifest-2.0.xsd` | `manifest-3.0.xsd` |
| DI and route config | XML or PHP | **PHP** (`services.php`, `routes.php`); XML deprecated since 6.7.14 |

---

## DI configuration: XML to PHP (6.7.14)

Symfony 8 drops XML configuration, so Shopware deprecated XML service, route and package config for
plugins and bundles in 6.7.14 and stops loading it in 6.8. A plugin that still ships `services.xml` fails
with an exception on 6.8. Every loaded XML file already logs a deprecation naming the file.

| Deprecated | Replacement |
|---|---|
| `Resources/config/services.xml`, `services_test.xml` | `services.php` / `services_test.php` with `ContainerConfigurator` |
| `Resources/config/routes.xml`, `routes_<env>.xml`, `routes/*.xml` | `routes.php` with `RoutingConfigurator` |
| `Resources/config/packages/**/*.xml` | YAML or PHP |

Not affected: `config.xml`, `custom-fields.xml`, app `manifest.xml`, `services.yaml`.

```php
<?php declare(strict_types=1);
// src/Resources/config/services.php

use Swag\Example\Service\MyService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(MyService::class)
        ->args([service('product.repository')])
        ->tag('kernel.event_subscriber');
};
```

Service ids, arguments and tags stay the same, only the notation changes. The plugin loader has read PHP
config for years, so `services.php` is safe for plugins that also support 6.6. Both formats can coexist
while you migrate.

---

## Core and DAL

| Change | Action |
|---|---|
| `EntityExtension::getEntityName()` is abstract, `getDefinitionClass()` removed | Implement `getEntityName()`, return e.g. `'product'`. `extendFields()` accepts only associations, FK/reference version fields and `Runtime` fields; a plain scalar field throws `FRAMEWORK__WRONG_FIELD_TYPE_FOR_EXTENSION` |
| Non-standard foreign keys on versioned tables | MySQL 8.4 rejects an FK on `product(id)` alone; reference `(id, version_id)` and run `bin/console dal:validate`. Use `MigrationStep::executeDdlStatement()` for raw DDL (6.7.14) |
| Custom Entities for plugins removed | `Resources/config/entities.xml` no longer works. Use `EntityDefinition` or attribute-based entities |
| `MigrationStep::getCreationTimestamp()` validated | Must be 1 … 2147483647 |
| Plugin migrations removed before `uninstall()` | Clean up your tables in `uninstall()`; a reinstall replays the migrations |
| Many-to-many mapping definitions | A mapping definition without foreign key fields now throws |
| `ScheduledTaskHandler` constructor | Second argument `Psr\Log\LoggerInterface` is required |
| DAL attribute classes are `final` | Cannot be extended any more |
| `EntityDefinition` constructor deprecated (6.7.2) | Do not call it directly |
| `Entity::get()` / `__get()` exceptions | Now `DataAbstractionLayerException` / `PropertyNotFoundException` |
| Native property types | Core classes declare native types; overriding classes must match |
| Notification classes moved | `Shopware\Core\Framework\Notification\*` instead of the admin bundle |
| `product.states` | Replaced by `product.type` (physical / digital) |

---

## Payment

`AbstractPaymentHandler` replaces the five handler interfaces. See `references/integrations.md` for the
full class. Key points:

- one tag: `shopware.payment.method`, no `.sync` / `.async`
- `supports(PaymentHandlerType, string $paymentMethodId, Context)` gates `refund` and `recurring`
- `pay()` returns `?RedirectResponse`; returning `null` means direct capture and `finalize()` is skipped
- the handler moves the transaction state itself (`OrderTransactionStateHandler::paid()`, `process()`,
  `authorize()`); Shopware only sets `fail` on an exception, or `cancel` when `finalize()` throws
  `PaymentException::customerCanceled()`
- handlers get `orderTransactionId`, `Request` and `Context`, no `SalesChannelContext`
- `capture` of prepared payments removed, use `pay`
- `technicalName` is required for payment **and** shipping methods
- customer default payment method removed; the last used method wins

---

## Administration

The basic components moved to Meteor: `sw-button`, `sw-card`, `sw-icon`, `sw-text-field`,
`sw-textarea-field`, `sw-number-field`, `sw-switch-field`, `sw-checkbox-field`, `sw-select-field`,
`sw-email-field`, `sw-url-field`, `sw-password-field`, `sw-datepicker`, `sw-colorpicker`,
`sw-progress-bar`, `sw-tabs`, `sw-popover`, `sw-skeleton-bar` and others. In 6.7 the old names still
resolve as deprecated wrappers: most render the `mt-*` component (the `deprecated` prop brings back the old
one), `sw-tabs` and `sw-popover` keep the legacy rendering unless opted in. The wrappers go in 6.8
(`sw-tabs` in 6.9). Write `mt-*` in new code; the migration map is in `references/admin.md`.

Other admin changes:

- `webpack.config.js` → `vite.config.mts`; ship separate plugin builds for 6.6 and 6.7
- all admin components are async now
- Vue 2 compatibility layer removed
- Vuex helpers renamed (`mapState` → `mapVuexState`); `vue-i18n` v10 removed the standalone `tc`
- `sw-dashboard-statistics` and its component sections and data sets removed
- `isEmailUsed` → `isEmailAlreadyInUse`
- `mt-tooltip` renders plain strings and HTML only, no Vue components inside

---

## Cache and Storefront

| Change | Impact |
|---|---|
| Delayed cache invalidation | Invalidation is queued, not immediate |
| Store-API route caching removed | Do not rely on route-level caching for Store API |
| ESI for header and footer | Variables previously available in header/footer templates were removed |
| Fine-grained caching removed | Configuration keys for it no longer apply |
| `ThemeFileImporterInterface` / `ThemeFileImporter` removed | Use the current theme compilation API |
| `StorefrontController::setTwig()` removed | Twig comes from the container |
| `sw-skin-alert` mixin, `alert-has-icon`, `alert-content` removed | Restyle custom alerts |
| NPM `query-string` removed | Use `URLSearchParams` |

**Accessibility rework.** Core templates changed markup: cart and order line items became `<ul>/<li>`,
pagination uses anchor links instead of radio inputs, modal triggers are `<button>` not `<a>`,
`{% sw_icon %}` is `aria-hidden="true"` by default, the base font size changed, and language/currency
dropdown items became buttons. Any theme that overrides those blocks needs re-checking.

---

## API and hosting

- `/api/oauth/authorize` removed in 6.7.0; non spec-compliant `/api/oauth/token` requests rejected.
  6.7.15 brings `GET /api/oauth/authorize` back for the authorization code grant with PKCE, for registered
  public clients such as CLI tools (browser login with a consent page in the Administration)
- since 6.7.14 many Admin API `_action` endpoints that only checked authentication enforce ACL privileges
  (number ranges, order recalculation, message queue, SEO, media, template rendering, `trigger-event`).
  Integrations without the privilege get `403 FRAMEWORK__MISSING_PRIVILEGE_ERROR`
- since 6.7.14.1 webhooks no longer receive password recovery and mail events, session tokens or opt-in
  links
- RSA JWT secrets removed
- Varnish requires the **XKeys** module
- Redis configuration keys changed with the reworked connection handling
- the Service bundle must be enabled explicitly
- Elasticsearch/OpenSearch shards and replicas come from the search server; OpenSearch 3.x supported from 6.7.3.1
- `SQL_SET_DEFAULT_SESSION_VARIABLE` has no effect

---

## New in 6.7.x worth using

### Storefront dev server (6.7.11)

```bash
composer storefront:dev-server
```

Vite based, no proxy needed, live updates for SCSS and JS. `composer watch:storefront` is deprecated.

### Twig UX component system (6.7.11)

Reusable storefront components with their own SCSS and JS, usable from existing templates. This is the
foundation of the upcoming content system.

### Theme config as CSS custom properties (6.7.11)

Every theme config field without `scss: false` is exposed as a native custom property:

```css
.btn-primary { background: var(--sw-color-brand-primary); }
```

### Single file references in theme.json (6.7.11)

```json
{
  "style": ["@MyTheme/app/storefront/src/scss/overrides.scss"],
  "script": ["@MyPlugin/app/storefront/dist/storefront/my-plugin.js"]
}
```

### Global JS event bus (6.7.11)

```js
window.Shopware.emit('Filter:Change', { foo: 'bar' });
window.Shopware.on('Filter:Change', ({ foo }) => { /* ... */ });
```

### MCP server (experimental)

Endpoints `/api/_mcp` and `/store-api/_mcp`, available whenever `symfony/mcp-bundle` is installed. The
`MCP_SERVER` feature flag was removed in 6.7.14, the endpoints are always on. ACL permissions and the MCP
allowlist are respected. Tools are grouped into toolsets; since 6.7.15 a client can name them on connect
(`/api/_mcp?toolsets=order,media` or `?toolsets=all`) instead of enabling them with the
`shopware-toolset-enable` tool.

Plugins register tools, prompts and resources with the tags `shopware.mcp.tool`, `shopware.mcp.prompt`,
`shopware.mcp.resource` (Admin API) and `shopware.store_api_mcp.*` (Store API). 6.7.15 moved to
`symfony/mcp-bundle` 0.13 and split the service ids per server (`mcp.server.admin`,
`mcp.server.store_api`); the tags did not change. `bin/console debug:mcp` lists what is registered.
Internals stay `@experimental` until 6.8.

### Document generation v2 (6.7.15, opt-in)

One generation call renders several formats of one document (HTML, PDF, ZUGFeRD XML, PDF with embedded
ZUGFeRD); ZUGFeRD is no longer a document type of its own. Behind the `DOCUMENT_GENERATION_REWORK` flag.
Timeline: opt-in in 6.7, default in 6.8 (opt-out), legacy pipeline and flag removed in 6.9. Plugins
register types, data providers and renderers with the tags `shopware.document_v2.type`,
`shopware.document_v2.provider` and `shopware.document_v2.renderer`; the legacy `document.renderer` tag
and decorators of the legacy `DocumentGenerator` are never called by v2. New business events
`document.generation.completed` and `document.generation.deleted` work for both pipelines and are
available in Flow Builder. Apps get a `<documents>` manifest block. Details in `references/seo-mail.md`.

### Vue Single File Components in the Administration (6.7.14)

Admin extensions can be written as `.vue` files. `sw-my-component.vue` declares a component,
`sw-my-component.override.vue` overrides one; a build-time transform maps both onto the Composition API
extension system. The base declares its extension surface with `swDefinePublic({ ... })`, the override
reads it with `swDefineOverride({ ... })`. Do not call `Shopware.Component.createExtendableSetup()` or
`overrideComponentSetup()` directly: they become private in 6.8. Template-based components registered
through `Shopware.Component.register()` keep working.

### Smaller additions

| Version | Feature |
|---|---|
| 6.7.1 | primary delivery and primary transaction on `order`; measurement units in Store API and a Twig conversion filter; new Admin API request headers |
| 6.7.2 | custom route names for Storefront controllers; `CheckoutPermissions::SKIP_CART_PERSISTENCE`; App System custom field sets for `unit` and `newsletter_recipient` |
| 6.7.3 | `NavigationRoute` caching; `activeRoute` instead of controller variables; customer address field length changes |
| 6.7.4 | plugin config default values; Vimeo and YouTube cookie consent separated |
| 6.7.12 | opt-in dedicated `webhook` Messenger transport |
| 6.7.13 | Storefront form validation messages use Shopware snippets; `LineItemPurchasePriceRule` uses `type` instead of `isNet`; declarative custom fields in `Resources/config/custom-fields.xml` for plugins and apps, inline `<custom-fields>` in `manifest.xml` deprecated |
| 6.7.14 | XML DI config deprecated (see above); `Criteria::resetFields()` / `resetExcludedFields()`; opt-in TypeScript and ESLint config for Admin extensions (experimental); `PluginManager.override()` works for async Storefront plugins; `theme:create --full`; app script hook `cookie-group-collect`; order state history records where a change came from; product export templates encode media URLs automatically (remove manual `url_encode`) |
| 6.7.14.1 | `EventDataCollection` will become final; user and integration cloning removed from the Admin API; `#[NotHookable]` and `EventDataCollection::HIDDEN_FROM_WEBHOOK` keep events and fields away from webhooks |
| 6.7.15 | `ProductListingCollectSortingEvent` registers listing sortings at runtime; `JsonField::addPropertyMapping()` for entity extensions; `IdSearchResult::getPrimaryKeyData()`; `sw-include-seo-urls` header returns SEO URLs for headless sales channels; `#[ExperimentalReplacement]` marks code with a planned replacement; Admin shell rework removed Twig blocks of `sw-admin-menu`, the top bar and `sw-page` (re-check overrides); `mt-empty-state` replaces `sw-empty-state`; themes inherit snippets from every theme in `configInheritance`; static theme compilation without a database |
| 6.7.15.1 | `theme:compile` / `theme:change --no-cleanup` has no effect, drop it from deploy scripts; `sw_garan_label_mail` Twig filter deprecated, use the `garanLabels` mail variable; line item rule conditions match any line item type again (6.7.14 matched only `product` items, which broke custom line items under negated conditions) |

Declarative custom fields (6.7.13) remove the need to create field sets programmatically on install:
ship `src/Resources/config/custom-fields.xml`, scaffold it with
`bin/console plugin:create --create-custom-fieldset`. Sets declared this way are removed on uninstall
when user data is not kept. Full syntax in `references/dal.md`.

---

## Prepare for 6.8 now

Full list in `UPGRADE-6.8.md` of the Shopware repository. The changes below hit plugin code most often;
each replacement already works on 6.7, so new code should use it today.

| 6.8 change | Do this on 6.7 |
|---|---|
| XML service, route and package config no longer loads | Ship `services.php` / `routes.php` (see top of this file) |
| `EntitySearchResult` is no longer an `EntityCollection`, not iterable or countable | `foreach ($result->getEntities() as $e)`, `$result->getEntities()->first()`, `getTotal()`; Twig `searchResult.entities` |
| `EntityDefinition` constructor removed | Never call it; let the container build definitions |
| `EventDataCollection` is `final` | Compose, do not extend |
| Several events require `Context` as the last constructor argument | Pass the `Context` when you dispatch them |
| Flows run after the main unit of work (kernel terminate, after the message or command) | Do not rely on a flow action having run inside the same request code path |
| Cart is deleted right after the order is created | Do not reload the cart by token in `CheckoutOrderPlacedEvent` subscribers |
| `AbstractCartPersister::exists()` (added in 6.7.15) is abstract | Implement it in custom persisters; decorators delegate to `getDecorated()` |
| `AbstractCartLoadRoute::load()` takes `?Cart $cart = null` as third parameter | Add it to decorators now and forward it |
| `SHOPWARE_HTTP_DEFAULT_TTL`, `shopware.http.cache.default_ttl`, `stale_while_revalidate`, `stale_if_error` removed | `shopware.http_cache.policies` |
| `AbstractCaptcha::isValid()` / `getViolations()` removed | `validate()` |
| Document generation v2 is the default | Register `shopware.document_v2.*` services next to legacy renderers |
| State machine: one destination per action and source state | Give custom transitions their own action name |
| `RegisterScheduledTaskMessage` removed | Call `TaskScheduler::registerTask()` |
| `StoreApiRouteCacheKeyEvent` / `StoreApiRouteCacheTagsEvent` removed | Nothing listens any more; delete the subscribers |
| `sw-empty-state` removed, `$tc` removed, axios 1.x default | `mt-empty-state`, `$t`, re-test custom HTTP calls |
| `Shopware.Component.createExtendableSetup()` / `overrideComponentSetup()` private | Write native setup SFCs (`.vue`, `.override.vue`) |
| Storefront `DomAccess` helper removed | Native DOM APIs (`querySelector`, `dataset`, `getAttribute`) |
| Inline microdata removed from templates, JSON-LD only | Move structured data overrides to the JSON-LD blocks |
| `theme.json` `label` / `helpText` removed | Admin snippet keys |
| `theme:compile --no-cleanup` removed | Drop the flag |
| Inline `<custom-fields>` in `manifest.xml` removed | `Resources/config/custom-fields.xml` |
| App scripts `response.cache.maxAge()` / `invalidationState()` removed | `sharedMaxAge()` (`s-maxage`) or `clientMaxAge()`; state-based invalidation has no replacement |
| `product.states` removed | `product.type` |
| Database needs time zone tables | Check `SELECT CONVERT_TZ(NOW(), 'UTC', 'Europe/Berlin')` is not `NULL` |
| OpenSearch 1.x dropped, comma-separated hosts in `OPENSEARCH_URL` removed | Upgrade OpenSearch; put several nodes behind one load-balanced endpoint |

Already deprecated earlier in 6.7 and removed in 6.8: increment-based message queue statistics,
`composer watch:storefront`, the reworked rule builder line item condition components (`sw-condition-*`),
`RuleComparison` helpers and several `RuleAreas` constants, `CartBehavior::isRecalculation()`,
`NavigationRoute::buildName()`, `ProductExportResult::getTotal()`.
