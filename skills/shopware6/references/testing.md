# Testing — PHPUnit, Jest, E2E

---

## PHPUnit — Unit Tests

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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

## PHPUnit — Integration Tests (KernelTestCase)

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
        $this->myEntityRepository = $this->getContainer()->get('my_entity.repository');
    }

    public function testCreateAndReadEntity(): void
    {
        $id      = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $this->myEntityRepository->create([
            ['id' => $id, 'name' => 'Test Entity'],
        ], $context);

        $criteria = new Criteria([$id]);
        $result   = $this->myEntityRepository->search($criteria, $context);

        static::assertCount(1, $result);
        static::assertSame('Test Entity', $result->first()->getName());
    }
}
```

---

## PHPUnit — Testing Subscribers

```php
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MySubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testSubscriberFiresOnProductLoaded(): void
    {
        $dispatcher = $this->getContainer()->get('event_dispatcher');
        $subscriber = $this->getContainer()->get(MySubscriber::class);

        $called = false;
        $dispatcher->addListener('product.loaded', function () use (&$called) {
            $called = true;
        });

        // Trigger the event via the repository — use SalesChannelContext for storefront subscribers
        $salesChannelContext = $this->createSalesChannelContext();
        $criteria = new Criteria([$someProductId]);
        $this->productRepository->search($criteria, $salesChannelContext->getContext());

        static::assertTrue($called);
    }
}
```

---

## phpunit.xml.dist

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="../../../vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,random">

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

# Integration tests need DB — set env vars:
DATABASE_URL=mysql://root:@localhost:3306/shopware_test php vendor/bin/phpunit --testsuite=integration
```

---

## Key Test Traits

| Trait | Provides |
|---|---|
| `IntegrationTestBehaviour` | `getContainer()`, boots kernel |
| `DatabaseTransactionBehaviour` | Wraps each test in rollback |
| `BasicTestDataBehaviour` | Pre-seeded currencies, tax rules |
| `SalesChannelApiTestBehaviour` | Sales channel with test browser |
| `AdminApiTestBehaviour` | Admin API test client |
| `CountryAddToSalesChannelTestBehaviour` | Country setup helpers |

Always use `DatabaseTransactionBehaviour` in integration tests to keep tests isolated:

```php
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class MyTest extends TestCase
{
    use IntegrationTestBehaviour;
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

## Jest — Admin Unit Tests

```js
import { shallowMount } from '@vue/test-utils';
import MyComponent from 'src/module/my-module/component/my-component';

describe('MyComponent', () => {
    let wrapper;

    beforeEach(() => {
        wrapper = shallowMount(MyComponent, {
            global: {
                stubs: ['sw-card', 'sw-button'],
            },
        });
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

    it('loads data on mount', async () => {
        const mockSearch = jest.fn().mockResolvedValue({ total: 1, data: [] });
        wrapper.vm.myRepository = { search: mockSearch };

        await wrapper.vm.$nextTick();
        expect(mockSearch).toHaveBeenCalled();
    });
});
```

---

## Running Jest Tests

```bash
# From plugin root
npm run unit

# Watch mode
npm run unit -- --watch

# Coverage
npm run unit -- --coverage
```

---

## Cypress E2E Tests

```js
describe('My Module', () => {
    beforeEach(() => {
        cy.loginViaApi();
        cy.openInitialPage(`${Cypress.env('admin')}#/my/module/index`);
    });

    it('should display the list view', () => {
        cy.get('.sw-data-grid').should('be.visible');
        cy.get('.sw-data-grid__row').should('have.length.greaterThan', 0);
    });

    it('should create a new entity', () => {
        cy.get('[data-testid="create-button"]').click();
        cy.get('[name="name"]').type('New Entity');
        cy.get('[data-testid="save-button"]').click();

        cy.awaitAndCheckNotification('Entity saved successfully');
        cy.get('.sw-data-grid__row').should('contain', 'New Entity');
    });
});
```

---

## Test Data — Fixtures

```php
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Context;

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

static::assertCount(1, $this->eventRecorder->getEvents(ProductWrittenEvent::class));

static::assertSame(200, $response->getStatusCode());
$data = json_decode($response->getContent(), true);
static::assertArrayHasKey('data', $data);

// Exception assertions — prefer expectExceptionObject (checks class + message at once)
$this->expectExceptionObject(new \InvalidArgumentException('Expected message'));
$this->service->doSomethingThatThrows();

// When you need separate class and message checks:
$this->expectException(MyException::class);
$this->expectExceptionMessage('Expected message');
$this->service->doSomethingThatThrows();
```

---

## StaticEntityRepository (Preferred Mocking Utility)

`StaticEntityRepository` is Shopware's built-in test utility. Prefer it over `createMock(EntityRepository::class)` — it's more realistic and records all write operations automatically.

```php
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\DataAbstractionLayerFieldTestBehaviour;
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
$repository->creates;  // array[] — payloads from create() calls
$repository->updates;  // array[] — payloads from update() calls
$repository->upserts;  // array[] — payloads from upsert() calls
$repository->deletes;  // array[] — ID arrays from delete() calls
```

---

## ProductBuilder (Test Fixture Pattern)

Shopware's `ProductBuilder` provides a fluent API for creating complex product test data without manually building the full array.

```php
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Test\IdsCollection;

class ProductIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;
    use DatabaseTransactionBehaviour;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
    }

    public function testProductWithVariants(): void
    {
        $context    = Context::createDefaultContext();
        $repository = $this->getContainer()->get('product.repository');

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
        $result   = $repository->search($criteria, $context)->first();

        static::assertNotNull($result);
        static::assertSame('My Test Product', $result->getName());
    }
}
```

### IdsCollection

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
