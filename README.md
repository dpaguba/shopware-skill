# Shopware 6 Agent Skill

<!-- readme-i18n-selector: start -->
[English](README.md)
<!-- readme-i18n-selector: end -->

[![skills.sh](https://skills.sh/b/dpaguba/shopware-skill)](https://skills.sh/dpaguba/shopware-skill)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

A comprehensive [agent skill](https://skills.sh) for Shopware 6 development — covering plugins, themes, the Admin (Vue.js), Storefront (Twig/SCSS), DAL, APIs, testing, and everything in between.

> [!NOTE]
> This skill targets **Shopware 6.5 and 6.6**. It follows the official [Shopware Developer Documentation](https://developer.shopware.com/docs/) and is kept up-to-date with modern patterns including PHP 8.2+ attributes and the Meteor Admin SDK.

## Installation

```bash
npx skills add dpaguba/shopware-skill
```

That's it. The skill is automatically loaded by Claude Code and other supported agents whenever you work on Shopware 6 tasks.

## What's Inside

The skill loads contextually — it picks up the right reference file based on your question. Coverage includes:

| Area | Topics |
|------|--------|
| **Plugin Structure** | `composer.json`, bootstrap class, `services.xml`, lifecycle hooks, scheduled tasks, console commands |
| **DAL** | `EntityDefinition`, Criteria, Aggregations, Associations, Translations, Entity Extensions, Migrations |
| **Admin** | Vue 3 modules, component overrides, ACL/privileges, `sw-entity-listing`, Vuex, Meteor Admin SDK |
| **Storefront** | Twig inheritance, SCSS overrides, JavaScript plugin system, custom controllers |
| **Themes** | `theme.json`, Bootstrap variable overrides, theme inheritance, configuration fields |
| **Cart** | Processors, Collectors, Validators, discount line items (`PercentagePriceDefinition`) |
| **SEO & Mail** | Custom SEO URL routes, Sitemap providers, mail templates, Order State Machine |
| **App System** | `manifest.xml`, webhook HMAC verification, registration handshake, App Scripts, Checkout Gateway |
| **API** | Admin API & Store API (CRUD, bulk, filters), TypeScript client, context token lifecycle |
| **Testing** | PHPUnit (unit + integration), `StaticEntityRepository`, `ProductBuilder`, Jest, Cypress |
| **Security** | Route scopes, CSRF protection, input validation, `ShopwareHttpException` |
| **Performance** | HTTP Cache (tags + invalidation), Message Queue, Elasticsearch/OpenSearch, object cache |
| **Advanced** | PHP 8 attribute-based entities (6.6.3+), Flysystem, Redis, Rate Limiter, Data Indexer, In-App Purchases, Field Inheritance |
| **DevOps** | Structured logging, PHPStan, php-cs-fixer, CI/CD, deployment |

## Usage

Once installed, just work on your Shopware 6 project normally. The skill activates automatically when you:

- Ask the agent to create or extend a plugin
- Work with the DAL, Admin, or Storefront
- Implement payment handlers, CMS elements, or rule conditions
- Debug, test, or deploy a Shopware extension

> [!TIP]
> Mention the Shopware version (6.5 vs 6.6) early in your prompt — some APIs and Vue component signatures differ between versions.

### Example prompts

```
Create a custom entity called "wishlist_item" with a ManyToOne association to product.
```

```
Add a cart processor that applies a 10% discount for customers in the "VIP" customer group.
```

```
Create a Shopware 6 theme with custom brand colors and a logo field in the theme config.
```

```
Write a PHPUnit integration test for my PaymentHandler using ProductBuilder.
```

## Reference Files

The skill's knowledge is organized into focused reference files under `skills/shopware6/references/`:

| File | Description |
|------|-------------|
| `dal.md` | Full DAL reference — fields, criteria, associations, translations, extensions |
| `admin.md` | Admin Vue system — modules, components, ACL, inline edit, search |
| `storefront.md` | Twig blocks, SCSS, JS plugin system, page subscribers |
| `themes.md` | Theme development — `theme.json`, Bootstrap overrides, inheritance |
| `cart.md` | Cart pipeline — processors, collectors, validators, discounts |
| `seo-mail.md` | SEO URLs, sitemap, mail templates, documents, state machine |
| `app-system.md` | App System — manifest, webhooks, App Scripts |
| `api.md` | Admin & Store API patterns |
| `testing.md` | PHPUnit, Jest, Cypress testing patterns |
| `security.md` | Auth, CSRF, validation, error handling |
| `performance.md` | Caching, queues, Elasticsearch |
| `integrations.md` | Payment, Shipping, CMS, Rule Builder, Flow Builder |
| `advanced.md` | PHP attributes, Flysystem, Redis, Rate Limiter, IAP |
| `devops.md` | Logging, static analysis, CI/CD |
| `plugin-structure.md` | Full plugin anatomy |

## Contributing

Improvements, corrections, and new patterns are welcome. Please open an issue or PR on [GitHub](https://github.com/dpaguba/shopware-skill).
