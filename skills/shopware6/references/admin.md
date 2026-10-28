# Admin: Vue.js Extension System

Shopware Admin is a Vue 3 single-page application. Plugins extend it by registering components, modules, and overrides through the global `Shopware` object.

---

## Entry Point

Create `src/Resources/app/administration/src/main.js` (or `main.ts`):

```js
import './module/my-module';
```

The file is picked up when the plugin is active: `bin/console bundle:dump` lists it in `var/plugins.json`, and
`bin/build-administration.sh` builds every entry found there.

> **SW 6.7 breaking change.** The Administration build moved from Webpack to **Vite**. A plugin that ships
> a `build/webpack.config.js` must replace it with `src/Resources/app/administration/src/vite.config.mts`.
> Because the build output differs, an admin extension usually needs a separate plugin release for 6.7 and for 6.6.

---

## Module Registration

```js
import MyModuleIndex from './page/my-module-index';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('my-module', {
    type: 'plugin',
    name: 'My Module',
    title: 'my-module.general.label',
    description: 'my-module.general.description',
    color: '#ff3d00',
    icon: 'regular-shopping-bag',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'my-module-index',
            path: 'index',
            meta: {
                privilege: 'my_module.viewer',
            },
        },
        detail: {
            component: 'my-module-detail',
            path: 'detail/:id',
        },
    },

    navigation: [{
        label: 'my-module.general.label',
        color: '#ff3d00',
        path: 'my.module.index',
        icon: 'regular-shopping-bag',
        position: 100,
        parent: 'sw-catalogue',
    }],
});
```

Icons come from the Meteor icon kit (`regular-*` / `solid-*`); the old `default-*` names no longer exist.
The server also loads any `en.json` / `de.json` (country-agnostic, recommended since 6.7.3) or `en-GB.json`
file below `Resources/app/administration/src`, so the `snippets` import is optional.

---

## Component Registration

```js
import template from './my-module-index.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('my-module-index', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            items: null,
            isLoading: false,
        };
    },

    computed: {
        myEntityRepository() {
            return this.repositoryFactory.create('my_entity');
        },
        columns() {
            return [
                { property: 'name', label: this.$t('my-module.list.columnName') },
            ];
        },
    },

    created() {
        this.loadItems();
    },

    methods: {
        async loadItems() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            this.items = await this.myEntityRepository.search(criteria, Shopware.Context.api);
            this.isLoading = false;
        },
    },
});
```

Template (`my-module-index.html.twig`):

```twig
{% block my_module_index %}
<sw-page class="my-module-index">
    {% block my_module_index_smart_bar_header %}
    <template #smart-bar-header>
        <h2>{{ $t('my-module.general.label') }}</h2>
    </template>
    {% endblock %}

    {% block my_module_index_content %}
    <template #content>
        <sw-entity-listing
            :repository="myEntityRepository"
            :columns="columns"
            :is-loading="isLoading"
            :data-source="items"
        />
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

`sw-entity-listing` takes the result as `:data-source`. `:items` still works in 6.7 but logs a deprecation and is
removed in 6.8.

---

## Overriding Existing Components

Use `Component.override()` to extend existing components; never replace them wholesale:

```js
const { Component } = Shopware;

Component.override('sw-product-detail', {
    methods: {
        async saveProduct() {
            console.log('Before save');

            await this.$super('saveProduct');

            console.log('After save');
        },
    },
});
```

Use `this.$super('methodName')` to invoke the original implementation.

### Vue SFCs (SW 6.7.14+, experimental until 6.8)

Admin components can also be authored as `.vue` files. Every `.vue` file needs `<script setup>`: a base
component (`my-component.vue`) declares its extension surface with `swDefinePublic({ ... })`, and
`my-component.override.vue` overrides it with `useSwPreviousState()` plus `swDefineOverride({ ... })`. An SFC
override only works on a native-setup base; core components are still Options API in 6.7.15, so keep
`Component.override()` for them. `Component.createExtendableSetup()` and `overrideComponentSetup()` are
`@private` now; let the build transform emit them.

---

## Extending Existing Admin Blocks (Twig Slots)

To inject content into existing admin pages, extend component templates:

```js
import template from './extension.html.twig';

Shopware.Component.override('sw-product-detail-base', { template });
```

```twig
{# extension.html.twig: only override the blocks you need #}
{% block sw_product_detail_base_basic_info_card %}
    {% parent %}

    <my-custom-component :product-id="product.id" />
{% endblock %}
```

> **SW 6.7.15 shell rework.** `sw-page` gained a `sw_page_top_bar` block (`sw_page_top_bar_actions` is no longer
> inside `sw_page_search_bar`), the root of `sw_page_smart_bar` is now a `<div class="sw-page__smart-bar">`, the
> `.smart-bar__back-btn` styles are gone, and several `sw-admin-menu` blocks were removed. `sw-page` is now a CSS
> container, so `position: fixed` content inside it is positioned relative to the page.

---

## Data Binding with Repository Factory

```js
inject: ['repositoryFactory'],

computed: {
    productRepository() {
        return this.repositoryFactory.create('product');
    },
},

methods: {
    async loadProduct(id) {
        const criteria = new Criteria([id]);
        criteria.addAssociation('manufacturer');

        return this.productRepository.get(id, Shopware.Context.api, criteria);
    },

    async saveProduct(product) {
        return this.productRepository.save(product, Shopware.Context.api);
    },

    async createProduct() {
        return this.productRepository.create(Shopware.Context.api);
    },
},
```

**SW 6.7.14+:** shared lookups can be cached per read by passing `{ cacheKey: ['my-plugin', 'currencies'], ttl }`
as the third argument of `search()`; `forceReload: true` bypasses it, and
`Shopware.Service('cacheService').invalidateCaches({ cacheKey: ['my-plugin'] })` drops a key prefix.

---

## Snippets (i18n)

```json
{
    "my-module": {
        "general": {
            "label": "My Module",
            "description": "Manage my entities"
        },
        "list": {
            "columnName": "Name",
            "active": "Active",
            "inactive": "Inactive"
        }
    }
}
```

Use in templates:

```twig
{{ $t('my-module.general.label') }}
```

`$tc` still exists in 6.7 but only delegates to `$t` and is removed in 6.8.

---

## Common Admin Components

SW 6.7 switched the basic `sw-*` components to the **Meteor Component Library** (`mt-*`). The old names still
resolve in 6.7, but only as deprecated wrappers: most render the `mt-*` component (pass `deprecated` to get the
old one), while `sw-popover` and `sw-tabs` keep their legacy rendering unless you opt in (`use-meteor-component`
on `sw-tabs`). They are removed in 6.8 (`sw-tabs` in 6.9), so use `mt-*` directly. Shopware-specific components
that have no Meteor equivalent (`sw-page`, `sw-entity-listing`, `sw-data-grid`, `sw-entity-multi-select`) keep
their names.

| Component | Purpose |
|---|---|
| `<sw-page>` | Full page layout with smart bar |
| `<sw-entity-listing>` | Paginated data grid bound to a repository |
| `<sw-data-grid>` | Data grid without entity binding |
| `<sw-entity-multi-select>` | Multi entity picker |
| `<sw-modal>` | Modal dialog |
| `<mt-card>` | Content card container |
| `<mt-button>` | Standard button |
| `<mt-text-field>` / `<mt-number-field>` / `<mt-switch>` | Typed form fields |
| `<mt-select>` | Dropdown select |
| `<mt-checkbox>` / `<mt-datepicker>` / `<mt-colorpicker>` | Typed inputs |
| `<mt-loader>` | Loading spinner |
| `<mt-banner>` | Alert / notification box |
| `<mt-empty-state>` | Empty state (`headline`, `description`, `icon`) |
| `<mt-tabs>` | Tab navigation |
| `<mt-icon>` | Icon |
| `<mt-tooltip>` | Tooltip (plain strings and HTML only, no Vue components inside) |

Snackbars (SW 6.7.14+): `Shopware.Service('snackbarService').addSnackbar({ ... })` and `removeSnackbar(id)`.

### sw-* to mt-* migration map

| Deprecated in 6.7, removed in 6.8 | Replacement |
|---|---|
| `sw-button` | `mt-button` |
| `sw-card` | `mt-card` |
| `sw-icon` | `mt-icon` (no `small` / `large` props, use `size`) |
| `sw-text-field` | `mt-text-field` |
| `sw-textarea-field` | `mt-textarea` |
| `sw-number-field` | `mt-number-field` |
| `sw-switch-field` | `mt-switch` |
| `sw-checkbox-field` | `mt-checkbox` |
| `sw-select-field` | `mt-select` |
| `sw-email-field` | `mt-email-field` |
| `sw-url-field` | `mt-url-field` |
| `sw-password-field` | `mt-password-field` |
| `sw-datepicker` | `mt-datepicker` |
| `sw-colorpicker` | `mt-colorpicker` |
| `sw-progress-bar` | `mt-progress-bar` |
| `sw-loader` | `mt-loader` |
| `sw-tabs` (removed in 6.9) | `mt-tabs` (`isVertical` renamed to `vertical`, `alignRight` removed, `items` prop instead of `sw-tabs-item` children) |
| `sw-popover` | `mt-floating-ui` (no `zIndex`, `resizeWidth`, `popoverClass`; not open by default) |
| `sw-skeleton-bar` | `mt-skeleton-bar` |
| `sw-alert` | `mt-banner` |
| `sw-empty-state` | `mt-empty-state` (`title` / `subline` become `headline` / `description`, `icon` is required) |

`sw-external-link` is not part of this list: it still exists in 6.7, and Meteor's closest match is
`<mt-link type="external">`.

**Prop and event renames that apply across all `mt-*` fields:**

| Old (`sw-*`) | New (`mt-*`) |
|---|---|
| `:value="x"` | `:model-value="x"` |
| `v-model:value="x"` | `v-model="x"` |
| `@update:value` | `@update:model-value` |
| `<template #label>` slot | `label` prop |
| `<template #hint>` slot | removed |
| `size="medium"` | removed value |
| `isInvalid` prop | removed |
| `id`, `ghostValue`, `padded` props | removed |

`mt-button` also lost the `ghost`, `danger`, `ghost-danger`, `contrast` and `context` variants and the
`router-link` prop. Valid variants are `primary`, `secondary`, `tertiary`, `critical` and `action`; navigate with a
`@click` handler that calls `this.$router.push()`.

---

## Accessing the Shopware API from Admin

```js
const { ApiService } = Shopware.Classes;

class MyApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'my-endpoint');
    }

    getData() {
        return this.httpClient.get('/_action/my-plugin/my-endpoint', {
            headers: this.getBasicHeaders(),
        });
    }
}

Shopware.Application.addServiceProvider('myApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new MyApiService(initContainer.httpClient, container.loginService);
});
```

---

## Meteor Admin SDK (6.6+)

For App System or external extensions, use the Meteor Admin SDK:

```js
import { location } from '@shopware-ag/meteor-admin-sdk';

location.startAutoResizer();

import { data } from '@shopware-ag/meteor-admin-sdk';
const subscriptionToken = data.subscribe('sw-product-detail__product', ({ data: product }) => {
    console.log(product);
}, { selectors: ['id', 'name'] });
```

The first argument is a dataset id that the Admin publishes (`Shopware.ExtensionAPI.publishData`), not an entity
name. App (iframe) extensions must request the fields they need via `selectors`.

---

## ACL / Privileges

Define privilege keys in JS so your module appears in the Admin role editor. Use in component:

```js
inject: ['acl'],

computed: {
    canEdit() {
        return this.acl.can('my_module.editor');
    },
},
```

---

## Building Admin Assets

```bash
# Full rebuild (project root)
bin/build-administration.sh

# Vite dev server with hot reload
bin/watch-administration.sh
```

A plugin needs no extra flag in its `package.json`: it is built once it is active and has
`src/Resources/app/administration/src/main.js` (or `main.ts`). If `src/Resources/app/administration/package.json`
exists and has no `node_modules` yet, the watch script installs its dependencies first.

**SW 6.7.14+ (experimental):** `composer admin:setup-extension-tooling` (Composer installs:
`bin/console administration:setup-extension-tooling`) generates TypeScript and ESLint configs against the installed
Shopware version, and `composer admin:check-extensions` runs `vue-tsc` and ESLint per extension; `-- --fix` applies
the `sw-*` to `mt-*` codemods.

---

## Naming Conventions

Shopware Admin uses three different naming formats depending on context:

| Context | Format | Example |
|---|---|---|
| Module ID | kebab-case | `my-plugin-webhook` |
| Component name | kebab-case | `my-plugin-webhook-list` |
| Route name | dot-notation | `my.plugin.webhook.index` |
| Route path | module ID split on `-` into segments | `/my/plugin/webhook/index` |
| ACL key | snake_case | `my_plugin_webhook` |
| Snippet key | dot-notation | `my-plugin.webhook.title` |

```js
Shopware.Module.register('my-plugin-webhook', {   // kebab-case
    routes: {
        index: {
            path: 'index',                          // becomes /my/plugin/webhook/index
            name: 'my.plugin.webhook.index',        // dot-notation, NOT used here: always auto-generated
            component: 'my-plugin-webhook-list',    // kebab-case component
        },
    },
    navigation: [{
        path: 'my.plugin.webhook.index',            // dot-notation for path reference
    }],
});
```

---

## ACL Privileges: Registration

Define privilege keys so your module appears in the Admin role editor.

Create `src/Resources/app/administration/src/module/my-module/acl/index.js` and import it from the module's
`index.js` (`import './acl';`):

```js
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'my_plugin_webhook',      // snake_case
    roles: {
        viewer: {
            privileges: ['my_plugin_webhook:read'],
            dependencies: [],
        },
        editor: {
            privileges: ['my_plugin_webhook:update'],
            dependencies: ['my_plugin_webhook.viewer'],
        },
        creator: {
            privileges: ['my_plugin_webhook:create'],
            dependencies: ['my_plugin_webhook.editor'],
        },
        deleter: {
            privileges: ['my_plugin_webhook:delete'],
            dependencies: ['my_plugin_webhook.editor'],
        },
    },
});
```

Use in components:

```js
inject: ['acl'],

computed: {
    canView()   { return this.acl.can('my_plugin_webhook.viewer'); },
    canEdit()   { return this.acl.can('my_plugin_webhook.editor'); },
    canCreate() { return this.acl.can('my_plugin_webhook.creator'); },
    canDelete() { return this.acl.can('my_plugin_webhook.deleter'); },
},
```

---

## List Page: Filter, Inline Edit, Delete Workflow

### Critical sw-entity-listing Props

```twig
{# my-module-list.html.twig #}
{% block my_module_list_content %}
<sw-entity-listing
    :repository="myEntityRepository"
    :columns="columns"
    :data-source="items"
    :is-loading="isLoading"
    :full-page="false"
    :show-selection="false"
    :disable-data-fetching="true"
    :allow-inline-edit="canEdit"
    :allow-delete="canDelete"
    @inline-edit-save="onInlineEditSave"
    @delete-item-failed="onDeleteFailed"
>
    <template #column-status="{ item }">
        <sw-label :variant="item.active ? 'success' : 'danger'">
            {{ item.active ? $t('my-module.list.active') : $t('my-module.list.inactive') }}
        </sw-label>
    </template>
</sw-entity-listing>
{% endblock %}
```

`:full-page="false"` prevents absolute positioning that hides the grid inside cards.
`:disable-data-fetching="true"` lets your component control when data loads.

### Column Definition with Inline Edit

```js
computed: {
    columns() {
        return [
            {
                property:   'name',
                label:      this.$t('my-module.list.columnName'),
                inlineEdit: 'string',   // 'string' | 'number' | 'boolean'
                sortable:   true,
                primary:    true,
            },
            {
                property:   'priority',
                label:      this.$t('my-module.list.columnPriority'),
                inlineEdit: 'number',
                sortable:   true,
            },
            {
                property:   'active',
                label:      this.$t('my-module.list.active'),
                inlineEdit: 'boolean',
            },
        ];
    },
},
```

### Delete Confirmation Workflow

```js
data() {
    return {
        showDeleteModal: null,   // holds the ID of the item to delete
    };
},

methods: {
    onDeleteItem(id) {
        this.showDeleteModal = id;
    },

    onConfirmDelete() {
        this.myEntityRepository
            .delete(this.showDeleteModal, Shopware.Context.api)
            .then(() => {
                this.showDeleteModal = null;
                this.loadItems();
            });
    },

    onCancelDelete() {
        this.showDeleteModal = null;
    },
},
```

---

## Admin Search Configuration

Register your entity in the Admin global search bar. The configuration is keyed by the module's `entity`:

```js
Shopware.Module.register('my-plugin-webhook', {
    entity: 'my_plugin_webhook',
    defaultSearchConfiguration: {
        _searchable: true,
        name: {
            _searchable: true,
            _score: 500,
        },
    },
});
```

Or use the ranking constants:

```js
import { searchRankingPoint } from 'src/app/service/search-ranking.service';

Shopware.Module.register('my-plugin-webhook', {
    entity: 'my_plugin_webhook',
    defaultSearchConfiguration: {
        _searchable: true,
        name: {
            _searchable: true,
            _score: searchRankingPoint.HIGH_SEARCH_RANKING,
        },
    },
});
```

---

## State Management

**SW 6.7:** every core store is Pinia (`Shopware.Store`), so `Shopware.State.get('swProductDetail')` and other core
Vuex modules are gone. `Shopware.State` itself still exists for your own Vuex modules, but it is deprecated and
removed together with Vuex in 6.8. The Vue 2 compatibility layer was removed.

### Pinia (SW 6.6+, required for core stores in 6.7)

```js
Shopware.Store.register({
    id: 'myModule',
    state: () => ({ myData: null }),
    actions: {
        setMyData(data) { this.myData = data; },
    },
});

computed: {
    myData() { return Shopware.Store.get('myModule').myData; },
},
methods: {
    updateData(data) { Shopware.Store.get('myModule').setMyData(data); },
},
```

### Vuex (deprecated, removed in 6.8)

```js
Shopware.State.registerModule('myModule', {
    namespaced: true,
    state: () => ({ myData: null }),
    mutations: {
        setMyData(state, data) { state.myData = data; },
    },
});

// read: Shopware.State.get('myModule').myData
// write: Shopware.State.commit('myModule/setMyData', data)
```

When porting to 6.7, the Vuex helper functions were renamed as well: `mapState` became `mapVuexState`,
and the equivalent Pinia helpers took over the original names. `vue-i18n` was upgraded to v10, which
removed the standalone `tc` function; `$tc` still exists on components but delegates to `t` and is removed in 6.8.
