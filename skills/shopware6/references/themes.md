# Themes: Creating and Configuring Shopware 6 Themes

Themes customize storefront appearance through template overrides, SCSS variables, JavaScript, and a merchantfacing configuration UI. Themes are plugins that implement `ThemeInterface`.

---

## Generate Scaffold

```bash
bin/console theme:create SwagMyTheme
bin/console plugin:refresh
bin/console plugin:install --activate SwagMyTheme
bin/console theme:change   # assign to sales channel
```

**SW 6.7.14+:** `theme:create` also accepts `--with-config` (`config.xml`), `--with-snippets` (storefront snippet
files), `--with-scss` (SCSS 7-1 folder structure) and `--full` for all three. The generated `composer.json` now
gets a real package name (`custom/<theme-name>`) and pins `shopware/core`.

---

## Directory Structure

```
SwagMyTheme/
├── composer.json
└── src/
    ├── SwagMyTheme.php         # Bootstrap, implements ThemeInterface
    └── Resources/
        ├── theme.json          # Required: metadata, assets, config fields
        └── app/
            ├── administration/src/snippet/
            │   └── en.json      # theme config labels (snippet keys)
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
}
```

`ThemeInterface` is an empty marker interface. The configuration is always read from `src/Resources/theme.json`.

---

## theme.json: Complete Structure

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
        "@Storefront",
        "app/storefront/src/scss/base.scss"
    ],

    "script": [
        "@Storefront",
        "app/storefront/dist/storefront/js/swag-my-theme/swag-my-theme.js"
    ],

    "asset": [
        "@Storefront",
        "app/storefront/src/assets"
    ],

    "config": {
        "fields": {
            "sw-color-brand-primary": {
                "type": "color",
                "value": "#008490",
                "editable": true,
                "block": "colors",
                "section": "colorsSection",
                "scss": true
            },
            "sw-color-brand-secondary": {
                "type": "color",
                "value": "#526e7f",
                "block": "colors"
            },
            "sw-font-family-base": {
                "type": "fontFamily",
                "value": "'Open Sans', sans-serif",
                "block": "typography",
                "scss": true
            },
            "sw-font-size-base": {
                "type": "text",
                "value": "16px",
                "block": "typography"
            },
            "sw-logo-desktop": {
                "type": "media",
                "value": "app/storefront/src/assets/logo.png",
                "block": "colors"
            }
        }
    }
}
```

Leaving out `@Storefront` in `style`, `script` or `asset` drops the Storefront's own CSS, JS or assets from the
compiled theme.

### Labels: admin snippets, not theme.json (SW 6.7)

Inline `label` / `helpText` objects on fields (and label-only `blocks` / `sections` entries) are deprecated in 6.7
and removed in 6.8. The theme manager reads admin snippets under
`sw-theme.<TechnicalName>.<tab>.<block>.<section>.<field>.label` (`.helpText` for help texts), where an unset tab,
block or section is `default` and `<TechnicalName>` is the theme's technical name (`Storefront` for the core theme):

```json
{
    "sw-theme": {
        "SwagMyTheme": {
            "default": {
                "colors": {
                    "label": "Colors",
                    "colorsSection": {
                        "label": "Brand Colors",
                        "sw-color-brand-primary": { "label": "Primary Color" }
                    },
                    "default": {
                        "sw-color-brand-secondary": { "label": "Secondary Color" }
                    }
                }
            }
        }
    }
}
```

Put it in `src/Resources/app/administration/src/snippet/en.json` (`en-GB.json` before 6.7.3); no admin `main.js`
is needed. On 6.6 only the inline `label` objects work.

### Field Types

| Type | Purpose |
|------|---------|
| `color` | Color picker, injects as SCSS variable |
| `text` | Text input |
| `number` | Number input |
| `fontFamily` | Font family picker |
| `media` | Media file picker (logo, background) |
| `checkbox` | Boolean checkbox |
| `switch` | Toggle switch |
| `url` | URL input |

Fields with `"scss": true` (default) are injected as SCSS variables automatically.

---

### Config values as CSS custom properties (SW 6.7.11+)

Every theme config field that is not marked `scss: false` (except `media` fields) is exposed as a native custom
property, so runtime theming no longer requires recompiling SCSS:

```css
.btn-primary {
    background: var(--sw-color-brand-primary);
}
```

### Referencing single files from other bundles (SW 6.7.11+)

`style` and `script` entries can point at one file in another bundle instead of pulling its whole build:

```json
{
  "style": ["@MyTheme/app/storefront/src/scss/overrides.scss"],
  "script": ["@MyPlugin/app/storefront/dist/storefront/my-plugin.js"]
}
```

---

## SCSS: Bootstrap Variable Overrides

`overrides.scss` is compiled first and can override Bootstrap and Shopware variables:

```scss
// overrides.scss: loaded before Storefront SCSS
$font-family-base:    'Open Sans', sans-serif;
$font-size-base:      1rem;
$line-height-base:    1.6;

$grid-breakpoints: (
    xs: 0,
    sm: 576px,
    md: 768px,
    lg: 992px,
    xl: 1200px,
    xxl: 1400px
);
```

Do not set theme config variables (`$sw-color-brand-primary`, `$sw-color-success`, ...) here. They are injected
before `overrides.scss`, so a value set in this file silently overrides what the merchant configures in the Admin;
change their default via `value` in `theme.json` instead.

Custom styles in `base.scss`:

```scss
// base.scss: runs after Storefront SCSS
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

**SW 6.7.15+:** the theme also inherits the storefront snippets of every theme listed in `configInheritance`
(snippets of intermediate themes used to be dropped), and `theme.parent_theme_id` points to the last, nearest entry. Run `bin/console theme:refresh` to apply it
outside a plugin update.

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

Theme config is stored per theme, not in the system config, so `SystemConfigService` cannot read it. Use the
accessor behind the Twig `theme_config()` function:

```php
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Theme\ThemeConfigValueAccessor;
use Symfony\Component\HttpFoundation\Request;

class MyService
{
    public function __construct(
        private readonly ThemeConfigValueAccessor $themeConfig,
    ) {}

    public function getPrimaryColor(Request $request, SalesChannelContext $context): ?string
    {
        $themeId = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_THEME_ID);
        $value = $this->themeConfig->get('sw-color-brand-primary', $context, $themeId);

        return \is_string($value) ? $value : null;
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

Or read the value directly:

```twig
{% set primaryColor = theme_config('sw-color-brand-primary') %}
```

`config('SwagMyTheme.config.key')` reads the plugin's `config.xml` values (system config), not theme fields.

---

## CLI Commands

```bash
# Compile theme for a sales channel
bin/console theme:compile

# Assign theme to sales channel interactively
bin/console theme:change

# Refresh theme config (after theme.json changes)
bin/console theme:refresh

# Dump theme config to var/theme-files.json (used by the watcher and dev server)
bin/console theme:dump
```

Each compile writes to a new `public/theme/<hash>` directory. The daily `theme.delete_files` scheduled task removes
unused ones 24 to 48 hours after the switch (SW 6.7.15.1). `theme:compile` and `theme:change` no longer clean up
themselves; their `--no-cleanup` option (added in 6.7.14) is a deprecated no-op and fails in 6.8, so drop it from
deploy scripts.

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

Heads-up for footer overrides: with 6.8 `layout/footer/footer.html.twig` switches to semantic markup (`<h2>`
headlines, `<ul>` / `<li>` columns instead of `div[role]`), announced in 6.7.15.
