# Plugin Structure: Full Anatomy

---

## composer.json

```json
{
    "name": "vendor-name/plugin-name",
    "description": "My Shopware 6 Plugin",
    "type": "shopware-platform-plugin",
    "license": "MIT",
    "require": {
        "shopware/core": "~6.7.0"
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

## services.php: Full Reference

> XML service config (`services.xml`) is deprecated since 6.7.14 and removed in 6.8; PHP config works on
> 6.6 and 6.7 too. The same applies to `routes.xml` (use `routes.php`). Service ids, arguments and tags
> stay identical, only the notation changes. `config.xml`, `custom-fields.xml` and app manifests stay XML.

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\PluginName\Api\Controller\MyApiController;
use VendorName\PluginName\Command\MyCommand;
use VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition;
use VendorName\PluginName\Decorator\MyDecorator;
use VendorName\PluginName\ScheduledTask\MyTaskHandler;
use VendorName\PluginName\Service\MyService;
use VendorName\PluginName\Storefront\Subscriber\PageSubscriber;
use VendorName\PluginName\Subscriber\MySubscriber;
use VendorName\PluginName\Twig\MyExtension;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Basic service
    $services->set(MyService::class)
        ->args([service('product.repository'), service('logger')]);

    // Event subscriber
    $services->set(MySubscriber::class)
        ->tag('kernel.event_subscriber');

    // Twig extension
    $services->set(MyExtension::class)
        ->tag('twig.extension');

    // Console command
    $services->set(MyCommand::class)
        ->tag('console.command');

    // Scheduled task handler
    $services->set(MyTaskHandler::class)
        ->args([service('scheduled_task.repository'), service('logger')])
        ->tag('messenger.message_handler');

    // Decorator (wraps original.service.id)
    $services->set(MyDecorator::class)
        ->decorate('original.service.id')
        ->args([service('.inner')]);

    // Entity definition
    $services->set(MyEntityDefinition::class)
        ->tag('shopware.entity.definition');

    // Storefront subscriber with SalesChannel context
    $services->set(PageSubscriber::class)
        ->args([service('product.repository')])
        ->tag('kernel.event_subscriber');

    // Admin API controller
    $services->set(MyApiController::class)
        ->public()
        ->args([service('my_entity.repository')])
        ->call('setContainer', [service('service_container')]);
};
```

`$services->defaults()->autowire()->autoconfigure();` at the top removes most explicit `args()` and
`tag()` calls: Shopware registers its base classes (`EntityDefinition`, `EntityExtension`,
`ScheduledTask`, `EntityIndexer`, ...) for autoconfiguration.

---

## Directory Structure: Complete

```
PluginName/
├── composer.json
├── src/
│   ├── PluginName.php                          # Bootstrap
│   ├── Resources/
│   │   ├── config/
│   │   │   ├── services.php                    # DI container (services.xml deprecated since 6.7.14)
│   │   │   ├── routes.php                      # Route imports (routes.xml deprecated since 6.7.14)
│   │   │   ├── config.xml                      # Plugin configuration
│   │   │   └── custom-fields.xml               # Declarative custom fields (6.7.13+)
│   │   ├── snippet/
│   │   │   ├── storefront.en.json              # Storefront snippets, language-only code recommended in 6.7
│   │   │   └── storefront.de.json
│   │   ├── app/
│   │   │   ├── administration/src/main.js      # Admin extension entry
│   │   │   └── storefront/src/main.js          # Storefront JS plugins entry
│   │   └── views/
│   │       └── storefront/                     # Twig overrides
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
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/SystemConfig/Schema/config.xsd">

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

### Field types

| `type` | Renders |
|---|---|
| `text` | Single-line input |
| `textarea` | Multi-line input |
| `text-editor` | WYSIWYG editor |
| `url` | URL input with validation |
| `password` | Masked input |
| `int` / `float` | Numeric input, supports `<min>`, `<max>`, `<step>` |
| `bool` | Toggle |
| `checkbox` | Checkbox |
| `datetime` / `date` / `time` | Pickers |
| `colorpicker` | Color picker |
| `single-select` | Dropdown, one value |
| `multi-select` | Dropdown, list of values |
| `price` | Price input (gross/net) |

`single-select` and `multi-select` take their choices from `<options>`:

```xml
<input-field type="single-select">
    <name>deliveryMode</name>
    <label>Delivery mode</label>
    <defaultValue>standard</defaultValue>
    <options>
        <option>
            <id>standard</id>
            <name>Standard</name>
            <name lang="de-DE">Standard</name>
        </option>
        <option>
            <id>express</id>
            <name>Express</name>
        </option>
    </options>
</input-field>
```

Field names must be unique within the plugin, at least four characters, letters and digits only, and
must not start with a digit. Add `cache-relevant="true"` (6.7.12+) to an `<input-field>` or `<component>`
whose value changes Storefront output, so the HTTP cache is invalidated when a merchant edits it. From 6.8
config writes no longer invalidate the cache by default, so the attribute becomes mandatory for such fields.

### Embedding admin components with `<component>`

`<component>` drops an existing Administration Vue component straight into the config page. This is how
you get entity pickers and media uploads without writing an admin module.

```xml
<component name="sw-entity-single-select">
    <name>defaultProductId</name>
    <entity>product</entity>
    <label>Default product</label>
    <label lang="de-DE">Standardprodukt</label>
</component>

<component name="sw-entity-multi-id-select">
    <name>excludedCategoryIds</name>
    <entity>category</entity>
    <label>Excluded categories</label>
</component>

<component name="sw-media-field">
    <name>fallbackImage</name>
    <label>Fallback image</label>
</component>
```

| Component | Stores |
|---|---|
| `sw-entity-single-select` | One entity ID as a string |
| `sw-entity-multi-id-select` | Array of entity IDs |
| `sw-media-field` | One media ID |
| `sw-text-editor` | HTML string |
| `sw-snippet-field` | Snippet key, editable per language |

Any child element other than `name` is passed to the component as a prop, which is how `<entity>` reaches
`sw-entity-single-select`. A custom component works the same way as long as it is registered before the
settings page renders:

```xml
<component name="my-plugin-config-widget">
    <name>widgetValue</name>
    <label>Custom widget</label>
</component>
```

```js
Shopware.Component.register('my-plugin-config-widget', {
    template: '<mt-card :title="label"><!-- ... --></mt-card>',
    props: ['value', 'label'],
    // emit 'update:value' to write back into the plugin config
});
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

## Choosing the execution mechanism

Four mechanisms cover almost everything a plugin needs to run. Picking the wrong one is the most common
source of slow checkouts and lost work.

| Mechanism | Runs | Failure behaviour | Pick when |
|---|---|---|---|
| Symfony event subscriber | Synchronously, inside the request | Exception propagates and can break the request | You must influence the result before the response is sent |
| Message queue (Messenger) | Asynchronously, in a worker | Retried, then moved to the failure transport | The work is slow, external, or may fail without harming the request |
| Scheduled task | Periodically, in a worker | Retried on the next run | The work is recurring and time based, not event based |
| Console command | Manually or from cron/CI | Visible in the terminal exit code | An operator or a pipeline triggers it |

### Symfony events

Use for anything that changes what the current request produces: enriching a page, adding line items,
validating a cart, modifying an entity payload before it is written.

```php
class MySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $event->getPage()->addExtension('myData', new ArrayStruct(['foo' => 'bar']));
    }
}
```

Rules of thumb: never call an external API from a subscriber that runs during checkout, never write large
amounts of data, and never swallow exceptions to "make it safe". If the work can fail independently of
the request, it belongs in the queue.

### Message queue

Use for external HTTP calls, mail dispatch, imports, indexing, image processing, anything slow. Dispatch
from a subscriber, handle in a worker.

```php
// Message: plain data only, must be serialisable.
// AsyncMessageInterface routes it to the `async` transport; without it the handler runs synchronously.
class SyncOrderMessage implements AsyncMessageInterface
{
    public function __construct(public readonly string $orderId) {}
}

// Dispatch
class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MessageBusInterface $bus) {}

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->bus->dispatch(new SyncOrderMessage($event->getOrder()->getId()));
    }
}

// Handle
#[AsMessageHandler]
class SyncOrderHandler
{
    public function __construct(private readonly MyGateway $gateway) {}

    public function __invoke(SyncOrderMessage $message): void
    {
        $this->gateway->sync($message->orderId);
    }
}
```

Put IDs in the message, never entities: the payload is serialised, and by the time the worker picks it up
the entity may have changed. `AsyncMessageInterface` (and `LowPriorityMessageInterface` for the
`low_priority` transport) live in `Shopware\Core\Framework\MessageQueue`. `#[AsMessageHandler]` is only read
when the service is autoconfigured; otherwise tag the handler with `messenger.message_handler`. Workers need `bin/console messenger:consume` running; without a worker the
message sits in the queue and nothing happens, which is the usual explanation for "my handler never runs".

### Scheduled tasks

A scheduled task is a message dispatched by Shopware on an interval. Use it for cleanup, periodic sync,
recalculation. Do not use it as a poor substitute for a queue: an interval of 60 seconds still depends on
a running worker and gives no per-item retry.

### Console commands

Use for operator-facing tooling: one-off migrations, diagnostics, manual reindexing, anything run from a
deploy pipeline. A command should be a thin wrapper around a service that the queue can also call, so the
same logic is reachable both ways.

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

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: MyTask::class)]
class MyTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,               // required since SW 6.7
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
    }
}
```

> **SW 6.7 breaking change.** `ScheduledTaskHandler::__construct()` takes a `Psr\Log\LoggerInterface` as
> its second argument. A 6.6 handler that passes only the repository fails to instantiate on 6.7.

Register task and handler in `services.php`:

```php
$services->set(MyTask::class)
    ->tag('shopware.scheduled.task');

$services->set(MyTaskHandler::class)
    ->args([service('scheduled_task.repository'), service('logger')])
    ->tag('messenger.message_handler');
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
use Shopware\Core\Framework\Event\OrderAware;

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

Register in `services.php` with the `key` tag attribute (required for Flow Builder dispatch lookup):

```php
$services->set(SendNotificationAction::class)
    ->tag('flow.action', ['key' => 'action.my_plugin.send_notification', 'priority' => 600]);
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

Register it `public()` with `setContainer` (see the `services.php` reference above) and import the
controllers in `src/Resources/config/routes.php`:

```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Api/Controller/**/*Controller.php', 'attribute');
};
```

Custom request headers on cross-origin API calls (6.7.15+): implement
`Shopware\Core\Framework\Api\Cors\CorsHeaderProviderInterface::provide(CorsHeaders $headers)` and call
`$headers->addAllowed('x-my-header')`; autoconfigured services are picked up, otherwise tag them
`shopware.api.cors_header_provider`.

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
// Ignores sales channel, same result for all channels:
public function getPrice(string $productId, Context $context): float
{
    $product = $this->productRepository->search(new Criteria([$productId]), $context)->getEntities()->first();
    return $product->getPrice()->first()->getGross();
}

public function getPrice(string $productId, SalesChannelContext $context): float
{
    $criteria = new Criteria([$productId]);
    $criteria->addAssociation('prices');

    $product = $this->salesChannelProductRepository->search($criteria, $context)->getEntities()->first();
    return $product->getCalculatedPrice()->getTotalPrice();
}
```

Use `sales_channel.product.repository` (not `product.repository`) for Storefront product queries; it automatically applies:
- Sales channel visibility filter
- Currency conversion
- Customer group pricing
- Tax calculation

```php
// services.php
$services->set(PriceService::class)
    ->args([service('sales_channel.product.repository')]);
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
                'regulationPrice' => [        // lowest price of the last 30 days (EU Omnibus, Art. 6a 98/6/EC)
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

{# List (was) price, check it exists first #}
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
```
