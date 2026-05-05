# Admin — Vue.js Extension System

Shopware Admin is a Vue 3 single-page application. Plugins extend it by registering components, modules, and overrides through the global `Shopware` object.

---

## Entry Point

Create `src/Resources/app/administration/main.js`:

```js
import './src/module/my-module';
```

Webpack picks this up automatically via `build-administration.sh`.

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
    icon: 'default-shopping-paper-bag',

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
        icon: 'default-shopping-paper-bag',
        position: 100,
        parent: 'sw-catalogue',
    }],
});
```

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
                { property: 'name', label: this.$tc('my-module.list.columnName') },
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
        <h2>{{ $tc('my-module.general.label') }}</h2>
    </template>
    {% endblock %}

    {% block my_module_index_content %}
    <template #content>
        <sw-entity-listing
            :repository="myEntityRepository"
            :columns="columns"
            :is-loading="isLoading"
            :items="items"
        />
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

---

## Overriding Existing Components

Use `Component.override()` to extend existing components — never replace them wholesale:

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

---

## Extending Existing Admin Blocks (Twig Slots)

To inject content into existing admin pages, extend component templates:

```js
import template from './extension.html.twig';

Shopware.Component.override('sw-product-detail-base', { template });
```

```twig
{# extension.html.twig — only override the blocks you need #}
{% block sw_product_detail_base_content_form %}
    {{ parent() }}

    <my-custom-component :product-id="product.id" />
{% endblock %}
```

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
            "columnName": "Name"
        }
    }
}
```

Use in templates:

```twig
{{ $tc('my-module.general.label') }}
{{ $t('my-module.general.label') }}
```

---

## Common Admin Components

| Component | Purpose |
|---|---|
| `<sw-page>` | Full page layout with smart bar |
| `<sw-card>` | Content card container |
| `<sw-entity-listing>` | Paginated data grid |
| `<sw-data-grid>` | Data grid without entity binding |
| `<sw-button>` | Standard button |
| `<sw-modal>` | Modal dialog |
| `<sw-text-field>` / `<sw-number-field>` / `<sw-switch-field>` | Typed form fields (deprecated `<sw-field>` wrapper removed in SW 6.7, avoid in 6.5+) |
| `<sw-select>` | Dropdown select |
| `<sw-entity-multi-select>` | Multi entity picker |
| `<sw-loader>` | Loading spinner |
| `<sw-alert>` | Alert/notification box |
| `<sw-tabs>` | Tab navigation |

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
const subscriptionToken = data.subscribe('product', (product) => {
    console.log(product);
});
```

---

## ACL / Privileges

Define privilege keys in JS so your module appears in the Admin role editor. Use in component:

```js
computed: {
    canEdit() {
        return this.acl.can('my_module.editor');
    },
},
```

---

## Building Admin Assets

```bash
# Full rebuild
bin/build-administration.sh

# Development watch mode (from Shopware root)
cd vendor/shopware/platform
./bin/build-administration.sh --watch
```

Plugins must have `"shopware-administration-mode": "plugin"` or equivalent in their `package.json` to be picked up by the build process.

---

## Naming Conventions

Shopware Admin uses three different naming formats depending on context:

| Context | Format | Example |
|---|---|---|
| Module ID | kebab-case | `my-plugin-webhook` |
| Component name | kebab-case | `my-plugin-webhook-list` |
| Route name | dot-notation | `my.plugin.webhook.index` |
| Route path | kebab-case | `my-plugin/webhook/index` |
| ACL key | snake_case | `my_plugin_webhook` |
| Snippet key | dot-notation | `my-plugin.webhook.title` |

```js
Shopware.Module.register('my-plugin-webhook', {   // kebab-case
    routes: {
        index: {
            path: 'index',                          // kebab segments
            name: 'my.plugin.webhook.index',        // dot-notation — NOT used here, auto-generated
            component: 'my-plugin-webhook-list',    // kebab-case component
        },
    },
    navigation: [{
        path: 'my.plugin.webhook.index',            // dot-notation for path reference
    }],
});
```

---

## ACL Privileges — Registration

Define privilege keys so your module appears in the Admin role editor.

Create `src/Resources/app/administration/src/module/my-module/acl/index.js`:

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
computed: {
    canView()   { return this.acl.can('my_plugin_webhook.viewer'); },
    canEdit()   { return this.acl.can('my_plugin_webhook.editor'); },
    canCreate() { return this.acl.can('my_plugin_webhook.creator'); },
    canDelete() { return this.acl.can('my_plugin_webhook.deleter'); },
},
```

---

## List Page — Filter, Inline Edit, Delete Workflow

### Critical sw-entity-listing Props

```twig
{# my-module-list.html.twig #}
{% block my_module_list_content %}
<sw-entity-listing
    :repository="myEntityRepository"
    :columns="columns"
    :items="items"
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
            {{ item.active ? $tc('global.default.active') : $tc('global.default.inactive') }}
        </sw-label>
    </template>
</sw-entity-listing>
{% endblock %}
```

`:full-page="false"` — prevents absolute positioning that hides the grid inside cards.
`:disable-data-fetching="true"` — lets your component control when data loads.

### Column Definition with Inline Edit

```js
computed: {
    columns() {
        return [
            {
                property:   'name',
                label:      this.$tc('my-module.list.columnName'),
                inlineEdit: 'string',   // 'string' | 'number' | 'boolean'
                sortable:   true,
                primary:    true,
            },
            {
                property:   'priority',
                label:      this.$tc('my-module.list.columnPriority'),
                inlineEdit: 'number',
                sortable:   true,
            },
            {
                property:   'active',
                label:      this.$tc('global.default.active'),
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

Register your entity in the Admin global search bar:

```js
Shopware.Module.register('my-plugin-webhook', {
    defaultSearchConfiguration: {
        _searchable: true,
        name: {
            _searchable: true,
            _score: 500,
        },
    },
});
```

Or configure globally:

```js
import { searchRankingPoint } from 'src/app/service/search-ranking.service';

Shopware.Module.register('my-plugin-webhook', {
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

**SW 6.6+:** Use Pinia via `Shopware.Store.register()` for new modules. The Vuex-based `Shopware.State` API is deprecated in 6.6 and will be removed in 6.7.

### Pinia (SW 6.6+, recommended)

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

### Vuex (SW 6.5, deprecated in 6.6)

```js
Shopware.State.registerModule('myModule', {
    namespaced: true,
    state: () => ({
        myData: null,
    }),
    mutations: {
        setMyData(state, data) {
            state.myData = data;
        },
    },
    getters: {
        myData: (state) => state.myData,
    },
});

computed: {
    myData() {
        return Shopware.State.get('myModule').myData;
    },
},
methods: {
    updateData(data) {
        Shopware.State.commit('myModule/setMyData', data);
    },
},
```
