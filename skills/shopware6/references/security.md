# Security — Auth, CSRF, Validation, Authorization

---

## Route Scopes & Authentication

Every custom route must declare its scope via `_routeScope`. Shopware enforces authentication based on it.

```php
#[Route(defaults: ['_routeScope' => ['storefront']])]
class MyController extends StorefrontController
{
    // Add _loginRequired to enforce logged-in customer
    #[Route(
        path: '/account/my-data',
        name: 'frontend.my-plugin.account',
        defaults: ['_loginRequired' => true],
        methods: ['GET']
    )]
    public function accountData(Request $request, SalesChannelContext $context): Response
    {
        // $context->getCustomer() is guaranteed non-null here
        $customer = $context->getCustomer();
    }
}

#[Route(defaults: ['_routeScope' => ['store-api']])]
class MyStoreApiRoute extends AbstractController
{
    #[Route(
        path: '/store-api/my-route',
        name: 'store-api.my_plugin',
        methods: ['POST'],
        defaults: ['_loginRequired' => true]  // requires sw-context-token with logged-in customer
    )]
    public function myRoute(Request $request, SalesChannelContext $context): JsonResponse {}
}

#[Route(defaults: ['_routeScope' => ['api']])]
class MyAdminController extends AbstractController
{
    #[Route(
        path: '/api/_action/my-plugin/sync',
        name: 'api.action.my_plugin.sync',
        methods: ['POST'],
        defaults: ['_acl' => ['product:update']]  // require specific ACL
    )]
    public function sync(Request $request, Context $context): JsonResponse {}
}
```

| Scope | Auth mechanism | Context type |
|---|---|---|
| `storefront` | Session cookie | `SalesChannelContext` |
| `store-api` | `sw-access-key` header | `SalesChannelContext` |
| `api` | OAuth2 Bearer token | `Context` |

---

## Authorization — Filter by Authenticated Customer

In Store API routes, always scope data to the logged-in customer. Never return data for other customers.

```php
// Returns all orders regardless of customer:
$criteria = new Criteria();
$orders = $this->orderRepository->search($criteria, $context->getContext());

public function getMyOrders(SalesChannelContext $context): JsonResponse
{
    $customer = $context->getCustomer();
    if ($customer === null) {
        throw new \Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException();
    }

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customer->getId()));

    $orders = $this->orderRepository->search($criteria, $context->getContext());

    return new JsonResponse($orders->getElements());
}
```

---

## Input Validation

Validate all user input at the boundary (controllers). Use Symfony's Validator component.

```php
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class MyController extends StorefrontController
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route(path: '/my-form', methods: ['POST'])]
    public function submitForm(Request $request, SalesChannelContext $context): Response
    {
        $email = $request->request->get('email', '');
        $name  = $request->request->get('name', '');

        $violations = $this->validator->validate([
            'email' => $email,
            'name'  => $name,
        ], new Assert\Collection([
            'email' => [new Assert\NotBlank(), new Assert\Email()],
            'name'  => [new Assert\NotBlank(), new Assert\Length(['min' => 2, 'max' => 100])],
        ]));

        if ($violations->count() > 0) {
            return $this->renderStorefront('@MyPlugin/storefront/page/form.html.twig', [
                'violations' => $violations,
                'formData'   => ['email' => $email, 'name' => $name],
            ]);
        }
    }
}
```

For Store/Admin API — return structured errors:

```php
if (!filter_var($request->get('email'), FILTER_VALIDATE_EMAIL)) {
    throw new \Shopware\Core\Framework\Validation\Exception\ConstraintViolationException(
        $this->buildViolations(['email' => 'Invalid email address']),
        $request->request->all()
    );
}
```

---

## CSRF Protection (Storefront Forms)

Shopware provides CSRF protection automatically for Storefront POST routes. Use the Twig helper in forms:

```twig
{# In Storefront Twig form #}
<form action="{{ path('frontend.my-plugin.submit') }}" method="post">
    {{ sw_csrf('frontend.my-plugin.submit') }}

    <input type="text" name="email">
    <button type="submit">Submit</button>
</form>
```

For AJAX POST requests from JavaScript, fetch the CSRF token first:

```js
const response = await fetch('/csrf/generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ intent: 'frontend.my-plugin.submit' }),
});
const { token } = await response.json();

const formData = new FormData();
formData.append('_csrf_token', token);
formData.append('email', 'user@example.com');

await fetch('/my-form', { method: 'POST', body: formData });
```

To exempt a route from CSRF (e.g., webhook receiver):

```php
#[Route(
    path: '/webhook/shopware',
    name: 'api.action.my_plugin.webhook',
    defaults: ['csrf_protected' => false],
    methods: ['POST']
)]
```

---

## SQL Injection Prevention

Never write raw SQL in business logic. Use DAL for all queries.

```php
// SQL injection vulnerability:
$name = $request->get('name');
$connection->executeQuery("SELECT * FROM product WHERE name = '$name'");

$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('name', $request->get('name')));
$this->productRepository->search($criteria, $context);

$connection->executeStatement(
    'UPDATE `product` SET `stock` = :stock WHERE `id` = :id',
    ['stock' => $stock, 'id' => Uuid::fromHexToBytes($id)]
);
```

In migrations: always use named parameters or `?` placeholders, never string concatenation.

---

## Sensitive Data

```php
// Logs customer PII — avoid:
$this->logger->info('Customer logged in', ['customer' => $customer]);

$this->logger->info('Customer logged in', ['customerId' => $customer->getId()]);

// Stores plain-text credentials in DB:
$config->set('my_plugin.api_key', $plainKey);

// inject via environment variables instead
$apiKey = $_ENV['MY_PLUGIN_API_KEY'];
```

Do not log: passwords, full credit card numbers, session tokens, customer email+name combinations.

---

## Context Source Verification (Admin API)

When your endpoint must distinguish between different callers:

```php
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;

public function myEndpoint(Request $request, Context $context): JsonResponse
{
    $source = $context->getSource();

    if ($source instanceof AdminApiSource) {
        // Called from Admin API with OAuth token
        $userId        = $source->getUserId();
        $isAdmin       = $source->isAdmin();
        $userIntegration = $source->getIntegrationId();
    }

    if ($source instanceof SalesChannelApiSource) {
        // Called from Store API
        $salesChannelId = $source->getSalesChannelId();
    }

    if ($source instanceof SystemSource) {
        // Internal system call — highest trust level
    }
}
```

---

## Rate Limiting (Custom Routes)

```php
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;

class MyApiController extends AbstractController
{
    public function __construct(
        private readonly RequestRateLimiterInterface $rateLimiter,
    ) {}

    #[Route(path: '/api/_action/my-plugin/expensive', methods: ['POST'])]
    public function expensive(Request $request): JsonResponse
    {
        $limiter = $this->rateLimiter->create($request);
        $limit   = $limiter->consume();

        if (!$limit->isAccepted()) {
            return new JsonResponse(
                ['error' => 'Rate limit exceeded'],
                429,
                ['Retry-After' => $limit->getRetryAfter()->getTimestamp()]
            );
        }

        // Expensive operation...
    }
}
```

---

## Error Handling Patterns

### Custom Exception Classes

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class ProductSyncFailedException extends ShopwareHttpException
{
    public function __construct(string $productId, string $reason)
    {
        parent::__construct(
            'Failed to sync product "{{ productId }}": {{ reason }}',
            ['productId' => $productId, 'reason' => $reason]
        );
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_GATEWAY; // 502
    }

    public function getErrorCode(): string
    {
        return 'MY_PLUGIN__PRODUCT_SYNC_FAILED';
    }
}
```

Extending `ShopwareHttpException` ensures:
- The exception is serialized to Shopware's standard JSON error format automatically
- HTTP status code is set correctly
- Error code is machine-readable for clients

### Throw and Catch

```php
// Throw
throw new ProductSyncFailedException($productId, $e->getMessage());

// Catch in controller — let Shopware's exception listener handle it
// (it will serialize ShopwareHttpException to JSON automatically)

// For non-ShopwareHttpException — wrap it
try {
    $this->externalApi->sync($productId);
} catch (\Throwable $e) {
    $this->logger->error('Sync failed', ['productId' => $productId, 'error' => $e->getMessage()]);
    throw new ProductSyncFailedException($productId, $e->getMessage());
}
```

### Standard JSON Error Response Shape

Shopware serializes `ShopwareHttpException` to:

```json
{
    "errors": [
        {
            "status": "502",
            "code": "MY_PLUGIN__PRODUCT_SYNC_FAILED",
            "title": "Bad Gateway",
            "detail": "Failed to sync product \"abc\": Connection refused",
            "meta": {
                "parameters": {
                    "productId": "abc",
                    "reason": "Connection refused"
                }
            }
        }
    ]
}
```

### Store API Error Codes

For Store API routes, use these standard HTTP codes:

| Situation | Code | Exception |
|---|---|---|
| Missing required field | 400 | `\Shopware\Core\Framework\Validation\Exception\ConstraintViolationException` |
| Not authenticated | 401 | `\Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException` |
| Access denied | 403 | `\Shopware\Core\Framework\ShopwareHttpException` with 403 |
| Entity not found | 404 | `\Shopware\Core\Framework\DataAbstractionLayer\Exception\EntityNotFoundException` |
| External service down | 502 | Custom exception extending `ShopwareHttpException` |

### Validation Exception (Store API)

```php
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Symfony\Component\Validator\Constraints as Assert;

class MyStoreApiController
{
    public function __construct(
        private readonly DataValidator $validator,
    ) {}

    public function createItem(RequestDataBag $data, SalesChannelContext $context): JsonResponse
    {
        $definition = new DataValidationDefinition('my_plugin.create_item');
        $definition->add('name',  new Assert\NotBlank(), new Assert\Length(['min' => 2, 'max' => 100]));
        $definition->add('email', new Assert\NotBlank(), new Assert\Email());

        $this->validator->validate($data->all(), $definition);
        // Throws ConstraintViolationException (400) automatically if validation fails

        // Process valid data...
    }
}
```

---

## Security Checklist

Before shipping a Shopware extension:

- [ ] All routes have `_routeScope` defined
- [ ] Store API routes filter by `$context->getCustomer()->getId()`
- [ ] No raw SQL with string concatenation
- [ ] No sensitive data in logs
- [ ] All POST Storefront forms include `{{ sw_csrf(...) }}`
- [ ] Webhook receivers verify HMAC signature
- [ ] API keys stored as environment variables, not in DB plain-text
- [ ] `_loginRequired` set on routes that need authenticated customers
- [ ] ACL privileges declared for Admin API routes (`_acl`)
