# App System — manifest.xml, Webhooks, App Scripts

The App System lets you extend Shopware without writing a PHP plugin. Your app lives on an external server; Shopware communicates via signed HTTP requests.

**Use App System when:** targeting the Shopware Store (SaaS), need zero-downtime updates, or building a multi-tenant integration.
**Use Plugin when:** need direct DB access, custom PHP services, or targeting self-hosted only.

---

## Directory Structure

```
custom/apps/MyVendorApp/
├── manifest.xml              # Required — defines everything
├── Resources/
│   ├── app/storefront/src/   # Optional: JS/SCSS assets
│   ├── views/storefront/     # Optional: Twig overrides
│   ├── config/config.xml     # Optional: Plugin-style config fields
│   └── scripts/              # Optional: App Scripts (Twig-based)
└── icon.png                  # Optional: shown in admin
```

---

## manifest.xml — Complete Example

```xml
<?xml version="1.0" encoding="UTF-8"?>
<manifest xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/Framework/App/Manifest/Schema/manifest-2.0.xsd">

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
        <compatibility>~6.6.0</compatibility>
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

    <!-- Allowlist for outbound requests Shopware makes to your app -->
    <allowed-hosts>
        <host>my-app.com</host>
        <host>api.external-service.com</host>
    </allowed-hosts>

    <!-- Webhooks — Shopware calls your URL on these events -->
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

    <!-- Custom fields added to existing entities -->
    <custom-fields>
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

    <!-- Cookie consent registration -->
    <cookies>
        <cookie>
            <snippet-name>my_app.tracking.cookie</snippet-name>
            <cookie>my_app_session</cookie>
            <expiration>30</expiration>
        </cookie>
    </cookies>

    <!-- Payment method provided by your app -->
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

---

## Registration Handshake

When Shopware installs the app it performs a 3-step handshake:

### Step 1 — Shopware → Your App (POST /shopware/register)

```php
#[Route(path: '/shopware/register', methods: ['POST'])]
public function register(Request $request): JsonResponse
{
    $body      = $request->getContent();
    $signature = $request->headers->get('shopware-app-signature');
    $expected  = hash_hmac('sha256', $body, $this->appSecret);

    if (!hash_equals($expected, $signature)) {
        return new JsonResponse(['error' => 'Invalid signature'], 401);
    }

    $payload = json_decode($body, true);
    $shopId  = $payload['shop-id'];
    $shopUrl = $payload['shop-url'];

    $this->shopRepository->save($shopId, $shopUrl);

    // $appName must match the <name> tag in manifest.xml exactly
    $appName = 'MyApp'; // same value as <name> in manifest.xml
    return new JsonResponse([
        'proof'            => hash_hmac('sha256', $shopId . $shopUrl . $appName, $this->appSecret),
        'secret'           => bin2hex(random_bytes(32)), // per-shop secret for future webhook verification
        'confirmation_url' => 'https://my-app.com/shopware/confirm',
    ]);
}
```

### Step 2 — Shopware → Your App (POST /shopware/confirm)

```php
#[Route(path: '/shopware/confirm', methods: ['POST'])]
public function confirm(Request $request): JsonResponse
{
    $payload = json_decode($request->getContent(), true);

    $shop = $this->shopRepository->findByShopId($payload['shopId']);
    $shop->setApiKey($payload['apiKey']);
    $shop->setSecretKey($payload['secretKey']);
    $this->shopRepository->save($shop);

    return new JsonResponse(['success' => true]);
}
```

### Step 3 — Call Shopware Admin API from Your App

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

Every webhook Shopware sends includes a `shopware-shop-signature` header. Verify it:

```php
public function handleOrderPlaced(Request $request): JsonResponse
{
    $shopId    = $request->query->get('shop-id', '');
    $secret    = $this->shopRepository->getSecretByShopId($shopId);

    $body      = $request->getContent();
    $signature = $request->headers->get('shopware-shop-signature');
    $expected  = hash_hmac('sha256', $body, $secret);

    if (!hash_equals($expected, $signature)) {
        return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $payload = json_decode($body, true);
    $orderId = $payload['data']['payload'][0]['primaryKey'] ?? null;

    return new JsonResponse(['success' => true]);
}
```

---

## App Scripts (Twig-based, no PHP server needed)

App Scripts run inside Shopware's process — no external server required. They use Twig with a restricted sandbox.

Place scripts in `Resources/scripts/{hook-name}/`:

```
Resources/scripts/
├── checkout-cart/
│   └── cart-manipulation.twig      # Runs on every cart calculation
├── product-page-loaded/
│   └── add-custom-data.twig        # Runs after product page loads
└── storefront-request/
    └── redirect.twig               # Runs on every storefront request
```

### Cart Manipulation Script

```twig
{# Resources/scripts/checkout-cart/add-discount.twig #}
{% set cart = hook.cart %}
{% set services = hook.services %}

{% if cart.lineItems.count > 0 %}
    {# Add a product to the cart — use services.cart.products facade #}
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
| `checkout-cart` | Every cart calculation |
| `checkout-cart-page-loaded` | Cart page load |
| `checkout-confirm-page-loaded` | Confirm page load |
| `product-page-loaded` | Product detail page load |
| `storefront-request` | Every storefront HTTP request |
| `order-placed` | After order is placed |

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

Shopware sends a signed POST to your URL with the selected entity IDs:

```php
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
    }
}
```

---

## Permission Reference

```xml
<permissions>
    <!-- Available: read, create, update, delete -->
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
# Use ngrok to expose local server
ngrok http 8000

# Update manifest.xml with ngrok URL:
# <registrationUrl>https://abc123.ngrok.io/shopware/register</registrationUrl>

# Install in local Shopware:
bin/console app:install MyVendorApp --activate

# Check registration status:
bin/console app:list
```
