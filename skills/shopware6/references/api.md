# API — Store API & Admin API

Shopware 6 provides two distinct APIs:

| | Store API | Admin API |
|---|---|---|
| Base path | `/store-api/` | `/api/` |
| Auth | Sales channel access key | Bearer token (OAuth) |
| Scope | Customer-facing data | Full platform management |
| Context | `SalesChannelContext` | `Context` |

---

## Admin API — Authentication

```bash
# Get token
curl -X POST https://shopware.test/api/oauth/token \
  -H 'Content-Type: application/json' \
  -d '{
    "grant_type": "password",
    "client_id": "administration",
    "username": "admin",
    "password": "shopware",
    "scope": "write"
  }'
```

Response: `{"access_token": "eyJ...", "token_type": "Bearer", ...}`

Use in subsequent requests:

```bash
curl -X GET https://shopware.test/api/product \
  -H 'Authorization: Bearer eyJ...' \
  -H 'Accept: application/json'
```

---

## Admin API — CRUD via JSON:API

### List (with filters)

```bash
curl -X GET 'https://shopware.test/api/product' \
  -H 'Authorization: Bearer TOKEN' \
  -G \
  --data-urlencode 'filter[active]=1' \
  --data-urlencode 'sort=-createdAt' \
  --data-urlencode 'page[limit]=25'
```

### Search (POST — more powerful)

```bash
curl -X POST 'https://shopware.test/api/search/product' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "filter": [
      {"type": "equals", "field": "active", "value": true},
      {"type": "range", "field": "stock", "parameters": {"gte": 1}}
    ],
    "associations": {
      "manufacturer": {},
      "categories": {}
    },
    "sort": [{"field": "name", "order": "ASC"}],
    "limit": 25,
    "page": 1,
    "total-count-mode": 1
  }'
```

### Create

```bash
curl -X POST 'https://shopware.test/api/product' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Test Product",
    "productNumber": "SW-001",
    "stock": 100,
    "price": [{"currencyId": "CURRENCY_ID", "gross": 19.99, "net": 16.80, "linked": true}],
    "taxId": "TAX_ID",
    "visibilities": [{"salesChannelId": "SC_ID", "visibility": 30}]
  }'
```

### Update

```bash
curl -X PATCH 'https://shopware.test/api/product/{id}' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"stock": 50}'
```

### Delete

```bash
curl -X DELETE 'https://shopware.test/api/product/{id}' \
  -H 'Authorization: Bearer TOKEN'
```

---

## Admin API — Bulk Operations

```bash
curl -X POST 'https://shopware.test/api/_action/sync' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "write-product": {
      "entity": "product",
      "action": "upsert",
      "payload": [
        {"id": "ID1", "stock": 10},
        {"id": "ID2", "stock": 20}
      ]
    }
  }'
```

---

## Store API

```bash
# List products (requires sw-access-key header)
curl -X POST 'https://shopware.test/store-api/product' \
  -H 'sw-access-key: SALESCHANNEL_ACCESS_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "filter": [{"type": "equals", "field": "active", "value": true}],
    "limit": 10
  }'

# Product detail
curl -X POST 'https://shopware.test/store-api/product/{productId}' \
  -H 'sw-access-key: SALESCHANNEL_ACCESS_KEY'

# Add to cart
curl -X POST 'https://shopware.test/store-api/checkout/cart/line-item' \
  -H 'sw-access-key: SALESCHANNEL_ACCESS_KEY' \
  -H 'sw-context-token: CONTEXT_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "items": [{
      "type": "product",
      "referencedId": "PRODUCT_ID",
      "quantity": 1
    }]
  }'
```

---

## Custom Store API Route

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Content\MyRoute;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class MyStoreApiRoute extends AbstractMyRoute
{
    #[Route(
        path: '/store-api/my-plugin/my-route',
        name: 'store-api.my_plugin.my_route',
        methods: ['GET', 'POST'],
        defaults: ['_entity' => 'my_entity']
    )]
    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): MyRouteResponse
    {
        $result = $this->myRepository->search($criteria, $context->getContext());

        return new MyRouteResponse($result);
    }
}
```

Create an abstract route to enable the decoration pattern — other plugins can decorate your route:

```php
abstract class AbstractMyRoute
{
    abstract public function getDecorated(): AbstractMyRoute;
    abstract public function load(Request $request, SalesChannelContext $context, Criteria $criteria): MyRouteResponse;
}
```

The concrete implementation throws `DecorationPatternException` (it is the innermost, nothing to decorate):

```php
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class MyStoreApiRoute extends AbstractMyRoute
{
    public function getDecorated(): AbstractMyRoute
    {
        throw new DecorationPatternException(self::class);
    }
}
```

A decorator calls the inner route:

```php
class MyStoreApiRouteDecorator extends AbstractMyRoute
{
    public function __construct(private readonly AbstractMyRoute $decorated) {}

    public function getDecorated(): AbstractMyRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): MyRouteResponse
    {
        $response = $this->decorated->load($request, $context, $criteria);
        return $response;
    }
}
```

Register decorator in `services.xml`:

```xml
<service id="VendorName\PluginName\Decorator\MyStoreApiRouteDecorator"
         decorates="VendorName\PluginName\Core\Content\MyRoute\MyStoreApiRoute">
    <argument type="service"
              id="VendorName\PluginName\Decorator\MyStoreApiRouteDecorator.inner"/>
</service>
```

---

## Custom Admin API Action

```php
#[Route(defaults: ['_routeScope' => ['api']])]
class MyAdminController extends AbstractController
{
    #[Route(
        path: '/api/_action/my-plugin/sync',
        name: 'api.action.my_plugin.sync',
        methods: ['POST'],
        defaults: ['_acl' => ['my_plugin:update']]
    )]
    public function sync(Request $request, Context $context): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        return new JsonResponse(['success' => true]);
    }
}
```

---

## API Versioning

The Admin API does **not** use version prefixes. The `/api/v1/` path was removed in SW 6.4.0.0 — all routes are at `/api/`. Do not use versioned prefixes.

Store API does not use versioning — it is backward-compatible within a major release.

---

## API Filter Types

```json
{"type": "equals",    "field": "active",      "value": true}
{"type": "equalsAny", "field": "id",          "value": ["id1", "id2"]}
{"type": "contains",  "field": "name",        "value": "shirt"}
{"type": "prefix",    "field": "productNumber","value": "SW-"}
{"type": "range",     "field": "stock",       "parameters": {"gte": 1, "lte": 100}}
{"type": "not",       "operator": "and",      "queries": [...]}
{"type": "multi",     "operator": "or",       "queries": [...]}
```

---

## API Associations

```json
{
  "associations": {
    "manufacturer": {},
    "categories": {
      "limit": 10,
      "sort": [{"field": "name"}]
    },
    "media": {
      "associations": {
        "thumbnails": {}
      }
    }
  }
}
```

---

## Useful Admin API Endpoints

| Method | Path | Description |
|---|---|---|
| GET/POST | `/api/product` | Product CRUD |
| GET/POST | `/api/category` | Category CRUD |
| GET/POST | `/api/order` | Order management |
| GET/POST | `/api/customer` | Customer management |
| GET/POST | `/api/media` | Media assets |
| GET/POST | `/api/sales-channel` | Sales channels |
| POST | `/api/search/{entity}` | Advanced search |
| POST | `/api/_action/sync` | Bulk operations |
| POST | `/api/_action/media/{mediaId}/upload-url` | Media upload |
| GET | `/api/_info/openapi3.json` | OpenAPI spec |

---

## Store API — Context Token Lifecycle

The Store API is stateless. All session state is carried via the `sw-context-token` header.

**Always persist the token returned in every response** — it can change after login, registration, or context switches.

```
Anonymous visit
  → GET /store-api/context          (no token)
  ← sw-context-token: ANON_TOKEN    (persist this)

Guest adds to cart
  → POST /store-api/checkout/cart/line-item
    sw-context-token: ANON_TOKEN
  ← sw-context-token: ANON_TOKEN    (usually same)

Customer logs in (pass old token to trigger cart merge)
  → POST /store-api/account/login
    sw-context-token: ANON_TOKEN
  ← sw-context-token: AUTH_TOKEN    (new token — update stored value!)

Customer logs out
  → POST /store-api/account/logout
    sw-context-token: AUTH_TOKEN
  ← 204 No Content                  (token is now invalid — get a fresh one)

Token expired / 403 received
  → GET /store-api/context           (no token)
  ← sw-context-token: NEW_ANON_TOKEN
```

### Required Headers

```
sw-access-key: <sales-channel-api-key>   # always required
sw-context-token: <token>                # required for cart, account, checkout
Content-Type: application/json           # for POST/PATCH
```

### Get or Create Context

```bash
GET /store-api/context

# Response includes current context + token in header:
# sw-context-token: abc123
```

### Modify Context (language, currency, payment method)

```bash
PATCH /store-api/context
{
  "currencyId": "...",
  "languageId": "...",
  "paymentMethodId": "...",
  "shippingMethodId": "..."
}
# Response: updated sw-context-token header
```

---

## Store API — Cart Operations

```bash
# Get current cart
GET /store-api/checkout/cart
sw-context-token: TOKEN

# Add product to cart
POST /store-api/checkout/cart/line-item
{
  "items": [{
    "type": "product",
    "referencedId": "PRODUCT_UUID",
    "quantity": 2
  }]
}

# Update quantity
PATCH /store-api/checkout/cart/line-item
{
  "items": [{
    "id": "LINE_ITEM_ID",
    "quantity": 5
  }]
}

# Remove item
DELETE /store-api/checkout/cart/line-item?ids[]=LINE_ITEM_ID

# Add promotion code
POST /store-api/checkout/cart/line-item
{
  "items": [{
    "type": "promotion",
    "referencedId": "PROMO-CODE"
  }]
}

# Empty cart
DELETE /store-api/checkout/cart
```

---

## Store API — Checkout

```bash
# 1. Set shipping address (or use existing customer address)
PATCH /store-api/context
{
  "shippingAddressId": "ADDRESS_UUID"
}

# 2. Create order from cart
POST /store-api/checkout/order
{
  "customerComment": "Please deliver between 9-17"
}
# Returns: order with payment URL if needed

# 3a. Handle immediate payment (synchronous)
# Order state moves to "paid" automatically

# 3b. Handle redirect payment (asynchronous)
POST /store-api/handle-payment
{
  "orderId": "ORDER_UUID",
  "finishUrl": "https://my-shop.com/checkout/finish?orderId=ORDER_UUID",
  "errorUrl": "https://my-shop.com/checkout/confirm?orderId=ORDER_UUID"
}
# Returns: { "redirectUrl": "https://payment-provider.com/pay?..." }
# Redirect customer there

# 4. After payment provider redirects back — verify
GET /store-api/checkout/finish?orderId=ORDER_UUID
```

---

## Store API — Customer Account

```bash
# Register new customer
POST /store-api/account/register
{
  "firstName": "Jane",
  "lastName": "Doe",
  "email": "jane@example.com",
  "password": "SecurePass123",
  "salutationId": "SALUTATION_UUID",
  "billingAddress": {
    "street": "Main St 1",
    "zipcode": "12345",
    "city": "Berlin",
    "countryId": "COUNTRY_UUID"
  }
}
# Returns: customer + new sw-context-token

# Login
POST /store-api/account/login
{
  "username": "jane@example.com",
  "password": "SecurePass123"
}
# Returns: { "contextToken": "NEW_TOKEN" } + sw-context-token header

# Get profile
GET /store-api/account/customer
sw-context-token: AUTH_TOKEN

# Update profile
POST /store-api/account/change-profile
{
  "firstName": "Jane",
  "lastName": "Smith",
  "salutationId": "SALUTATION_UUID"
}

# Change password
POST /store-api/account/change-password
{
  "password": "OldPass123",
  "newPassword": "NewPass456",
  "newPasswordConfirm": "NewPass456"
}

# List orders
POST /store-api/order
{
  "filter": [{"type": "equals", "field": "transactions.stateMachineState.technicalName", "value": "paid"}],
  "limit": 10
}

# Logout
POST /store-api/account/logout

# Get addresses
POST /store-api/account/list-address

# Create address
POST /store-api/account/address
{
  "firstName": "Jane",
  "lastName": "Doe",
  "street": "New Street 5",
  "zipcode": "54321",
  "city": "Hamburg",
  "countryId": "COUNTRY_UUID"
}

# Set default shipping address
PATCH /store-api/account/address/default-shipping/ADDRESS_UUID
```

---

## TypeScript Store API Client Pattern

For headless frontends, wrap the Store API with a typed client:

```typescript
class StoreApiClient {
    private contextToken: string | null = null;

    constructor(
        private readonly baseUrl: string,
        private readonly accessKey: string,
    ) {}

    private getHeaders(): HeadersInit {
        const headers: HeadersInit = {
            'sw-access-key': this.accessKey,
            'Content-Type': 'application/json',
        };

        if (this.contextToken) {
            headers['sw-context-token'] = this.contextToken;
        }

        return headers;
    }

    private updateToken(response: Response): void {
        const newToken = response.headers.get('sw-context-token');
        if (newToken) {
            this.contextToken = newToken;
            // Persist to cookie / localStorage / server-side session
        }
    }

    async get<T>(path: string): Promise<T> {
        const response = await fetch(`${this.baseUrl}/store-api${path}`, {
            method: 'GET',
            headers: this.getHeaders(),
        });

        this.updateToken(response);

        if (!response.ok) {
            const error = await response.json();
            throw new StoreApiError(response.status, error.errors);
        }

        return response.json();
    }

    async post<T>(path: string, body?: unknown): Promise<T> {
        const response = await fetch(`${this.baseUrl}/store-api${path}`, {
            method: 'POST',
            headers: this.getHeaders(),
            body: body ? JSON.stringify(body) : undefined,
        });

        this.updateToken(response);

        if (!response.ok) {
            const error = await response.json();
            throw new StoreApiError(response.status, error.errors);
        }

        return response.json();
    }

    async login(email: string, password: string): Promise<void> {
        await this.post('/account/login', { username: email, password });
        // Token automatically updated via updateToken()
    }
}

class StoreApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly errors: Array<{ code: string; detail: string }>,
    ) {
        super(errors[0]?.detail ?? 'Store API error');
    }
}
```
