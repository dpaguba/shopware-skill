# Storefront: Twig, SCSS, JavaScript

The Shopware 6 Storefront uses Twig 3 for templates, SCSS for styling, and a custom JavaScript plugin system based on vanilla JS (no jQuery, no Vue).

---

## Template Inheritance

Never copy full templates. Always extend and override specific blocks.

```twig
{# src/Resources/views/storefront/page/content/product-detail.html.twig #}
{% sw_extends '@Storefront/storefront/page/content/product-detail.html.twig' %}

{% block page_content %}
    {# Override the entire block #}
    <div class="my-custom-wrapper">
        {{ parent() }}
    </div>
{% endblock %}
```

The path under `views/storefront/` must exactly mirror the path in the Storefront bundle:

- Original: `vendor/shopware/storefront/Resources/views/storefront/page/content/product-detail.html.twig`
- Override: `src/Resources/views/storefront/page/content/product-detail.html.twig`

Use `{{ parent() }}` to preserve original block content.

> **Product detail is a CMS layout since 6.6.** `page/product-detail/index.html.twig` and its sub-templates were
> removed; an override of that path is silently never rendered. The page renders
> `page/content/product-detail.html.twig`, and its parts live in `element/cms-element-*.html.twig` and
> `component/buy-widget/`.

---

## Finding Template Blocks

```bash
# Find all blocks in a template
grep -r "{% block " vendor/shopware/storefront/Resources/views/storefront/component/buy-widget/
```

Block names follow a pattern: template prefix + area name, e.g. `buy_widget_*` in `component/buy-widget/`,
`element_buy_box_*` in `element/cms-element-buy-box.html.twig`. The product detail page shell has `page_content`,
`cms_breadcrumb` and `cms_content`.

---

## Variables Available in Templates

Access page variables injected by subscribers:

```twig
{# In a product detail page template #}
{{ page.product.name }}
{{ page.product.description }}
{{ page.product.cover.media.url }}

{# Loop over variants #}
{% for property in page.product.properties %}
    {{ property.name }}
{% endfor %}

{# Sales channel context #}
{{ context.salesChannel.name }}
{{ context.currency.symbol }}
```

---

## Inject Variables via Subscriber

```php
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();

        $page->addExtension('myData', new ArrayStruct([
            'customValue' => 'hello',
        ]));
    }
}
```

Access in Twig:

```twig
{{ page.extensions.myData.customValue }}
```

---

## Custom Template Variables (Global)

Register a subscriber for `StorefrontRenderEvent` to inject data on every render:

```php
use Shopware\Storefront\Event\StorefrontRenderEvent;

class GlobalVariableSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [StorefrontRenderEvent::class => 'onRender'];
    }

    public function onRender(StorefrontRenderEvent $event): void
    {
        $event->setParameter('myGlobalVar', 'value');
    }
}
```

---

## SCSS Themes

### Plugin Styles

A plugin without `theme.json` needs no registration: `src/Resources/app/storefront/src/scss/base.scss` is compiled
into every theme that lists `@Plugins`, and the JS entry `src/Resources/app/storefront/src/main.js` (or `main.ts`)
is built to `src/Resources/app/storefront/dist/storefront/js/<technical-name>/<technical-name>.js`.

### Override Theme Variables

Bootstrap and Storefront variables use `!default`, so they can only be overridden in a file compiled before
`@Storefront`. That is a theme's `overrides.scss`.

Create `src/Resources/app/storefront/src/scss/overrides.scss`:

```scss
$font-size-base: 1rem;
$primary: #ff3d00;
$border-radius: 4px;
```

Register in the theme's `src/Resources/theme.json`:

```json
{
    "name": "MyTheme",
    "author": "Vendor Name",
    "views": ["@Storefront", "@Plugins", "@MyTheme"],
    "style": ["app/storefront/src/scss/overrides.scss", "@Storefront", "app/storefront/src/scss/base.scss"],
    "script": ["@Storefront", "app/storefront/dist/storefront/js/my-theme/my-theme.js"],
    "asset": ["@Storefront", "app/storefront/src/assets"]
}
```

### Custom SCSS Files

```bash
src/Resources/app/storefront/src/scss/
├── my-component.scss
└── base.scss
```

Import partials from `base.scss` (plugin) or from a file registered in the theme's `style` array.

---

## JavaScript Plugin System

Shopware uses a custom plugin system: not jQuery, not Vue.

### Register a JS Plugin

```js
const PluginManager = window.PluginManager;

// Lazy: the chunk loads only when the selector is on the page
PluginManager.register('MyPlugin', () => import('./my-plugin/my-plugin.plugin'), '[data-my-plugin]');
```

### Plugin Class

```js
import Plugin from 'src/plugin-system/plugin.class'; // or: extends window.PluginBaseClass

export default class MyPlugin extends Plugin {
    static options = {
        selector: '.my-element',
        value: 'default',
    };

    init() {
        this.el;          // The DOM element the plugin is attached to
        this.options;     // Merged options (static options + data-my-plugin-options)

        this._onClick = this._onClick.bind(this);
        this.el.addEventListener('click', this._onClick);
    }

    // SW 6.7.14+: called before the PluginManager replaces this instance
    destroy() {
        this.el.removeEventListener('click', this._onClick);
    }

    _onClick(event) {
        event.preventDefault();
        console.log(this.options.value);
    }
}
```

The plugin attaches to `[data-my-plugin]` DOM elements. Pass options as JSON in the `-options` attribute:

```html
<div data-my-plugin data-my-plugin-options='{ "value": "custom" }'>...</div>
```

### Accessing Other Plugins

```js
const otherPlugin = window.PluginManager.getPluginInstanceFromElement(otherElement, 'OtherPlugin');

// SW 6.7.11+: call a method on every instance
window.PluginManager.callPluginMethod('OtherPlugin', 'refresh');
```

### Overriding and Extending Plugins (SW 6.7.14+)

`PluginManager.override('AddToCart', MyAddToCart, '[data-add-to-cart]')` now also works for async (lazy) core
plugins, and `PluginManager.extend()` works again. `override()` needs the exact selector the plugin was registered
with. An instance that is already on the page gets replaced, and its `destroy()` is called first; core plugins
mostly lack `destroy()`, so register overrides in your entry file before plugins initialize.

Each extension bundle now has its own webpack chunk global (`webpackChunk<technical_name>`) instead of sharing
`window.webpackChunk`; rebuild storefront assets after updating.

---

## Build Storefront Assets

```bash
# Full build
bin/build-storefront.sh

# Hot proxy watcher (project)
bin/watch-storefront.sh

# Compile theme
bin/console theme:compile

# Refresh theme config
bin/console theme:refresh
```

### Dev server (SW 6.7.11+)

```bash
composer storefront:dev-server
```

Vite based, needs no proxy, and applies SCSS and JS changes live while you browse the normal Storefront.
This Composer script exists in the `shopware/shopware` platform checkout; a project's `bin/watch-storefront.sh`
still starts the hot proxy. `composer watch:storefront` still works but is deprecated and will be removed in 6.8.

### Global JS event bus (SW 6.7.11+)

Plugins can communicate without importing each other:

```js
window.Shopware.emit('Filter:Change', { foo: 'bar' });
window.Shopware.on('Filter:Change', ({ foo }) => { /* ... */ });
```

### Twig UX components (SW 6.7.11+)

Reusable storefront components with their own SCSS and JS, usable inside existing templates. This is the
base of the upcoming content system, so prefer it over copy-pasted include partials for new work.

---

## Custom Controller (Storefront Route)

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class MyController extends StorefrontController
{
    #[Route(
        path: '/my-page',
        name: 'frontend.my-page.index',
        methods: ['GET']
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        return $this->renderStorefront('@MyPlugin/storefront/page/my-page/index.html.twig', [
            'myVar' => 'value',
        ]);
    }
}
```

Register the controller in `src/Resources/config/services.php`. It must be public and get the container through
`setContainer`, as the core Storefront controllers do:

```php
$services->set(MyController::class)
    ->public()
    ->call('setContainer', [service('service_container')]);
```

Register routes in `src/Resources/config/routes.php` (Shopware auto-discovers this path; no `build()` override
needed in `PluginName.php`):

```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Storefront/Controller/*Controller.php', 'attribute');
};
```

> **6.7.14+:** `services.xml` and `routes.xml` are deprecated and no longer loaded in 6.8. Use the PHP formats above.

Storefront CSRF tokens were removed in 6.5 in favour of SameSite cookies, so POST forms need no token and there is
no `sw_csrf` function anymore (see references/security.md).

---

## Twig Functions & Filters

Shopware adds many custom Twig functions:

```twig
{# URL generation #}
{{ path('frontend.home.page') }}
{{ seoUrl('frontend.detail.page', { productId: product.id }) }}

{# Asset URL #}
{{ asset('bundles/myplugin/image.png') }}

{# Responsive media thumbnails (tag, not function) #}
{% sw_thumbnails 'my-image-thumbnails' with { media: product.cover.media } %}

{# Translation #}
{{ 'my.translation.key' | trans }}
{{ 'my.translation.key' | trans({ '%count%': 5 }) }}

{# Price formatting #}
{{ product.calculatedPrice.totalPrice | currency }}

{# Date formatting #}
{{ order.orderDateTime | date('d.m.Y') }}

{# Plugin config (system config) and theme config #}
{{ config('MyPlugin.config.myKey') }}
{{ theme_config('sw-color-brand-primary') }}

{# Feature toggle #}
{% if feature('FEATURE_NEXT_12345') %}
    ...
{% endif %}
```

---

## Snippets (Translations in Storefront)

Create `src/Resources/snippet/en_GB/my-plugin.en-GB.json`:

```json
{
    "my-plugin": {
        "page": {
            "title": "My Custom Page",
            "description": "Welcome to my page."
        }
    }
}
```

Shopware discovers snippets automatically by scanning `Resources/snippet/` for `<name>.<locale>.json`. Since 6.7.3
core files use country-agnostic locales (`storefront.en.json`), which is the recommended naming for new files;
`en-GB` files keep working.

Use in Twig:

```twig
{{ 'my-plugin.page.title' | trans }}
```

---

## Theme vs. Plugin

| | Plugin | Theme |
|---|---|---|
| Purpose | Extend functionality | Change appearance |
| Inherits from | Storefront | Another theme or Storefront |
| `theme.json` | Optional | Required |
| Admin preview | No | Yes (screenshot) |
| Sales-channel assignment | Plugin active = always on | Must be assigned to sales channel |

A plugin can bundle a theme by implementing the (empty marker) `ThemeInterface` and shipping
`src/Resources/theme.json`; the path is fixed, there is no method to configure it:

```php
use Shopware\Storefront\Framework\ThemeInterface;

class MyPlugin extends Plugin implements ThemeInterface
{
}
```

---

## Template Inclusion

Use `{% sw_include %}` instead of Twig's native `{% include %}`. It resolves bundle-prefixed paths with the same inheritance chain as `{% sw_extends %}`:

```twig
{% sw_include '@Storefront/storefront/component/address/address-form.html.twig' %}

{# Pass variables #}
{% sw_include '@MyPlugin/storefront/component/my-partial.html.twig' with { item: product } %}
```

Native `{% include %}` also works but bypasses Shopware's template override mechanism, so other plugins cannot override the included template.
