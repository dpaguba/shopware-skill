# App System: manifest.xml, Webhooks, App Scripts

The App System lets you extend Shopware without writing a PHP plugin. Your app lives on an external server; Shopware communicates via signed HTTP requests.

**Use App System when:** targeting the Shopware Store (SaaS), need zero-downtime updates, or building a multi-tenant integration.
**Use Plugin when:** need direct DB access, custom PHP services, or targeting self-hosted only.

---

## Directory Structure

```
custom/apps/MyVendorApp/
├── manifest.xml              # Required, defines everything
├── Resources/
│   ├── app/storefront/src/   # Optional: JS/SCSS assets
│   ├── views/storefront/     # Optional: Twig overrides
│   ├── config/config.xml     # Optional: Plugin-style config fields
│   ├── config/custom-fields.xml # Optional: custom field sets (SW 6.7.13+)
│   └── scripts/              # Optional: App Scripts (Twig-based)
└── icon.png                  # Optional: shown in admin
```

---

## manifest.xml: Complete Example

```xml
<?xml version="1.0" encoding="UTF-8"?>
<manifest xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/Framework/App/Manifest/Schema/manifest-3.0.xsd">

    <meta>
        <name>MyVendorApp</name>
        <label>My Vendor App</label>
        <label lang="de-DE">Meine Vendor App</label>
        <description>Short description</description>
        <description lang="de-DE">Kurze Beschreibung</description>
        <author>My Vendor Name</author>
        <copyright>(c) My Vendor</copyright>
        <version>1.0.0</version>
        <icon>icon.png</icon>
        <license>MIT</license>
        <compatibility>~6.7.0</compatibility>
        <privacy>https://my-vendor.com/privacy</privacy>
    </meta>

    <!-- App registration endpoint (required for server-side apps) -->
    <setup>
        <registrationUrl>https://my-app.com/shopware/register</registrationUrl>
        <secret>mySharedAppSecret</secret>
    </setup>

    <!-- Request only the minimum permissions needed -->
    <permissions>
        <read>product</read>
        <read>product_manufacturer</read>
        <read>order</read>
        <read>order_line_item</read>
        <create>order_transaction</create>
        <update>order</update>
    </permissions>

    <!-- Hosts your app talks to. Since 6.7.14 Extension SDK action and URI-signing targets
         must be absolute URLs on one of these hosts. -->
    <allowed-hosts>
        <host>my-app.com</host>
        <host>api.external-service.com</host>
    </allowed-hosts>

    <!-- Webhooks: Shopware calls your URL on these events -->
    <webhooks>
        <webhook name="orderPlaced"
                 url="https://my-app.com/webhooks/order-placed"
                 event="checkout.order.placed"/>
        <webhook name="productWritten"
                 url="https://my-app.com/webhooks/product-written"
                 event="product.written"/>
    </webhooks>

    <!-- Admin extensions -->
    <admin>
        <!-- Action button on product detail page -->
        <action-button action="syncProduct"
                       entity="product"
                       view="detail"
                       url="https://my-app.com/api/sync">
            <label>Sync Product</label>
        </action-button>

        <!-- Embedded iFrame module in admin sidebar -->
        <module name="my-app-dashboard"
                source="https://my-app.com/admin/dashboard"
                parent="sw-dashboard"
                position="50">
            <label>My App Dashboard</label>
        </module>
    </admin>

    <!-- Custom fields: Resources/config/custom-fields.xml (below). The inline <custom-fields>
         block is deprecated since SW 6.7.13 and removed in 6.8; keep it only for apps that
         must still install on older versions. -->

    <!-- Cookie consent registration -->
    <cookies>
        <cookie>
            <snippet-name>my_app.tracking.cookie</snippet-name>
            <cookie>my_app_session</cookie>
            <expiration>30</expiration>
        </cookie>
    </cookies>

    <!-- Payment method provided by your app.
         SW 6.7: manifest schema is manifest-3.0.xsd, <capture-url> was removed, use <pay-url>.
         Payment states are no longer set by default for async payments: return "status" (a
         transition action such as paid, authorize, process, fail, cancel) in the signed pay or
         finalize response, otherwise the transaction stays open. The finalize call delivers query
         parameters under `requestData`. Optional: <validate-url>, <refund-url>, <recurring-url>.
         Since 6.7.15 app updates no longer overwrite existing name/description translations. -->
    <payments>
        <payment-method>
            <identifier>my_app_payment</identifier>
            <name>My App Payment</name>
            <description>Pay via My App</description>
            <pay-url>https://my-app.com/payment/pay</pay-url>
            <finalize-url>https://my-app.com/payment/finalize</finalize-url>
        </payment-method>
    </payments>

</manifest>
```

### Custom fields: `Resources/config/custom-fields.xml`

Same syntax as the old inline block. Shopware prefers this file when both exist. See references/dal.md for the
field element list. Since 6.7.14 `media_folder` and `media_folder_configuration` are allowed related entities.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<custom-fields xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/CustomField/Schema/custom-fields-1.0.xsd">
    <custom-field-set>
        <name>my_app_product_fields</name>
        <label>My App Fields</label>
        <label lang="de-DE">Meine App Felder</label>
        <related-entities>
            <product/>
        </related-entities>
        <fields>
            <text name="my_app_external_id">
                <label>External ID</label>
                <required>false</required>
            </text>
            <bool name="my_app_sync_enabled">
                <label>Sync Enabled</label>
            </bool>
        </fields>
    </custom-field-set>
</custom-fields>
```

### Document types (SW 6.7.15+, experimental)

Apps can register document types for document generation v2 (`DOCUMENT_GENERATION_REWORK` flag). Shopware seeds a
number range per type; the `document-generation` app script hook can enrich the render data.

```xml
<documents>
    <document-type>
        <identifier>my_app_warranty</identifier>
        <label>Warranty certificate</label>
        <formats>
            <format>html</format>
            <format>pdf</format>
        </formats>
    </document-type>
</documents>
```

---

## Registration Handshake

When Shopware installs the app it performs a 3-step handshake:

### Step 1: Shopware → Your App (GET /shopware/register)

Shopware sends `GET <registrationUrl>?shop-id=...&shop-url=...&timestamp=...` with `shopware-app-signature`, an
HMAC-SHA256 of the query string keyed with the app secret (`<setup><secret>` locally, the Store secret otherwise).

```php
#[Route(path: '/shopware/register', methods: ['GET'])]
public function register(Request $request): JsonResponse
{
    // Raw query string: Request::getQueryString() re-normalizes it and breaks the HMAC
    $query     = (string) $request->server->get('QUERY_STRING');
    $signature = (string) $request->headers->get('shopware-app-signature', '');
    $expected  = hash_hmac('sha256', $query, $this->appSecret);

    if (!hash_equals($expected, $signature)) {
        return new JsonResponse(['error' => 'Invalid signature'], 401);
    }

    $shopId     = $request->query->getString('shop-id');
    $shopUrl    = $request->query->getString('shop-url');
    $shopSecret = bin2hex(random_bytes(32)); // per-shop secret for all later requests

    $this->shopRepository->savePending($shopId, $shopUrl, $shopSecret);

    // $appName must match the <name> tag in manifest.xml exactly
    $appName = 'MyVendorApp';
    return new JsonResponse([
        'proof'            => hash_hmac('sha256', $shopId . $shopUrl . $appName, $this->appSecret),
        'secret'           => $shopSecret,
        'confirmation_url' => 'https://my-app.com/shopware/confirm',
    ]);
}
```

### Step 2: Shopware → Your App (POST /shopware/confirm)

The JSON body (`apiKey`, `secretKey`, `timestamp`, `shopUrl`, `shopId`) is signed in `shopware-shop-signature` with
the per-shop secret you just returned. On re-registration a `shopware-shop-signature-previous` header signed with
the old secret is added. Since 6.7.14 the shop only switches to the new secret after this call answered 2xx, and
re-running `app:install` recovers an interrupted handshake, so keep the old secret until the confirm arrives.

```php
#[Route(path: '/shopware/confirm', methods: ['POST'])]
public function confirm(Request $request): JsonResponse
{
    $body    = $request->getContent();
    $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    $shop    = $this->shopRepository->findByShopId($payload['shopId']);

    $expected = hash_hmac('sha256', $body, $shop->getPendingSecret());
    if (!hash_equals($expected, (string) $request->headers->get('shopware-shop-signature', ''))) {
        return new JsonResponse(['error' => 'Invalid signature'], 401);
    }

    $shop->setApiKey($payload['apiKey']);
    $shop->setSecretKey($payload['secretKey']);
    $shop->commitPendingSecret();
    $this->shopRepository->save($shop);

    return new JsonResponse(['success' => true]);
}
```

### Step 3: Call Shopware Admin API from Your App

```php
$client = new GuzzleHttp\Client();
$response = $client->post($shopUrl . '/api/oauth/token', [
    'json' => [
        'grant_type'    => 'client_credentials',
        'client_id'     => $apiKey,
        'client_secret' => $secretKey,
    ],
]);

$token = json_decode($response->getBody(), true)['access_token'];
```

---

## Webhook Verification

Every webhook is a POST with a JSON body `{"data": {"payload": ..., "event": "..."}, "source": {"url", "shopId",
"appVersion", "eventId"}, "timestamp": ...}` and a `shopware-shop-signature` header (HMAC-SHA256 of the raw body
with the per-shop secret). Verify it:

```php
public function handleOrderPlaced(Request $request): JsonResponse
{
    $body    = $request->getContent();
    $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    $secret  = $this->shopRepository->getSecretByShopId($payload['source']['shopId'] ?? '');

    $signature = (string) $request->headers->get('shopware-shop-signature', '');
    $expected  = hash_hmac('sha256', $body, $secret);

    if (!hash_equals($expected, $signature)) {
        return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    // Business events (checkout.order.placed) carry their flow data by name
    $orderId = $payload['data']['payload']['order']['id'] ?? null;
    // Entity events (product.written) carry write results:
    // $payload['data']['payload'][0]['primaryKey']

    return new JsonResponse(['success' => true]);
}
```

> **SW 6.7.14.1.** `user.recovery.request`, `customer.recovery.request`, `mail.before.send` and
> `mail.after.create.message` are no longer delivered to webhooks (a manifest subscribing to them triggers a
> deprecation now and is rejected with `WebhookNotPermittedError` in 6.8). Removed from payloads: `contextToken`
> of `checkout.customer.login`, `confirmUrl` of the double opt-in events, `url` of `newsletter.register`, and
> customer confirmation hashes. All stay available in Flow Builder.

---

## App Scripts (Twig-based, no PHP server needed)

App Scripts run inside Shopware's process, so no external server is required. They use Twig with a restricted sandbox.

Place scripts in `Resources/scripts/{hook-name}/`:

```
Resources/scripts/
├── cart/
│   └── add-free-gift.twig          # Runs on every cart calculation
├── product-page-loaded/
│   └── add-custom-data.twig        # Runs after product page loads
└── store-api-my-endpoint/
    └── my-endpoint.twig            # Custom endpoint /store-api/script/my-endpoint
```

### Cart Manipulation Script

`services` is a global script variable; `hook` holds the hook data (`hook.cart` here).

```twig
{# Resources/scripts/cart/add-free-gift.twig #}
{% if services.cart.items.count > 0 and not services.cart.products.has('PRODUCT_ID') %}
    {# Add a product to the cart via the services.cart.products facade #}
    {% do services.cart.products.add('PRODUCT_ID', 1) %}
{% endif %}
```

### Product Page Custom Data

```twig
{# Resources/scripts/product-page-loaded/custom-data.twig #}
{% set page = hook.page %}
{% set product = page.product %}

{# Read custom fields #}
{% set externalId = product.customFields.my_app_external_id ?? null %}

{# Add data to page extension #}
{% do page.addArrayExtension('myAppData', {
    externalId: externalId,
    syncEnabled: product.customFields.my_app_sync_enabled ?? false
}) %}
```

Access in Twig template:

```twig
{{ page.extensions.myAppData.externalId }}
```

### Available Script Hooks

| Hook | When it fires |
|---|---|
| `cart` | Every cart calculation |
| `product-pricing` | Product prices are calculated |
| `product-page-loaded` | Product detail page load (most storefront pages have a `*-page-loaded` hook) |
| `checkout-cart-page-loaded` | Cart page load |
| `checkout-confirm-page-loaded` | Confirm page load |
| `cookie-group-collect` | Cookie groups collected (SW 6.7.14+): modify or remove groups and entries |
| `document-generation` | Document v2 rendering (SW 6.7.15+, experimental) |
| `api-{name}`, `store-api-{name}`, `storefront-{name}` | Custom endpoints `/api/script/{name}`, `/store-api/script/{name}`, `/storefront/script/{name}` |
| `app-installed`, `app-updated`, `app-activated`, `app-deactivated`, `app-deleted` | App lifecycle |

There is no order-placed script hook: react to `checkout.order.placed` with a webhook.

---

## Action Buttons

Action buttons appear in Admin entity detail/list pages and call your app's URL:

```xml
<admin>
    <action-button action="syncProduct"
                   entity="product"
                   view="detail"
                   url="https://my-app.com/api/sync">
        <label>Sync to ERP</label>
        <label lang="de-DE">An ERP synchronisieren</label>
    </action-button>

    <action-button action="bulkExport"
                   entity="product"
                   view="list"
                   url="https://my-app.com/api/bulk-export">
        <label>Export Selected</label>
    </action-button>
</admin>
```

`entity` is one of `product`, `order`, `category`, `promotion`, `customer`, `cms_page`; `view` is `detail` or `list`.
Shopware sends a POST signed with `shopware-shop-signature` with the selected entity IDs:

```json
{
    "source": {
        "url": "https://shop.example.com",
        "appVersion": "1.0.0",
        "shopId": "xyz"
    },
    "data": {
        "ids": ["product-uuid-1", "product-uuid-2"],
        "entity": "product",
        "action": "syncProduct"
    },
    "meta": {
        "timestamp": 1761650000,
        "reference": "random-uuid",
        "language": "language-uuid"
    }
}
```

Answer with an empty 2xx body, or with `{"actionType": "notification", "payload": {"status": "success", "message":
"Synced"}}` (also `openNewTab`, `reload`, `openModal`) signed in a `shopware-app-signature` header (HMAC-SHA256 of
the response body with the per-shop secret); unsigned responses with a body are rejected.

Since 6.7.14 Administration users and integrations need the `app.all` or `app.<AppName>` privilege to see the app's
modules and run its Extension SDK actions.

---

## Permission Reference

```xml
<permissions>
    <!-- Available: read, create, update, delete, crud (all four), permission (an extra ACL privilege) -->
    <read>product</read>
    <read>order</read>
    <read>customer</read>      <!-- Requires privacy policy disclosure! -->
    <update>order</update>
    <create>order_transaction</create>
    <delete>product_review</delete>
</permissions>
```

Request only what is needed. Over-permissioning is a rejection reason in the Shopware Store review.

---

## Testing App Registration Locally

```bash
# With APP_ENV=dev (SW 6.7.15+) shopware.app_system.enable_url_validation is false, so HTTP and
# private hosts work directly. Otherwise (and in prod) targets need HTTPS on a public host:
ngrok http 8000

# Update manifest.xml with ngrok URL:
# <registrationUrl>https://abc123.ngrok.io/shopware/register</registrationUrl>

# Validate and install in local Shopware:
bin/console app:validate MyVendorApp
bin/console app:install MyVendorApp --activate

# Check registration status:
bin/console app:list
```

Keep `enable_url_validation` enabled in production; while it is `false`, `allow_unencrypted_traffic` and
`allowed_private_ip_addresses` have no effect.
