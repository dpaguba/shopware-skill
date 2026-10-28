# Security: Auth, CSRF, Validation, Authorization

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

| Scope | Auth mechanism | Context type | Constant |
|---|---|---|---|
| `storefront` | Session cookie | `SalesChannelContext` | `StorefrontRouteScope::ID` |
| `store-api` | `sw-access-key` header | `SalesChannelContext` | `StoreApiRouteScope::ID` |
| `api` | OAuth2 Bearer token | `Context` | `ApiRouteScope::ID` |

A scope also checks the path: `api` routes must live under `/api/`, `store-api` routes under `/store-api/`.

### Class level vs method level

`_routeScope` is a Symfony route default, so the usual merge rules apply: a scope on the method
**overrides** the one on the class, it does not add to it. Declare it once on the class and let every
action inherit it. Put it on a method only when one action in an otherwise storefront controller must be
reachable through a different scope, which is rare and usually a sign the action belongs in its own
controller.

```php
#[Route(defaults: ['_routeScope' => ['storefront']])]   // applies to every action below
class MyController extends StorefrontController
{
    #[Route(path: '/my/page', name: 'frontend.my.page', methods: ['GET'])]
    public function page(): Response {}                  // scope: storefront (inherited)

    #[Route(
        path: '/api/_action/my/hook',                    // api scope requires the /api/ prefix
        name: 'api.action.my.hook',
        defaults: ['_routeScope' => ['api']],            // overrides the class scope
        methods: ['POST']
    )]
    public function hook(Context $context): JsonResponse {}
}
```

A route may list several scopes: `['_routeScope' => ['storefront', 'store-api']]`. Do this only when the
same action really serves both, and remember the resolved context type differs per request.

**A route with no scope anywhere is broken.** `RouteScopeListener` throws
`RoutingException::invalidRouteScope()` on the first request, so the failure shows up as a 412
`FRAMEWORK__ROUTING_INVALID_ROUTE_SCOPE` rather than as a missing guard. A path outside the scope's prefix
fails the same way.

Core uses the constants instead of literals, and so should plugin code that needs to survive renames:

```php
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
```

Related defaults, all settable at either level with the same override semantics:

| Default | Effect |
|---|---|
| `_loginRequired` | Storefront/Store API: customer must be logged in, otherwise 403 `FRAMEWORK__ROUTING_CUSTOMER_NOT_LOGGED_IN` |
| `_loginRequiredAllowGuest` | Also accepts guest sessions |
| `_contextTokenRequired` | Store API: reject requests without `sw-context-token` instead of starting a new context |
| `_acl` | Admin API: array of required ACL privileges |
| `_noStore` | Response is excluded from the HTTP cache |
| `_httpCache` | Response is cacheable |
| `_captcha` | Enables configured captchas for the route |

---

## Authorization: Filter by Authenticated Customer

In Store API routes, always scope data to the logged-in customer. Never return data for other customers.

```php
// Returns all orders regardless of customer:
$criteria = new Criteria();
$orders = $this->orderRepository->search($criteria, $context->getContext());

public function getMyOrders(SalesChannelContext $context): JsonResponse
{
    $customer = $context->getCustomer();
    if ($customer === null) {
        throw \Shopware\Core\Checkout\Customer\CustomerException::customerNotLoggedIn(); // 403
    }

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customer->getId()));

    $orders = $this->orderRepository->search($criteria, $context->getContext());

    return new JsonResponse($orders->getEntities()->getElements());
}
```

---

## Input Validation

Validate all user input at the boundary (controllers). Use Symfony's Validator component. Constraints take
named arguments (Symfony 7.3+ deprecates option arrays, and Shopware 6.8 removes them from its own constraints).

```php
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class MyController extends StorefrontController
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route(path: '/my-form', name: 'frontend.my-plugin.submit', methods: ['POST'])]
    public function submitForm(Request $request, SalesChannelContext $context): Response
    {
        $email = $request->request->getString('email');
        $name  = $request->request->getString('name');

        $violations = $this->validator->validate([
            'email' => $email,
            'name'  => $name,
        ], new Assert\Collection(fields: [
            'email' => [new Assert\NotBlank(), new Assert\Email()],
            'name'  => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 100)],
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

Read input from the bag you expect (`$request->request`, `$request->query`): `Request::get()` is deprecated in
Symfony 7.4. For routes that accept both GET and POST, use `Shopware\Core\Framework\Adapter\Request\RequestParamHelper::get($request, 'name')`.

For Store/Admin API, return structured errors with `DataValidator` (see "Validation Exception" below): it throws
`ConstraintViolationException`, rendered as 400 `FRAMEWORK__CONSTRAINT_VIOLATION` with one error per violation.

---

## CSRF Protection (Storefront Forms)

Shopware has no CSRF tokens since 6.5: the `sw_csrf` Twig function, the `frontend.csrf.generateToken` route and
`window.csrf` were removed. Protection comes from the session cookie being `SameSite=Lax`
(`framework.session.cookie_samesite: lax`), so browsers do not send it with cross-site POST, PATCH or DELETE
requests.

What this means for extension code:

- Never change state in a GET route: Lax cookies are still sent on top-level cross-site GET navigation.
- Forms and same-origin `fetch()` calls need no token. Remove `{{ sw_csrf(...) }}` and hidden `_csrf_token`
  fields when porting templates from 6.4.
- Endpoints called without the session cookie (webhook receivers, server-to-server calls) are not covered:
  authenticate them yourself (HMAC signature, API token).

```twig
<form action="{{ path('frontend.my-plugin.submit') }}" method="post">
    <input type="email" name="email">
    <button type="submit">Submit</button>
</form>
```

---

## SQL Injection Prevention

Never write raw SQL in business logic. Use DAL for all queries.

```php
// SQL injection vulnerability:
$name = $request->request->getString('name');
$connection->executeQuery("SELECT * FROM product WHERE name = '$name'");

$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('name', $request->request->getString('name')));
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
// Logs customer PII, avoid:
$this->logger->info('Customer logged in', ['customer' => $customer]);

$this->logger->info('Customer logged in', ['customerId' => $customer->getId()]);

// Stores plain-text credentials in DB:
$config->set('my_plugin.api_key', $plainKey);

// inject via environment variables instead (services.php)
$services->set(MyApiClient::class)->args(['%env(MY_PLUGIN_API_KEY)%']);
```

Do not log: passwords, full credit card numbers, session tokens, customer email+name combinations.

Flow event values that must not leave the shop (tokens, opt-in links) are kept out of app webhook payloads with
`EventDataCollection::HIDDEN_FROM_WEBHOOK` or `#[NotHookable]` (SW 6.7.14.1+, see references/integrations.md).

Since 6.7.14 the storefront can send `Clear-Site-Data` on customer logout
(`storefront.security.clear_site_data_on_logout: ['storage']`, allowed `cache`, `cookies`, `storage`; off by
default). The header is origin-wide: `cookies` also ends an Administration session on the same domain. A custom
storefront logout action opts in with `$request->attributes->set(PlatformRequest::ATTRIBUTE_CLEAR_SITE_DATA, true)`.

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
        $mayUpdate     = $source->isAllowed('product:update');
    }

    if ($source instanceof SalesChannelApiSource) {
        // Called from Store API
        $salesChannelId = $source->getSalesChannelId();
    }

    if ($source instanceof SystemSource) {
        // Internal system call, highest trust level
    }
}
```

---

## Rate Limiting (Custom Routes)

Use Shopware's `RateLimiter` service with your own limiter name:

```php
use Shopware\Core\Framework\RateLimiter\RateLimiter;

class MyApiController extends AbstractController
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    #[Route(path: '/api/_action/my-plugin/expensive', name: 'api.action.my_plugin.expensive', methods: ['POST'])]
    public function expensive(Request $request): JsonResponse
    {
        // Throws RateLimitExceededException: 429 with the wait time in the error detail
        $this->rateLimiter->ensureAccepted('my_plugin_expensive', (string) $request->getClientIp());

        // Expensive operation...
        // For login-style limits call $this->rateLimiter->reset('my_plugin_expensive', $key) on success
    }
}
```

The limiter name must exist in the `shopware.api.rate_limiter` parameter, otherwise `ensureAccepted()` throws.
A plugin ships `src/Resources/config/rate_limiter.yaml` and merges it in with a compiler pass; config, pass and
policies are in references/advanced.md (Rate Limiter).

---

## Error Handling Patterns

### Custom Exception Classes

Shopware's convention is one domain exception per domain: a class extending `Shopware\Core\Framework\HttpException`
with static factories that carry the status code, a unique error code and the message.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class MyPluginException extends HttpException
{
    public const PRODUCT_SYNC_FAILED = 'MY_PLUGIN__PRODUCT_SYNC_FAILED';

    public static function productSyncFailed(string $productId, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            Response::HTTP_BAD_GATEWAY, // 502
            self::PRODUCT_SYNC_FAILED,
            'Failed to sync product "{{ productId }}": {{ reason }}',
            ['productId' => $productId, 'reason' => $reason],
            $previous
        );
    }
}
```

`HttpException` extends `ShopwareHttpException`, which ensures:
- The exception is serialized to Shopware's standard JSON error format automatically
- HTTP status code is set correctly
- Error code is machine-readable for clients (`$e->is(MyPluginException::PRODUCT_SYNC_FAILED)`)

Add a subclass (`class ProductSyncFailedException extends MyPluginException`) returned by the factory only for
cases callers must catch specifically.

### Throw and Catch

```php
// Throw
throw MyPluginException::productSyncFailed($productId, $e->getMessage());

// Catch in controller: let Shopware's exception listener handle it
// (it will serialize ShopwareHttpException to JSON automatically)

// For other exceptions: wrap them
try {
    $this->externalApi->sync($productId);
} catch (\Throwable $e) {
    $this->logger->error('Sync failed', ['productId' => $productId, 'error' => $e->getMessage()]);
    throw MyPluginException::productSyncFailed($productId, $e->getMessage(), $e);
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
| Not logged in | 403 | `\Shopware\Core\Checkout\Customer\CustomerException::customerNotLoggedIn()` (`_loginRequired` answers the same status before your code runs) |
| Access denied | 403 | Your domain exception with `Response::HTTP_FORBIDDEN` |
| Entity not found | 404 | `\Shopware\Core\Framework\DataAbstractionLayer\Exception\EntityNotFoundException` |
| External service down | 502 | Your domain exception with `Response::HTTP_BAD_GATEWAY` |

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
        $definition->add('name',  new Assert\NotBlank(), new Assert\Length(min: 2, max: 100));
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

- [ ] All routes have `_routeScope` defined, with paths matching the scope prefix
- [ ] Store API routes filter by `$context->getCustomer()->getId()`
- [ ] No raw SQL with string concatenation
- [ ] No sensitive data in logs or webhook payloads
- [ ] No state changes in GET routes (SameSite cookies are the CSRF protection)
- [ ] Webhook receivers verify HMAC signature
- [ ] API keys stored as environment variables, not in DB plain-text
- [ ] `_loginRequired` set on routes that need authenticated customers
- [ ] ACL privileges declared for Admin API routes (`_acl`)
