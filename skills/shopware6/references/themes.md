# Themes — Creating and Configuring Shopware 6 Themes

Themes customize storefront appearance through template overrides, SCSS variables, JavaScript, and a merchantfacing configuration UI. Themes are plugins that implement `ThemeInterface`.

---

## Generate Scaffold

```bash
bin/console theme:create SwagMyTheme
bin/console plugin:refresh
bin/console plugin:install --activate SwagMyTheme
bin/console theme:change   # assign to sales channel
```

---

## Directory Structure

```
SwagMyTheme/
├── composer.json
└── src/
    ├── SwagMyTheme.php         # Bootstrap — implements ThemeInterface
    └── Resources/
        ├── theme.json          # Required: metadata, assets, config fields
        └── app/
            └── storefront/
                └── src/
                    ├── main.js
                    └── scss/
                        ├── base.scss        # custom styles
                        └── overrides.scss   # Bootstrap variable overrides
```

---

## Bootstrap Class

```php
<?php declare(strict_types=1);

namespace SwagMyTheme;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;

class SwagMyTheme extends Plugin implements ThemeInterface
{
    public function getThemeConfigPath(): string
    {
        return 'theme.json';
    }
}
```

---

## theme.json — Complete Structure

```json
{
    "name": "SwagMyTheme",
    "author": "My Company",
    "description": {
        "en-GB": "My custom Shopware theme",
        "de-DE": "Mein benutzerdefiniertes Shopware-Theme"
    },
    "previewMedia": "app/storefront/src/assets/preview.jpg",

    "views": [
        "@Storefront",
        "@Plugins",
        "@SwagMyTheme"
    ],

    "style": [
        "app/storefront/src/scss/overrides.scss",
        "app/storefront/src/scss/base.scss"
    ],

    "script": [
        "app/storefront/dist/storefront/js/swag-my-theme/swag-my-theme.js"
    ],

    "asset": [
        "app/storefront/src/assets"
    ],

    "config": {
        "blocks": {
            "colors": {
                "label": {
                    "en-GB": "Colors",
                    "de-DE": "Farben"
                }
            },
            "typography": {
                "label": {
                    "en-GB": "Typography"
                }
            }
        },
        "sections": {
            "colorsSection": {
                "label": { "en-GB": "Brand Colors" },
                "block": "colors"
            }
        },
        "fields": {
            "sw-color-brand-primary": {
                "label": { "en-GB": "Primary Color", "de-DE": "Primärfarbe" },
                "type": "color",
                "value": "#008490",
                "editable": true,
                "block": "colors",
                "section": "colorsSection",
                "scss": true
            },
            "sw-color-brand-secondary": {
                "label": { "en-GB": "Secondary Color" },
                "type": "color",
                "value": "#526e7f",
                "block": "colors"
            },
            "sw-font-family-base": {
                "label": { "en-GB": "Base Font" },
                "type": "fontFamily",
                "value": "'Open Sans', sans-serif",
                "block": "typography",
                "scss": true
            },
            "sw-font-size-base": {
                "label": { "en-GB": "Base Font Size" },
                "type": "text",
                "value": "16px",
                "block": "typography"
            },
            "sw-logo-desktop": {
                "label": { "en-GB": "Desktop Logo" },
                "type": "media",
                "value": "app/storefront/src/assets/logo.png",
                "block": "colors"
            }
        }
    }
}
```

### Field Types

| Type | Purpose |
|------|---------|
| `color` | Color picker — injects as SCSS variable |
| `text` | Text input |
| `number` | Number input |
| `fontFamily` | Font family picker |
| `media` | Media file picker (logo, background) |
| `checkbox` | Boolean checkbox |
| `switch` | Toggle switch |
| `url` | URL input |

Fields with `"scss": true` (default) are injected as SCSS variables automatically.

---

## SCSS — Bootstrap Variable Overrides

`overrides.scss` is compiled first and can override Bootstrap and Shopware variables:

```scss
// overrides.scss — loaded before Storefront SCSS
$font-family-base:    'Open Sans', sans-serif;
$font-size-base:      1rem;
$line-height-base:    1.6;

$sw-color-brand-primary:   #008490;
$sw-color-brand-secondary: #526e7f;
$sw-color-success:         #3cc261;
$sw-color-danger:          #e52427;

$grid-breakpoints: (
    xs: 0,
    sm: 576px,
    md: 768px,
    lg: 992px,
    xl: 1200px,
    xxl: 1400px
);
```

Custom styles in `base.scss`:

```scss
// base.scss — runs after Storefront SCSS
.my-theme-header {
    background: $sw-color-brand-primary;
    color: white;
}

.btn-primary {
    background-color: var(--sw-color-brand-primary);
}
```

---

## Theme Inheritance

A theme can inherit from another theme. All parent config fields are available unless overridden.

In `theme.json`:
```json
{
    "configInheritance": [
        "@Storefront",
        "@ParentTheme"
    ]
}
```

In `views` array, order defines override priority (last wins):
```json
{
    "views": [
        "@Storefront",
        "@ParentTheme",
        "@SwagMyTheme"
    ]
}
```

---

## Read Theme Config in PHP

```php
use Shopware\Core\System\SystemConfig\SystemConfigService;

class MyService
{
    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {}

    public function getPrimaryColor(string $salesChannelId): string
    {
        return $this->systemConfig->getString(
            'SwagMyTheme.config.sw-color-brand-primary',
            $salesChannelId
        );
    }
}
```

---

## Read Theme Config in Twig

Theme config variables injected with `scss: true` are available as CSS variables:

```twig
<style>
    .hero { background-color: var(--sw-color-brand-primary); }
</style>
```

Or read via `shopware.config`:

```twig
{% set primaryColor = shopware.config['SwagMyTheme.config.sw-color-brand-primary'] %}
```

---

## CLI Commands

```bash
# Compile theme for a sales channel
bin/console theme:compile

# Assign theme to sales channel interactively
bin/console theme:change

# Refresh theme config (after theme.json changes)
bin/console theme:refresh

# Dump theme config to see all variables
bin/console theme:dump
```

---

## Plugin vs Theme vs App

| | Plugin with theme.json | Standalone Theme | App-based Theme |
|---|---|---|---|
| Purpose | Add features + styling | Styling only | SaaS / Store |
| PHP | Yes | Yes | No |
| `ThemeInterface` | Yes | Yes | Not needed |
| `theme.json` | Yes | Yes | Yes |
| Shopware Store | Plugin section | Theme section | App section |
| Assignment | Auto (when plugin active + assigned) | Manual per sales channel | Manual |

---

## Template Override from Theme

Themes follow the same Twig inheritance rules as plugins:

```twig
{# src/Resources/views/storefront/layout/header/header.html.twig #}
{% sw_extends '@Storefront/storefront/layout/header/header.html.twig' %}

{% block layout_header_navigation %}
    <div class="my-theme-nav-wrapper">
        {{ parent() }}
    </div>
{% endblock %}
```
