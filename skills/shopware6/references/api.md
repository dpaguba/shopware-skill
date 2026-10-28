# API: Store API & Admin API

Shopware 6 provides two distinct APIs:

| | Store API | Admin API |
|---|---|---|
| Base path | `/store-api/` | `/api/` |
| Auth | Sales channel access key | Bearer token (OAuth) |
| Scope | Customer-facing data | Full platform management |
| Context | `SalesChannelContext` | `Context` |

---

## Admin API: Authentication

> **SW 6.7.** Non spec-compliant `/api/oauth/token` requests are rejected: send `Content-Type: application/json` or
> `application/x-www-form-urlencoded` with the exact grant parameters, and `scope` as a space-delimited string
> (`"write admin"`), not an array. RSA JWT secrets were removed. 6.7.0 removed the old `/api/oauth/authorize`;
> 6.7.15 reintroduces `GET /api/oauth/authorize` for the authorization code grant (below). 6.7.1 added the
> `sw-measurement-weight-unit` / `sw-measurement-length-unit` request headers to read and write product
> measurements in other units.


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

Integrations use `"grant_type": "client_credentials"` with `client_id` (access key) and `client_secret`.

### Browser login for CLI tools (SW 6.7.15+)

Registered public clients (Shopware ships `shopware-cli`) can use the authorization code grant with PKCE instead
of storing a password or integration secret:

1. Open `GET /api/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&code_challenge=...&code_challenge_method=S256`
   in the browser. The user signs in to the Administration and approves on its consent page.
2. Exchange the code: `POST /api/oauth/token` with `grant_type=authorization_code`, `client_id`, `code`,
   `redirect_uri`, `code_verifier`. Codes are single use. Refresh with `grant_type=refresh_token` and the same
   `client_id`; refresh tokens rotate and reusing an old one revokes the whole token family.

Register own clients in `config/packages/shopware.yaml` under `shopware.api.oauth_clients.<id>` (`name`,
`redirect_uris`). Loopback URIs (`http://127.0.0.1/callback`) accept any port, all others must match exactly.
Code lifetime: `shopware.api.auth_code_ttl` (default `PT5M`). An unregistered redirect URI returns 400
`FRAMEWORK__OAUTH_INVALID_REDIRECT_URI`.

---

## Admin API: CRUD via JSON:API

### List (with filters)

```bash
curl -X GET 'https://shopware.test/api/product' \
  -H 'Authorization: Bearer TOKEN' \
  -G \
  --data-urlencode 'filter[active]=1' \
  --data-urlencode 'sort=-createdAt' \
  --data-urlencode 'limit=25' \
  --data-urlencode 'page=1'
```

### Search (POST, more powerful)

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

## Admin API: Bulk Operations

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

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class MyStoreApiRoute extends AbstractMyRoute
{
    public function __construct(private readonly EntityRepository $myRepository) {}

    #[Route(
        path: '/store-api/my-plugin/my-route',
        name: 'store-api.my_plugin.my_route',
        methods: ['GET', 'POST'],
        defaults: ['_entity' => 'my_entity']
    )]
    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): MyRouteResponse
    {
        $result = $this->myRepository->search($criteria, $context->getContext());

        return new MyRouteResponse($result); // MyRouteResponse extends StoreApiResponse
    }
}
```

Create an abstract route to enable the decoration pattern, so other plugins can decorate your route:

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

Register route and decorator in `src/Resources/config/services.php` (XML service config is deprecated since
6.7.14 and removed in 6.8; PHP config works on 6.6 and 6.7 too):

```php
use VendorName\PluginName\Core\Content\MyRoute\MyStoreApiRoute;
use VendorName\PluginName\Decorator\MyStoreApiRouteDecorator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

$services->set(MyStoreApiRoute::class)
    ->args([service('my_entity.repository')])
    ->public();

$services->set(MyStoreApiRouteDecorator::class)
    ->decorate(MyStoreApiRoute::class)
    ->args([service('.inner')]);
```

A custom request header your frontend sends cross-origin must be added to the API CORS lists (SW 6.7.15+):
implement `Shopware\Core\Framework\Api\Cors\CorsHeaderProviderInterface::provide(CorsHeaders $headers)` and call
`$headers->addAllowed('x-my-header')` / `addExposed(...)`. Autoconfigured services are picked up, otherwise tag
with `shopware.api.cors_header_provider`. The lists are global, not scoped to your routes.

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

> **SW 6.7.14.** Many core `/api/_action/*` endpoints that only required a token now enforce ACL privileges
> (number ranges, order recalculation, SEO URLs, media actions, message queue, increments, mail and product
> export rendering, `trigger-event`). Integrations with a hand-picked privilege list get 403
> `FRAMEWORK__MISSING_PRIVILEGE_ERROR` until the privilege is added to their role. 6.7.14.1: cloning users and
> integrations via `/api/_action/clone/...` returns 403, and aggregation names containing a backtick, `?`, `:` or
> control characters are rejected with 400 `FRAMEWORK__INVALID_AGGREGATION_QUERY`.

---

## API Versioning

The Admin API does **not** use version prefixes. The `/api/v1/` path was removed in SW 6.4.0.0: all routes are at `/api/`. Do not use versioned prefixes.

Store API does not use versioning either; it is backward-compatible within a major release.

To fail fast on an incompatible shop, send `sw-expect-packages: shopware/core:~6.7.0,swag/paypal:*` with an
authenticated request (417 `FRAMEWORK__API_EXPECTATION_FAILED` on mismatch). Since 6.7.14 the header is rejected
with 417 `FRAMEWORK__API_EXPECTATION_NOT_SUPPORTED` on unauthenticated endpoints, including `POST /api/oauth/token`,
so do not set it as an HTTP client default.

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
| POST | `/api/_action/media/{mediaId}/upload?extension=jpg&fileName=x` | Upload a file into an existing media entity (binary body, or JSON `{"url": ...}`) |
| GET | `/api/_info/openapi3.json` | OpenAPI spec |

---

## Store API: Context Token Lifecycle

The Store API is stateless. All session state is carried via the `sw-context-token` header.

**Persist the token whenever a response carries `sw-context-token`**: it changes after login, registration,
logout or context switches. With `CACHE_REWORK` or the 6.8 flag active (SW 6.7.15+), cacheable reads no longer
echo the header, so keep the stored token when it is absent.

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
  ← sw-context-token: AUTH_TOKEN    (new token, update stored value!)

Customer logs out
  → POST /store-api/account/logout
    sw-context-token: AUTH_TOKEN
  ← sw-context-token: NEW_ANON_TOKEN (old token is invalid, store the new one)

Token expired / 403 received
  → GET /store-api/context           (no token)
  ← sw-context-token: NEW_ANON_TOKEN
```

### Required Headers

```
sw-access-key: <sales-channel-api-key>   # always required
sw-context-token: <token>                # required for cart, account, checkout
Content-Type: application/json           # for POST/PATCH
sw-language-id / sw-currency-id: <id>    # optional; unavailable currencies are rejected since 6.7.15
sw-include-seo-urls: 1                   # optional; adds seoUrls (part of Vary since 6.7.15)
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

## Store API: Cart Operations

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
# or, for clients that cannot send DELETE: POST /store-api/checkout/cart/line-item/delete {"ids": [...]}

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

## Store API: Checkout

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
# Returns: the order. A second submit of an already ordered cart answers
# 404 CHECKOUT__CART_TOKEN_NOT_FOUND (SW 6.7.15+)

# 3. Start the payment (always, also for non-redirect methods)
POST /store-api/handle-payment
{
  "orderId": "ORDER_UUID",
  "finishUrl": "https://my-shop.com/checkout/finish?orderId=ORDER_UUID",
  "errorUrl": "https://my-shop.com/checkout/confirm?orderId=ORDER_UUID"
}
# Returns: { "redirectUrl": "https://payment-provider.com/pay?..." } or { "redirectUrl": null }
# null: the handler finished in pay() (direct capture, invoice, prepayment), go to finishUrl
# otherwise redirect the customer there

# 4. The provider returns to /payment/finalize-transaction?_sw_payment_token=...
#    Shopware calls the handler's finalize() and redirects to finishUrl or errorUrl
#    (errorUrl gets ?error-code=...). Reload the order to show its payment state:
POST /store-api/order
{ "filter": [{"type": "equals", "field": "id", "value": "ORDER_UUID"}] }
```

---

## Store API: Customer Account

```bash
# Register new customer
POST /store-api/account/register
{
  "firstName": "Jane",
  "lastName": "Doe",
  "email": "jane@example.com",
  "password": "SecurePass123",
  "salutationId": "SALUTATION_UUID",
  "storefrontUrl": "https://my-shop.com",
  "billingAddress": {
    "street": "Main St 1",
    "zipcode": "12345",
    "city": "Berlin",
    "countryId": "COUNTRY_UUID"
  }
}
# storefrontUrl must be a sales channel domain (validated when double opt-in is on),
# acceptedDataProtection: true is required when the data protection checkbox is enabled
# Returns: customer + new sw-context-token

# Login
POST /store-api/account/login
{
  "email": "jane@example.com",
  "password": "SecurePass123"
}
# Returns: { "contextToken": "NEW_TOKEN" } + sw-context-token header
# ("username" is still read as a fallback for "email")

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

### Headless helpers (SW 6.7.14+)

| Route | Returns |
|---|---|
| `GET /store-api/shop-settings` | Non-sensitive system config for the sales channel (`general`, `loginRegistration`, `cart`, `listing`, `newsletter`) |
| `GET /store-api/snippet?prefixes=checkout,account` | Resolved snippets per language, `ETag`/`If-None-Match` aware. Experimental until 6.8 |
| `GET /store-api/product/{productId}/garan-label` | EU GARAN guarantee label as SVG |
| `GET /store-api/legal-guarantee-notice` | EU statutory legal guarantee notice |

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
        await this.post('/account/login', { email, password });
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
