# Shopware 6 Agent Skill

English · [Deutsch](#deutsch)

[![skills.sh](https://skills.sh/b/dpaguba/shopware-skill)](https://skills.sh/dpaguba/shopware-skill)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

An [agent skill](https://skills.sh) for Shopware 6: plugins, themes, the Admin, the Storefront, DAL, APIs and testing. Sixteen reference files, around 8,500 lines, checked against the Shopware source rather than written from memory.

> [!NOTE]
> The skill targets **Shopware 6.7** (current line 6.7.13.x) and flags what differs on **6.6**. That gap matters: 6.7 collapsed five payment handler interfaces into one `AbstractPaymentHandler`, removed every basic `sw-*` Admin component in favour of Meteor `mt-*`, swapped Webpack for Vite and dropped Vuex for Pinia. Code that is correct on 6.6 often fails on 6.7.

## Installation

```bash
npx skills add dpaguba/shopware-skill
```

That's it. Claude Code and other supported agents load the skill whenever you work on Shopware 6.

## What's Inside

Reference files load on demand, so a question about the cart never drags in the Admin docs.

| Area | Topics |
|------|--------|
| **Plugin Structure** | `composer.json`, bootstrap class, `services.xml`, lifecycle hooks, scheduled tasks, console commands, choosing between events, queue and commands |
| **DAL** | `EntityDefinition`, Criteria, Aggregations, Associations, Translations, Entity Extensions, Migrations, declarative custom fields |
| **Admin** | Vue 3 modules, component overrides, ACL/privileges, `sw-entity-listing`, Pinia, Meteor components with the full `sw-*` to `mt-*` rename map |
| **Storefront** | Twig inheritance, SCSS overrides, JavaScript plugin system, custom controllers, the Vite dev server |
| **Themes** | `theme.json`, Bootstrap variable overrides, theme inheritance, config exposed as CSS custom properties |
| **Cart** | Processors, Collectors, Validators, discount line items (`PercentagePriceDefinition`) |
| **SEO & Mail** | Custom SEO URL routes, Sitemap providers, mail templates, Order State Machine |
| **App System** | `manifest.xml` (schema 3.0), webhook HMAC verification, registration handshake, App Scripts, Checkout Gateway |
| **API** | Admin API & Store API (CRUD, bulk, filters), TypeScript client, context token lifecycle |
| **Testing** | PHPUnit (unit + integration), `StaticEntityRepository`, `ProductBuilder`, Jest, Cypress |
| **Security** | Route scopes at class and method level, CSRF protection, input validation, `ShopwareHttpException` |
| **Performance** | HTTP Cache (tags + invalidation), Message Queue, Elasticsearch/OpenSearch, object cache |
| **Advanced** | PHP 8 attribute-based entities (6.6.3+), Flysystem, Redis, Rate Limiter, Data Indexer, In-App Purchases, Field Inheritance |
| **DevOps** | Structured logging, PHPStan, php-cs-fixer, CI/CD, deployment |
| **6.6 to 6.7 migration** | Breaking changes across Core/DAL, Admin, Storefront, cache, API and hosting, plus what each 6.7.x minor added |

## Usage

Work on your Shopware project as usual. The skill activates when you:

- Ask the agent to create or extend a plugin
- Work with the DAL, Admin, or Storefront
- Implement payment handlers, CMS elements, or rule conditions
- Debug, test, or deploy an extension

> [!TIP]
> Say which Shopware version you are on early in the prompt. Without it the agent targets 6.7 and adds a note about 6.6, which is usually right but costs you a paragraph you did not need.

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

Under `skills/shopware6/references/`:

| File | Description |
|------|-------------|
| `dal.md` | Fields, criteria, associations, translations, extensions, custom fields |
| `admin.md` | Admin Vue system: modules, components, ACL, inline edit, search |
| `storefront.md` | Twig blocks, SCSS, JS plugin system, page subscribers, dev server |
| `themes.md` | `theme.json`, Bootstrap overrides, inheritance, CSS custom properties |
| `cart.md` | Cart pipeline: processors, collectors, validators, discounts |
| `seo-mail.md` | SEO URLs, sitemap, mail templates, documents, state machine |
| `app-system.md` | Manifest, webhooks, App Scripts |
| `api.md` | Admin & Store API patterns |
| `testing.md` | PHPUnit, Jest, Cypress |
| `security.md` | Auth, route scopes, CSRF, validation, error handling |
| `performance.md` | Caching, queues, Elasticsearch |
| `integrations.md` | Payment, shipping costs, CMS, Rule Builder, Flow Builder |
| `advanced.md` | PHP attributes, Flysystem, Redis, Rate Limiter, IAP |
| `devops.md` | Logging, static analysis, CI/CD |
| `plugin-structure.md` | Full plugin anatomy |
| `migration-6.7.md` | What 6.7 broke, and what each minor added |

## Examples

Two runnable plugins live in `skills/shopware6/examples/`, real source files rather than snippets:

- [`custom-entity/`](skills/shopware6/examples/custom-entity/) builds a `wishlist_item` entity, including the composite foreign key that `product` needs because its primary key is `(id, version_id)`
- [`storefront-subscriber/`](skills/shopware6/examples/storefront-subscriber/) adds a related-articles box to the product page through a page subscriber and a Twig override

Copy either into `custom/plugins/` and run `bin/console plugin:install --activate`.

## Contributing

Corrections are especially welcome. Shopware moves fast, and a reference that was right in June can be wrong by autumn. If you hit a pattern the skill gets wrong, open an issue or a PR on [GitHub](https://github.com/dpaguba/shopware-skill).

---

## Deutsch

<details>
<summary><b>Deutsche Fassung aufklappen</b></summary>

<br>

Ein [Agent Skill](https://skills.sh) für Shopware 6: Plugins, Themes, Administration, Storefront, DAL, APIs und Tests. Sechzehn Referenzdateien, rund 8.500 Zeilen, gegen den Shopware-Quellcode geprüft statt aus dem Gedächtnis geschrieben.

> **Hinweis:** Der Skill zielt auf **Shopware 6.7** (aktuelle Linie 6.7.13.x) und markiert, was sich auf **6.6** unterscheidet. Dieser Unterschied ist erheblich: 6.7 hat fünf Payment-Handler-Interfaces zu einem `AbstractPaymentHandler` zusammengeführt, sämtliche `sw-*` Basiskomponenten der Administration zugunsten der Meteor-Komponenten (`mt-*`) entfernt, Webpack durch Vite ersetzt und Vuex durch Pinia abgelöst. Code, der unter 6.6 korrekt ist, schlägt unter 6.7 häufig fehl.

### Installation

```bash
npx skills add dpaguba/shopware-skill
```

Das war es. Claude Code und andere unterstützte Agenten laden den Skill, sobald du an Shopware 6 arbeitest.

### Inhalt

Referenzdateien werden bei Bedarf geladen, eine Frage zum Warenkorb zieht also nicht die Administrations-Doku mit.

| Bereich | Themen |
|------|--------|
| **Plugin-Struktur** | `composer.json`, Bootstrap-Klasse, `services.xml`, Lifecycle-Hooks, Scheduled Tasks, Console Commands, Auswahl zwischen Events, Queue und Commands |
| **DAL** | `EntityDefinition`, Criteria, Aggregations, Associations, Übersetzungen, Entity Extensions, Migrationen, deklarative Custom Fields |
| **Administration** | Vue-3-Module, Component Overrides, ACL und Berechtigungen, `sw-entity-listing`, Pinia, Meteor-Komponenten mit vollständiger Umbenennungstabelle von `sw-*` nach `mt-*` |
| **Storefront** | Twig-Vererbung, SCSS-Overrides, JavaScript-Plugin-System, eigene Controller, der Vite-Dev-Server |
| **Themes** | `theme.json`, Bootstrap-Variablen überschreiben, Theme-Vererbung, Konfiguration als CSS Custom Properties |
| **Warenkorb** | Processors, Collectors, Validators, Rabatt-Line-Items (`PercentagePriceDefinition`) |
| **SEO & Mail** | Eigene SEO-URL-Routen, Sitemap-Provider, Mail-Templates, Order State Machine |
| **App System** | `manifest.xml` (Schema 3.0), HMAC-Verifikation von Webhooks, Registrierungs-Handshake, App Scripts, Checkout Gateway |
| **API** | Admin API & Store API (CRUD, Bulk, Filter), TypeScript-Client, Lebenszyklus des Context Tokens |
| **Tests** | PHPUnit (Unit und Integration), `StaticEntityRepository`, `ProductBuilder`, Jest, Cypress |
| **Sicherheit** | Route Scopes auf Klassen- und Methodenebene, CSRF-Schutz, Eingabevalidierung, `ShopwareHttpException` |
| **Performance** | HTTP-Cache (Tags und Invalidierung), Message Queue, Elasticsearch/OpenSearch, Object Cache |
| **Fortgeschritten** | Attribut-basierte Entities mit PHP 8 (6.6.3+), Flysystem, Redis, Rate Limiter, Data Indexer, In-App Purchases, Field Inheritance |
| **DevOps** | Strukturiertes Logging, PHPStan, php-cs-fixer, CI/CD, Deployment |
| **Migration 6.6 auf 6.7** | Breaking Changes in Core/DAL, Administration, Storefront, Cache, API und Hosting, dazu die Neuerungen jedes 6.7.x-Minors |

### Verwendung

Arbeite an deinem Shopware-Projekt wie gewohnt. Der Skill greift, wenn du:

- den Agenten bittest, ein Plugin zu erstellen oder zu erweitern
- mit DAL, Administration oder Storefront arbeitest
- Payment Handler, CMS-Elemente oder Rule-Bedingungen implementierst
- eine Extension debuggst, testest oder deployst

> **Tipp:** Nenne deine Shopware-Version früh im Prompt. Ohne diese Angabe zielt der Agent auf 6.7 und ergänzt einen Hinweis zu 6.6, was meistens passt, dich aber einen Absatz kostet, den du nicht gebraucht hättest.

#### Beispiel-Prompts

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

### Referenzdateien

Unter `skills/shopware6/references/`:

| Datei | Beschreibung |
|------|-------------|
| `dal.md` | Felder, Criteria, Associations, Übersetzungen, Extensions, Custom Fields |
| `admin.md` | Vue-System der Administration: Module, Komponenten, ACL, Inline-Edit, Suche |
| `storefront.md` | Twig-Blöcke, SCSS, JS-Plugin-System, Page Subscriber, Dev-Server |
| `themes.md` | `theme.json`, Bootstrap-Overrides, Vererbung, CSS Custom Properties |
| `cart.md` | Warenkorb-Pipeline: Processors, Collectors, Validators, Rabatte |
| `seo-mail.md` | SEO-URLs, Sitemap, Mail-Templates, Dokumente, State Machine |
| `app-system.md` | Manifest, Webhooks, App Scripts |
| `api.md` | Muster für Admin API und Store API |
| `testing.md` | PHPUnit, Jest, Cypress |
| `security.md` | Auth, Route Scopes, CSRF, Validierung, Fehlerbehandlung |
| `performance.md` | Caching, Queues, Elasticsearch |
| `integrations.md` | Payment, Versandkosten, CMS, Rule Builder, Flow Builder |
| `advanced.md` | PHP-Attribute, Flysystem, Redis, Rate Limiter, IAP |
| `devops.md` | Logging, statische Analyse, CI/CD |
| `plugin-structure.md` | Vollständige Plugin-Anatomie |
| `migration-6.7.md` | Was 6.7 gebrochen hat, und was jeder Minor gebracht hat |

### Beispiele

In `skills/shopware6/examples/` liegen zwei lauffähige Plugins, echte Quelldateien statt Snippets:

- [`custom-entity/`](skills/shopware6/examples/custom-entity/) baut eine `wishlist_item`-Entity auf, samt des zusammengesetzten Fremdschlüssels, den `product` verlangt, weil sein Primärschlüssel `(id, version_id)` lautet
- [`storefront-subscriber/`](skills/shopware6/examples/storefront-subscriber/) ergänzt die Produktseite über einen Page Subscriber und ein Twig-Override um eine Box mit ähnlichen Artikeln

Kopiere eines davon nach `custom/plugins/` und führe `bin/console plugin:install --activate` aus.

### Mitwirken

Korrekturen sind besonders willkommen. Shopware entwickelt sich schnell, und eine Referenz, die im Juni noch stimmte, kann im Herbst falsch sein. Wenn dir ein Muster auffällt, das der Skill falsch wiedergibt, öffne bitte ein Issue oder einen PR auf [GitHub](https://github.com/dpaguba/shopware-skill).

</details>
