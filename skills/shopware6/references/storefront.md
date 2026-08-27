# Storefront — Twig, SCSS, JavaScript

The Shopware 6 Storefront uses Twig 3 for templates, SCSS for styling, and a custom JavaScript plugin system based on vanilla JS (no jQuery, no Vue).

---

## Template Inheritance

Never copy full templates. Always extend and override specific blocks.

```twig
{# src/Resources/views/storefront/page/product-detail/index.html.twig #}
{% sw_extends '@Storefront/storefront/page/product-detail/index.html.twig' %}

{% block page_product_detail %}
    {# Override the entire block #}
    <div class="my-custom-wrapper">
        {{ parent() }}
    </div>
{% endblock %}
```

The path under `views/storefront/` must exactly mirror the path in the Storefront bundle:

- Original: `vendor/shopware/storefront/Resources/views/storefront/page/product-detail/index.html.twig`
- Override: `src/Resources/views/storefront/page/product-detail/index.html.twig`

Use `{{ parent() }}` to preserve original block content.

---

## Finding Template Blocks

```bash
# Find all blocks in a template
grep -r "{% block " vendor/shopware/storefront/Resources/views/storefront/page/product-detail/
```

Block names follow a pattern: `page_product_detail_` + area name.

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

### Override Theme Variables

Create `src/Resources/app/storefront/src/scss/overrides.scss`:

```scss
$font-size-base: 1rem;
$primary: #ff3d00;
$border-radius: 4px;
```

Register in `theme.json`:

```json
{
    "name": "MyPlugin",
    "author": "Vendor Name",
    "views": ["@Storefront", "@Plugins", "@MyPlugin"],
    "style": ["app/storefront/src/scss/overrides.scss"],
    "script": ["app/storefront/dist/storefront/js/my-plugin.js"],
    "asset": ["app/storefront/src/assets"]
}
```

### Custom SCSS Files

```bash
src/Resources/app/storefront/src/scss/
├── my-component.scss
└── overrides.scss
```

Import in `overrides.scss` or a main file registered via `theme.json`.

---

## JavaScript Plugin System

Shopware uses a custom plugin system — not jQuery, not Vue.

### Register a JS Plugin

```js
import MyPlugin from './my-plugin/my-plugin.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('MyPlugin', MyPlugin, '[data-my-plugin]');
```

### Plugin Class

```js
import Plugin from 'src/plugin-system/plugin.class';

export default class MyPlugin extends Plugin {
    static options = {
        selector: '.my-element',
        value: 'default',
    };

    init() {
        this.el;           // The DOM element the plugin is attached to
        this._options;     // Merged options (from static options + data attrs)

        this._registerEvents();
    }

    _registerEvents() {
        this.el.addEventListener('click', this._onClick.bind(this));
    }

    _onClick(event) {
        event.preventDefault();
        console.log(this._options.value);
    }
}
```

The plugin attaches to `[data-my-plugin]` DOM elements. Pass options via data attributes:

```html
<div data-my-plugin="true" data-my-plugin-value="custom">...</div>
```

### Accessing Other Plugins

```js
const otherPlugin = window.PluginManager.getPluginInstance('OtherPlugin', this.el);
```

---

## Build Storefront Assets

```bash
# Full build
bin/build-storefront.sh

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
`composer watch:storefront` still works but is deprecated and will be removed in 6.8.

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

Register controller in `services.xml` — no `<call>` directives needed, `StorefrontController` receives container and twig automatically:

```xml
<service id="VendorName\PluginName\Storefront\Controller\MyController"
         public="true"/>
```

Register route in `src/Resources/config/routes.xml` (Shopware auto-discovers this path — no `build()` override needed in `PluginName.php`):

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<routes xmlns="http://symfony.com/schema/routing"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://symfony.com/schema/routing
        https://symfony.com/schema/routing/routing-1.0.xsd">

    <import resource="../../Storefront/Controller/**"
            type="attribute"
            ignore-errors="false"/>
</routes>
```

For POST routes, include CSRF token in templates (see references/security.md):

```twig
{{ sw_csrf('frontend.my-page.post') }}
```

---

## Twig Functions & Filters

Shopware adds many custom Twig functions:

```twig
{# URL generation #}
{{ path('frontend.home.page') }}
{{ seoUrl('frontend.detail.page', { productId: product.id }) }}

{# Asset URL #}
{{ asset('bundles/myplugin/image.png') }}

{# Media thumbnail #}
{{ thumbnail(media, 800, 600) }}

{# Translation #}
{{ 'my.translation.key' | trans }}
{{ 'my.translation.key' | trans({ '%count%': 5 }) }}

{# Price formatting #}
{{ product.calculatedPrice.totalPrice | currency }}

{# Date formatting #}
{{ order.orderDateTime | date('d.m.Y') }}

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

Shopware discovers snippets automatically by scanning `Resources/snippet/`.

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

A plugin can bundle a theme by implementing `ThemeInterface`:

```php
class MyPlugin extends Plugin implements ThemeInterface
{
    public function getThemeConfigPath(): string
    {
        return 'theme.json';
    }
}
```

---

## Template Inclusion

Use `{% sw_include %}` instead of Twig's native `{% include %}` — it resolves bundle-prefixed paths with the same inheritance chain as `{% sw_extends %}`:

```twig
{% sw_include '@Storefront/storefront/component/address/address-form.html.twig' %}

{# Pass variables #}
{% sw_include '@MyPlugin/storefront/component/my-partial.html.twig' with { item: product } %}
```

Native `{% include %}` also works but bypasses Shopware's template override mechanism, so other plugins cannot override the included template.
