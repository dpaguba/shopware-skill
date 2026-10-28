# Testing: PHPUnit, Jest, E2E

---

## PHPUnit: Unit Tests

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use VendorName\PluginName\Service\MyService;

class MyServiceTest extends TestCase
{
    private EntityRepository&MockObject $repository;
    private MyService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->service    = new MyService($this->repository);
    }

    public function testDoSomethingReturnsExpectedResult(): void
    {
        // Arrange
        $this->repository
            ->expects($this->once())
            ->method('search')
            ->willReturn($this->createMock(EntitySearchResult::class));

        // Act
        $result = $this->service->doSomething();

        // Assert
        static::assertNotNull($result);
    }
}
```

---

## PHPUnit: Integration Tests (KernelTestCase)

Integration tests boot the real Shopware kernel. Use them for DAL operations, event dispatching, and service interactions.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Tests\Integration\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use PHPUnit\Framework\TestCase;

class MyEntityIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $myEntityRepository;

    protected function setUp(): void
    {
        $this->myEntityRepository = static::getContainer()->get('my_entity.repository');
    }

    public function testCreateAndReadEntity(): void
    {
        $id      = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $this->myEntityRepository->create([
            ['id' => $id, 'name' => 'Test Entity'],
        ], $context);

        $criteria = new Criteria([$id]);
        $entities = $this->myEntityRepository->search($criteria, $context)->getEntities();

        static::assertCount(1, $entities);
        static::assertSame('Test Entity', $entities->first()->getName());
    }
}
```

Read results through `getEntities()`. In 6.8 `EntitySearchResult` is no longer an `EntityCollection`, so
`count($result)`, `foreach ($result ...)` and `$result->first()` stop working; `getEntities()` already works in 6.7.

---

## PHPUnit: Testing Subscribers

A subscriber is a plain object: build the event, call the listener, assert on the result. No kernel needed.

```php
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Generator;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

class ProductPageSubscriberTest extends TestCase
{
    public function testAddsRelatedArticlesExtension(): void
    {
        $product = new SalesChannelProductEntity();
        $product->setId(Uuid::randomHex());

        $page = new ProductPage();
        $page->setProduct($product);

        $service = $this->createMock(RelatedArticlesService::class);
        $service->method('findRelated')->willReturn(new ProductCollection());

        // Generator is Shopware's (@internal) factory for a fully populated SalesChannelContext
        $event = new ProductPageLoadedEvent($page, Generator::generateSalesChannelContext(), new Request());

        (new ProductPageSubscriber($service))->onProductPageLoaded($event);

        static::assertTrue($page->hasExtension('relatedArticles'));
    }
}
```

---

## phpunit.xml.dist

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
         bootstrap="tests/TestBootstrap.php"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,random">

    <php>
        <ini name="error_reporting" value="-1"/>
        <server name="KERNEL_CLASS" value="Shopware\Core\Kernel"/>
        <env name="APP_ENV" value="test"/>
        <env name="APP_DEBUG" value="1"/>
        <env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>
    </php>

    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`tests/TestBootstrap.php` (what `bin/console plugin:create` scaffolds):

```php
<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$loader = (new TestBootstrapper())
    ->addCallingPlugin()
    ->addActivePlugins('MyPlugin')
    ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('VendorName\\PluginName\\Tests\\', __DIR__);
```

Test-only services go in `src/Resources/config/services_test.php` (loaded when `APP_ENV=test`); `services_test.xml` is
deprecated since 6.7.14 and no longer loaded in 6.8.

---

## Running PHP Tests

```bash
# Run all tests
php vendor/bin/phpunit --testsuite=unit
php vendor/bin/phpunit --testsuite=integration

# Run single file
php vendor/bin/phpunit tests/Unit/Service/MyServiceTest.php

# Run specific test method
php vendor/bin/phpunit --filter=testDoSomethingReturnsExpectedResult

# With coverage (requires Xdebug or PCOV)
php vendor/bin/phpunit --coverage-html=coverage/

# Integration tests need a DB; TestBootstrapper appends "_test" to the database name:
DATABASE_URL=mysql://root:@localhost:3306/shopware php vendor/bin/phpunit --testsuite=integration
```

---

## Key Test Traits

| Trait | Provides |
|---|---|
| `IntegrationTestBehaviour` | Boots kernel, `static::getContainer()`; already includes `DatabaseTransactionBehaviour`, `BasicTestDataBehaviour`, cache, filesystem and session resets |
| `DatabaseTransactionBehaviour` | Wraps each test in rollback |
| `BasicTestDataBehaviour` | Lookups of seeded data: `getValidTaxId()`, `getValidPaymentMethodId()`, `getValidCountryId()` ... |
| `SalesChannelApiTestBehaviour` | Sales channel with test browser, `createSalesChannelContext()` |
| `AdminApiTestBehaviour` | Admin API test client |
| `CountryAddToSalesChannelTestBehaviour` | Country setup helpers |

`IntegrationTestBehaviour` already rolls back every test, so do not add `DatabaseTransactionBehaviour` next to it.
Use it on its own only when you compose a smaller set, for example `KernelTestBehaviour` plus
`DatabaseTransactionBehaviour`:

```php
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

class MyTest extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;
}
```

---

## Testing Storefront Controllers

```php
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;

class MyControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testIndexRoute(): void
    {
        $browser = $this->createCustomSalesChannelBrowser([
            'name' => 'Test Sales Channel',
        ]);

        $browser->request('GET', '/my-page');

        static::assertSame(200, $browser->getResponse()->getStatusCode());
        static::assertStringContainsString('My Custom Page', $browser->getResponse()->getContent());
    }
}
```

---

## Testing Admin API

```php
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;

class MyApiControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use AdminApiTestBehaviour;

    public function testMyEndpoint(): void
    {
        $browser = $this->getBrowser();

        $browser->request(
            'POST',
            '/api/_action/my-plugin/sync',
            [],
            [],
            ['HTTP_CONTENT_TYPE' => 'application/json'],
            json_encode(['key' => 'value'])
        );

        $response = json_decode($browser->getResponse()->getContent(), true);

        static::assertSame(200, $browser->getResponse()->getStatusCode());
        static::assertTrue($response['success']);
    }
}
```

---

## Jest: Admin Unit Tests

Plugins use the `@shopware-ag/jest-preset-sw6-admin` preset (v6+ for the 6.7 Vite / Vue 3 Administration). It boots
the global `Shopware` object from a Shopware Administration checkout and maps `src/...` imports to it.

```js
// jest.config.js (plugin: src/Resources/app/administration/)
module.exports = {
    preset: '@shopware-ag/jest-preset-sw6-admin',
    globals: {
        // required: vendor/shopware/administration/Resources/app/administration (with node_modules installed);
        // the preset also reads process.env.ADMIN_PATH to resolve `src/...` imports
        adminPath: process.env.ADMIN_PATH,
    },
};
```

Install `jest @shopware-ag/jest-preset-sw6-admin @babel/preset-env @babel/plugin-proposal-class-properties` (plus
`@vue/test-utils` for mounting) as dev dependencies and add a `babel.config.json` with that preset and plugin, as the
preset's README describes.

```js
import { shallowMount } from '@vue/test-utils';
import './index'; // registers 'my-component' via Shopware.Component.register()

const mockSearch = jest.fn().mockResolvedValue({ total: 1, data: [] });

async function createWrapper() {
    return shallowMount(await Shopware.Component.build('my-component'), {
        global: {
            stubs: ['mt-card', 'mt-button'],
            provide: {
                repositoryFactory: { create: () => ({ search: mockSearch }) },
            },
        },
    });
}

describe('my-component', () => {
    let wrapper;

    beforeEach(async () => {
        wrapper = await createWrapper();
    });

    afterEach(() => {
        wrapper.unmount();
    });

    it('renders correctly', () => {
        expect(wrapper.find('.my-class').exists()).toBe(true);
    });

    it('emits event on button click', async () => {
        await wrapper.find('[data-testid="my-button"]').trigger('click');
        expect(wrapper.emitted('my-event')).toHaveLength(1);
    });

    it('loads data on mount', () => {
        expect(mockSearch).toHaveBeenCalled();
    });
});
```

`Shopware.Component.build()` is async in 6.7 and returns the component with its Twig template and overrides
resolved; computed properties such as a repository cannot be assigned on `wrapper.vm`, so inject mocks via
`provide`.

---

## Running Jest Tests

```bash
# From the plugin's administration folder, with "unit": "jest" in its package.json
npm run unit

# Watch mode
npm run unit -- --watch

# Coverage
npm run unit -- --coverage
```

---

## Playwright E2E Tests

Shopware's E2E tests are Playwright based (the Cypress suite is gone from the platform repo). Plugins use the
Acceptance Test Suite, typically from `tests/acceptance/` in the plugin:

```bash
npm init playwright@latest
npm install @shopware-ag/acceptance-test-suite
npx playwright install
# .env: APP_URL, SHOPWARE_ADMIN_USERNAME / SHOPWARE_ADMIN_PASSWORD
# (or SHOPWARE_ACCESS_KEY_ID / SHOPWARE_SECRET_ACCESS_KEY for an integration)
```

```ts
// BaseTestFile.ts
import { test as base } from '@shopware-ag/acceptance-test-suite';
import type { FixtureTypes } from '@shopware-ag/acceptance-test-suite';

export * from '@shopware-ag/acceptance-test-suite';

export const test = base.extend<FixtureTypes>({
    // your own fixtures
});
```

```ts
import { test } from './BaseTestFile';

test('Product can be added to the cart', async ({
    ShopCustomer, TestDataService, StorefrontProductDetail, AddProductToCart,
}) => {
    const product = await TestDataService.createBasicProduct({ stock: 1 });

    await ShopCustomer.goesTo(StorefrontProductDetail.url(product));
    await ShopCustomer.attemptsTo(AddProductToCart(product));
});

test('Admin sees the product name', async ({ ShopAdmin, TestDataService, AdminProductDetail }) => {
    const product = await TestDataService.createBasicProduct();

    await ShopAdmin.goesTo(AdminProductDetail.url(product.id));
    await ShopAdmin.expects(AdminProductDetail.productHeadline).toContainText(product.name);
});
```

Run with `npx playwright test` (`--ui` for the interactive runner). `TestDataService` creates data through the
Admin API and cleans it up after the test.

---

## Test Data: Fixtures

```php
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\TestDefaults;

private function createProduct(string $name, float $price): string
{
    $id = Uuid::randomHex();

    $this->productRepository->create([
        [
            'id'            => $id,
            'name'          => $name,
            'productNumber' => 'TEST-' . $id,
            'stock'         => 100,
            'price'         => [[
                'currencyId' => Defaults::CURRENCY,
                'gross'      => $price,
                'net'        => $price / 1.19,
                'linked'     => true,
            ]],
            'taxId'          => $this->getValidTaxId(),
            'visibilities'   => [[
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
                'visibility'     => ProductVisibilityDefinition::VISIBILITY_ALL,
            ]],
        ],
    ], Context::createDefaultContext());

    return $id;
}
```

---

## Common Test Assertions

```php
static::assertCount(3, $result->getEntities());
static::assertSame('Expected Name', $entity->getName());
static::assertNotNull($entity->getManufacturer());

// Events: `use EventDispatcherBehaviour;` (Shopware\Core\Framework\Test\TestCaseBase), listener removed after the test
$written = [];
$this->addEventListener(static::getContainer()->get('event_dispatcher'), 'product.written',
    static function (EntityWrittenEvent $event) use (&$written): void { $written[] = $event; });
// ... write a product ...
static::assertCount(1, $written);

static::assertSame(200, $response->getStatusCode());
$data = json_decode($response->getContent(), true);
static::assertArrayHasKey('data', $data);

// Exception assertions: prefer expectExceptionObject (checks class + message at once)
$this->expectExceptionObject(new \InvalidArgumentException('Expected message'));
$this->service->doSomethingThatThrows();

// When you need separate class and message checks:
$this->expectException(MyException::class);
$this->expectExceptionMessage('Expected message');
$this->service->doSomethingThatThrows();
```

---

## StaticEntityRepository (Preferred Mocking Utility)

`StaticEntityRepository` is Shopware's built-in test utility. Prefer it over `createMock(EntityRepository::class)`: it's more realistic and records all write operations automatically.

```php
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;

class MyServiceTest extends TestCase
{
    public function testSearchAndUpdate(): void
    {
        $context    = Context::createDefaultContext();
        $productId  = Uuid::randomHex();

        $product = new ProductEntity();
        $product->setId($productId);
        $product->setName('Test Product');

        $collection = new ProductCollection([$product]);

        $repository = new StaticEntityRepository(
            [$collection],                   // sequential search results
            new ProductDefinition()          // optional: enables proper primary key resolution
        );

        $service = new MyService($repository);
        $service->doSomething($context);

        static::assertCount(1, $repository->updates);
        static::assertSame($productId, $repository->updates[0][0]['id']);
        static::assertSame('Updated Name', $repository->updates[0][0]['name']);
    }

    public function testMultipleSearchCalls(): void
    {
        $context = Context::createDefaultContext();

        // Each search() call consumes the next result in the array
        $repository = new StaticEntityRepository([
            new ProductCollection([/* first call */]),
            new ProductCollection([/* second call */]),
        ]);
    }
}
```

### StaticEntityRepository Write Tracking Properties

```php
$repository->creates;  // array[]: payloads from create() calls
$repository->updates;  // array[]: payloads from update() calls
$repository->upserts;  // array[]: payloads from upsert() calls
$repository->deletes;  // array[]: ID arrays from delete() calls
```

---

## ProductBuilder (Test Fixture Pattern)

Shopware's `ProductBuilder` provides a fluent API for creating complex product test data without manually building the full array.

```php
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

class ProductIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
    }

    public function testProductWithVariants(): void
    {
        $context    = Context::createDefaultContext();
        $repository = static::getContainer()->get('product.repository');

        // Build product with all required fields handled automatically
        $product = (new ProductBuilder($this->ids, 'PROD-001'))
            ->name('My Test Product')
            ->price(99.99)
            ->stock(100)
            ->category('Main Category')
            ->manufacturer('My Manufacturer')
            ->visibility()                    // adds to default sales channel
            ->build();

        $repository->create([$product], $context);

        $productId = $this->ids->get('PROD-001');

        // Assert
        $criteria = new Criteria([$productId]);
        $result   = $repository->search($criteria, $context)->getEntities()->first();

        static::assertNotNull($result);
        static::assertSame('My Test Product', $result->getName());
    }
}
```

### IdsCollection

> **SW 6.7 namespace change.** `Shopware\Core\Framework\Test\IdsCollection` was removed. The class now
> lives at `Shopware\Core\Test\Stub\Framework\IdsCollection`. `ProductBuilder` did **not** move and stays
> at `Shopware\Core\Content\Test\Product\ProductBuilder`.

`IdsCollection` is a stable UUID registry for tests. Using it ensures IDs are consistent across creation and assertion:

```php
$ids = new IdsCollection();

$productId = $ids->get('my-product');    // creates + caches a UUID for 'my-product'
$sameId    = $ids->get('my-product');    // returns the same UUID
$bytes     = $ids->getBytes('my-product'); // returns BINARY(16) version
```

---

## assertSame vs assertEquals

In Shopware tests, always prefer `assertSame` over `assertEquals` for string/UUID comparisons.

```php
// assertEquals uses loose comparison (== not ===):
// May pass when comparing '0' == false, or similar type coercions
static::assertEquals($expectedId, $actualId);

static::assertSame($expectedId, $actualId);
static::assertSame(1, $result->getTotal());   // not assertEquals(1, ...)
```

This matters especially when comparing:
- UUIDs (hex string vs binary bytes)
- Integer counts from DAL
- Boolean flags from entity fields
