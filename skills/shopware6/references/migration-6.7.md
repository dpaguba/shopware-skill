# Shopware 6.7 — Breaking Changes and New Capabilities

Reference for code written against 6.5/6.6 that must run on 6.7, and for the features 6.7 added.
Current line at the time of writing: **6.7.13.x**. The next major, 6.8, is scheduled for **2027**.

---

## Platform requirements

| | SW 6.6 | SW 6.7 |
|---|---|---|
| PHP | 8.2, 8.3 | 8.2 – 8.5 |
| Symfony | 7.0 | 7.4 |
| Doctrine DBAL | 3.x | 4.x |
| PHPUnit | 9/10 | 11.x |
| Admin build | Webpack | **Vite** |
| Admin state | Vuex (deprecated) | **Pinia only** |
| Admin components | `sw-*` | **Meteor `mt-*`** |
| App manifest schema | `manifest-2.0.xsd` | `manifest-3.0.xsd` |

---

## Core and DAL

| Change | Action |
|---|---|
| `EntityExtension::getEntityName()` is abstract | Implement it, return e.g. `'product_media'` |
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
- handlers get `orderTransactionId`, `Request` and `Context`, no `SalesChannelContext`
- `capture` of prepared payments removed, use `pay`
- `technicalName` is required for payment **and** shipping methods
- customer default payment method removed; the last used method wins

---

## Administration

Component removals are exhaustive: `sw-button`, `sw-card`, `sw-icon`, `sw-text-field`, `sw-textarea-field`,
`sw-number-field`, `sw-switch-field`, `sw-checkbox-field`, `sw-select-field`, `sw-email-field`,
`sw-url-field`, `sw-password-field`, `sw-datepicker`, `sw-colorpicker`, `sw-progress-bar`, `sw-loader`,
`sw-tabs`, `sw-popover`, `sw-external-link`, `sw-skeleton-bar`. See the migration map in
`references/admin.md`.

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

- `/api/oauth/authorize` removed; non spec-compliant `/api/oauth/token` requests rejected
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
`MCP_SERVER` feature flag was removed in 6.7.14, the endpoints are always on. ACL permissions are
respected. Classes stay `@experimental` until 6.8.

### Smaller additions

| Version | Feature |
|---|---|
| 6.7.1 | primary delivery and primary transaction on `order`; measurement units in Store API and a Twig conversion filter; new Admin API request headers |
| 6.7.2 | custom route names for Storefront controllers; `CheckoutPermissions::SKIP_CART_PERSISTENCE`; App System custom field sets for `unit` and `newsletter_recipient` |
| 6.7.3 | `NavigationRoute` caching; `activeRoute` instead of controller variables; customer address field length changes |
| 6.7.4 | plugin config default values; Vimeo and YouTube cookie consent separated |
| 6.7.12 | opt-in dedicated `webhook` Messenger transport |
| 6.7.13 | Storefront form validation messages use Shopware snippets; `LineItemPurchasePriceRule` uses `type` instead of `isNet`; declarative custom fields in `Resources/config/custom-fields.xml` for plugins and apps, inline `<custom-fields>` in `manifest.xml` deprecated |
| 6.7.14 | product export templates encode media URLs automatically (remove manual `url_encode`) |

Declarative custom fields (6.7.13) remove the need to create field sets programmatically on install:
ship `src/Resources/config/custom-fields.xml`, scaffold it with
`bin/console plugin:create --create-custom-fieldset`. Sets declared this way are removed on uninstall
when user data is not kept. Full syntax in `references/dal.md`.

---

## Deprecated in 6.7, removed in 6.8

- increment-based message queue statistics
- inline `<custom-fields>` in `manifest.xml` (use `Resources/config/custom-fields.xml`)
- `composer watch:storefront`
- rule builder line item condition components (`sw-condition-*` reworked)
- `RuleComparison` helpers, several `RuleAreas` constants
- `CartBehavior::isRecalculation()`
- `NavigationRoute::buildName()`
