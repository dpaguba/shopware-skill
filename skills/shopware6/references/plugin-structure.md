# Plugin Structure — Full Anatomy

---

## composer.json

```json
{
    "name": "vendor-name/plugin-name",
    "description": "My Shopware 6 Plugin",
    "type": "shopware-platform-plugin",
    "license": "MIT",
    "require": {
        "shopware/core": "~6.6.0"
    },
    "extra": {
        "shopware-plugin-class": "VendorName\\PluginName\\PluginName",
        "label": {
            "de-DE": "Mein Plugin",
            "en-GB": "My Plugin"
        },
        "description": {
            "de-DE": "Beschreibung meines Plugins",
            "en-GB": "Description of my plugin"
        }
    },
    "autoload": {
        "psr-4": {
            "VendorName\\PluginName\\": "src/"
        }
    }
}
```

---

## Plugin Bootstrap Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class PluginName extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        // Create default data, custom fields, etc.
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `my_table`');
    }

    public function activate(ActivateContext $activateContext): void {}

    public function deactivate(DeactivateContext $deactivateContext): void {}

    public function update(UpdateContext $updateContext): void {}
}
```

Only implement lifecycle methods when there is a concrete reason. An empty `install()` override is unnecessary.

---

## services.xml — Full Reference

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services
           http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>

        <!-- Basic Service -->
        <service id="VendorName\PluginName\Service\MyService">
            <argument type="service" id="product.repository"/>
            <argument type="service" id="logger"/>
        </service>

        <!-- Event Subscriber -->
        <service id="VendorName\PluginName\Subscriber\MySubscriber">
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Twig Extension -->
        <service id="VendorName\PluginName\Twig\MyExtension">
            <tag name="twig.extension"/>
        </service>

        <!-- Console Command -->
        <service id="VendorName\PluginName\Command\MyCommand">
            <tag name="console.command"/>
        </service>

        <!-- Scheduled Task Handler -->
        <service id="VendorName\PluginName\ScheduledTask\MyTaskHandler">
            <argument type="service" id="scheduled_task.repository"/>
            <argument type="service" id="logger"/>
            <tag name="messenger.message_handler"/>
        </service>

        <!-- Decorator (wraps original.service.id) -->
        <service id="VendorName\PluginName\Decorator\MyDecorator"
                 decorates="original.service.id">
            <argument type="service"
                      id="VendorName\PluginName\Decorator\MyDecorator.inner"/>
        </service>

        <!-- Entity Definition -->
        <service id="VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition">
            <tag name="shopware.entity.definition"/>
        </service>

        <!-- Storefront Subscriber with SalesChannel context -->
        <service id="VendorName\PluginName\Storefront\Subscriber\PageSubscriber">
            <argument type="service" id="product.repository"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Admin API Controller -->
        <service id="VendorName\PluginName\Api\Controller\MyApiController"
                 public="true">
            <argument type="service" id="my_entity.repository"/>
        </service>

    </services>
</container>
```

---

## Directory Structure — Complete

```
PluginName/
├── composer.json
├── src/
│   ├── PluginName.php                          # Bootstrap
│   ├── Resources/
│   │   ├── config/
│   │   │   ├── services.xml                    # DI container
│   │   │   ├── routes.xml                      # Route imports
│   │   │   └── config.xml                      # Plugin configuration
│   │   ├── snippet/
│   │   │   ├── en_GB/
│   │   │   │   └── my-plugin.en-GB.json
│   │   │   └── de_DE/
│   │   │       └── my-plugin.de-DE.json
│   │   └── views/
│   │       ├── storefront/                     # Twig overrides
│   │       └── administration/                 # Admin Twig overrides
│   ├── Migration/
│   │   └── Migration1234567890CreateTable.php
│   ├── Core/
│   │   └── Content/
│   │       └── MyEntity/
│   │           ├── MyEntityDefinition.php
│   │           ├── MyEntity.php
│   │           └── MyEntityCollection.php
│   ├── Service/
│   │   └── MyService.php
│   ├── Subscriber/
│   │   └── MySubscriber.php
│   ├── Command/
│   │   └── MyCommand.php
│   ├── Storefront/
│   │   ├── Controller/
│   │   │   └── MyController.php
│   │   └── Subscriber/
│   │       └── PageSubscriber.php
│   └── Api/
│       └── Controller/
│           └── MyApiController.php
└── tests/
    ├── Unit/
    └── Integration/
```

---

## Plugin Configuration (config.xml)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">

    <card>
        <title>My Plugin Configuration</title>
        <title lang="de-DE">Meine Plugin-Konfiguration</title>

        <input-field type="text">
            <name>apiKey</name>
            <label>API Key</label>
            <label lang="de-DE">API-Schlüssel</label>
            <required>true</required>
        </input-field>

        <input-field type="bool">
            <name>enableFeature</name>
            <defaultValue>false</defaultValue>
            <label>Enable Feature</label>
        </input-field>

        <input-field type="int">
            <name>maxItems</name>
            <defaultValue>10</defaultValue>
            <label>Max Items</label>
        </input-field>
    </card>
</config>
```

Read config in PHP:

```php
use Shopware\Core\System\SystemConfig\SystemConfigService;

class MyService
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {}

    public function getApiKey(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(
            'PluginName.config.apiKey',
            $salesChannelId
        );
    }
}
```

---

## Scheduled Tasks

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class MyTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'vendor_name.my_task';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // Every 24 hours in seconds
    }
}
```

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\ScheduledTask;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: MyTask::class)]
class MyTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public function run(): void
    {
    }
}
```

Register task in `services.xml`:

```xml
<service id="VendorName\PluginName\ScheduledTask\MyTask">
    <tag name="shopware.scheduled.task"/>
</service>
```

---

## Console Command

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'my-plugin:do-something',
    description: 'Does something useful',
)]
class MyCommand extends Command
{
    public function __construct(
        private readonly MyService $myService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Entity ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        $output->writeln('Running...');

        $this->myService->doSomething($id);

        $output->writeln('<info>Done!</info>');

        return Command::SUCCESS;
    }
}
```

---

## Flow Builder Custom Action (6.5+)

```php
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Event\FlowEventAware;

class SendNotificationAction extends FlowAction
{
    public static function getName(): string
    {
        return 'action.my_plugin.send_notification';
    }

    public function requirements(): array
    {
        return [OrderAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        $orderId = $flow->getData(OrderAware::ORDER_ID);
    }
}
```

Register in `services.xml` with `key` attribute (required for Flow Builder dispatch lookup):

```xml
<service id="VendorName\PluginName\Flow\SendNotificationAction">
    <tag name="flow.action" key="action.my_plugin.send_notification" priority="600"/>
</service>
```

---

## Admin API Controller

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Api\Controller;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class MyApiController extends AbstractController
{
    #[Route(
        path: '/api/_action/my-plugin/my-endpoint',
        name: 'api.action.my_plugin.my_endpoint',
        methods: ['POST']
    )]
    public function myEndpoint(Request $request, Context $context): JsonResponse
    {
        $data = $request->request->all();

        return new JsonResponse(['success' => true, 'data' => $data]);
    }
}
```

---

## Key Shopware Interfaces & Base Classes

| Interface / Class | Use for |
|---|---|
| `Plugin` | Plugin bootstrap |
| `EntityDefinition` | Custom entity schema |
| `Entity` | Custom entity data object |
| `EntityCollection` | Typed collection of entities |
| `EntityTranslationDefinition` | Translatable entity |
| `EventSubscriberInterface` | Listen to events |
| `StorefrontController` | Storefront routes |
| `AbstractController` | Admin API routes |
| `ScheduledTask` | Recurring background job |
| `ScheduledTaskHandler` | Executes scheduled task |
| `FlowAction` | Custom flow builder action |
| `ThemeInterface` | Theme bundle |

---

## Multi-Channel & B2B

### Sales Channel Context Awareness

Services that must behave differently per sales channel receive `SalesChannelContext` instead of plain `Context`.

```php
// Ignores sales channel — same result for all channels:
public function getPrice(string $productId, Context $context): float
{
    $product = $this->productRepository->search(new Criteria([$productId]), $context)->first();
    return $product->getPrice()->first()->getGross();
}

public function getPrice(string $productId, SalesChannelContext $context): float
{
    $criteria = new Criteria([$productId]);
    $criteria->addAssociation('prices');

    $product = $this->salesChannelProductRepository->search($criteria, $context)->first();
    return $product->getCalculatedPrice()->getTotalPrice();
}
```

Use `sales_channel.product.repository` (not `product.repository`) for Storefront product queries — it automatically applies:
- Sales channel visibility filter
- Currency conversion
- Customer group pricing
- Tax calculation

```xml
<!-- services.xml -->
<service id="VendorName\PluginName\Service\PriceService">
    <argument type="service" id="sales_channel.product.repository"/>
</service>
```

### Advanced Pricing (list price, regulation price)

```php
$this->productRepository->upsert([
    [
        'id'    => $productId,
        'price' => [
            [
                'currencyId' => Defaults::CURRENCY,
                'gross'      => 99.99,        // current selling price
                'net'        => 84.03,
                'linked'     => true,
                'listPrice'  => [             // crossed-out "was" price
                    'gross'  => 149.99,
                    'net'    => 126.05,
                    'linked' => true,
                ],
                'regulationPrice' => [        // "recommended retail price" (UVP/RRP)
                    'gross'  => 129.99,
                    'net'    => 109.24,
                    'linked' => true,
                ],
            ],
        ],
    ],
], $context);
```

Access calculated prices on Storefront:

```twig
{# Current price #}
{{ page.product.calculatedPrice.totalPrice | currency }}

{# List (was) price — check it exists first #}
{% if page.product.calculatedPrice.listPrice %}
    <s>{{ page.product.calculatedPrice.listPrice.price | currency }}</s>
    {{ page.product.calculatedPrice.listPrice.percentage }}% off
{% endif %}
```

### Sales Channel-Specific Configuration

Read system config scoped to a sales channel:

```php
$value = $this->systemConfigService->get(
    'MyPlugin.config.apiKey',
    $salesChannelContext->getSalesChannelId()  // falls back to global if not set
);
```

### B2B Patterns (Shopware B2B Suite)

B2B Suite is a separate Shopware extension. Without it, model B2B via:

**Company → Customer relationship:**

```php
$this->customerRepository->update([
    [
        'id'           => $customerId,
        'customFields' => [
            'my_plugin_company_id'  => $companyId,
            'my_plugin_role'        => 'employee',  // 'admin' | 'employee' | 'manager'
            'my_plugin_budget'      => 5000.00,
        ],
    ],
], $context);
```

**Check B2B context in subscriber:**

```php
public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
{
    $customer = $event->getSalesChannelContext()->getCustomer();
    if ($customer === null) {
        return;
    }

    $companyId = $customer->getCustomFields()['my_plugin_company_id'] ?? null;
    if ($companyId === null) {
        return; // not a B2B customer
    }

    $budget = (float) ($customer->getCustomFields()['my_plugin_budget'] ?? 0);
    $orderTotal = $event->getOrder()->getAmountTotal();

    if ($orderTotal > $budget) {
        $this->messageBus->dispatch(new OrderApprovalRequiredMessage(
            $event->getOrder()->getId(),
            $companyId
        ));
    }
}
```

**Tiered B2B pricing via customer groups:**

```php
$this->customerGroupRepository->create([
    [
        'id'                  => $groupId,
        'name'                => 'B2B Customers',
        'displayGross'        => false,  // B2B sees net prices
        'registrationActive'  => false,
        'translations'        => [
            'de-DE' => ['name' => 'B2B Kunden'],
            'en-GB' => ['name' => 'B2B Customers'],
        ],
    ],
], $context);

$this->customerRepository->update([
    ['id' => $customerId, 'groupId' => $groupId],
], $context);
```

### Multi-Sales-Channel Subscribers

Always check sales channel in subscribers when behavior should differ per channel:

```php
public function onProductPageLoaded(ProductPageLoadedEvent $event): void
{
    $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

    if ($salesChannelId !== $this->targetSalesChannelId) {
        return;
    }
}
