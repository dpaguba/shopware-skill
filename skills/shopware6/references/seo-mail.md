# SEO URLs, Sitemap, Mail Templates, Documents, State Machine

---

## Custom SEO URLs (Dynamic — entity-based)

For custom entities that need auto-updating SEO URLs when entity data changes.

### 1. SEO URL Route Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Seo;

use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlMapping;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteConfig;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition;

class MyEntitySeoUrlRoute implements SeoUrlRouteInterface
{
    public const ROUTE_NAME = 'frontend.my-entity.page';
    public const DEFAULT_TEMPLATE = 'my-entity/{{ entity.name | slugify }}';

    public function getConfig(): SeoUrlRouteConfig
    {
        return new SeoUrlRouteConfig(
            MyEntityDefinition::class,
            self::ROUTE_NAME,
            self::DEFAULT_TEMPLATE,
            true  // supports canonical
        );
    }

    public function prepareCriteria(Criteria $criteria, SalesChannelEntity $salesChannel): void
    {
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('active', true));
    }

    public function getMapping(Entity $entity, ?SalesChannelEntity $salesChannel): SeoUrlMapping
    {
        return new SeoUrlMapping(
            $entity,
            ['pathInfo' => '/my-entity/' . $entity->getId()],
            [
                'entity' => $entity,
            ]
        );
    }
}
```

Register:

```xml
<service id="VendorName\PluginName\Seo\MyEntitySeoUrlRoute">
    <tag name="shopware.seo_url.route"/>
</service>
```

### 2. Trigger Regeneration on Entity Write

```php
use Shopware\Core\Content\Seo\SeoUrlUpdater;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;

class MyEntitySeoUpdater implements EventSubscriberInterface
{
    public function __construct(
        private readonly SeoUrlUpdater $seoUrlUpdater,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return ['my_entity.written' => 'onMyEntityWritten'];
    }

    public function onMyEntityWritten(EntityWrittenEvent $event): void
    {
        $ids = array_map(
            fn($result) => $result->getPrimaryKey(),
            $event->getWriteResults()
        );

        $this->seoUrlUpdater->update(MyEntitySeoUrlRoute::ROUTE_NAME, $ids);
    }
}
```

### 3. Register Default Template in Migration

```php
$connection->executeStatement('
    INSERT IGNORE INTO `seo_url_template`
        (`id`, `route_name`, `entity_name`, `template`, `is_valid`, `created_at`)
    VALUES
        (UNHEX(REPLACE(UUID(), \'-\', \'\')),
         \'frontend.my-entity.page\',
         \'my_entity\',
         \'my-entity/{{ entity.name | slugify }}\',
         1,
         NOW())
');
```

### Static SEO URLs (for custom controller routes)

```php
$connection->executeStatement("
    INSERT IGNORE INTO `seo_url`
        (`id`, `language_id`, `sales_channel_id`, `foreign_key`, `route_name`,
         `path_info`, `seo_path_info`, `is_canonical`, `is_modified`, `is_deleted`,
         `created_at`)
    VALUES
        (UNHEX(REPLACE(UUID(), '-', '')),
         UNHEX(REPLACE(:languageId, '-', '')),
         UNHEX(REPLACE(:salesChannelId, '-', '')),
         UNHEX(REPLACE(UUID(), '-', '')),
         'frontend.my-plugin.page',
         '/my-plugin-page',
         'my-plugin-page',
         1, 0, 0, NOW())
");
```

---

## Sitemap — Custom URL Provider

Add custom URLs to the XML sitemap.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Seo;

use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyEntityUrlProvider extends AbstractUrlProvider
{
    public function __construct(
        private readonly EntityRepository $myEntityRepository,
    ) {}

    public function getName(): string
    {
        return 'my_entity';
    }

    public function getDecorated(): AbstractUrlProvider
    {
        throw new DecorationPatternException(self::class);
    }

    public function getUrls(SalesChannelContext $context, int $limit, int $offset = 0): UrlResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        $entities = $this->myEntityRepository->search($criteria, $context->getContext());

        $urls = [];
        foreach ($entities as $entity) {
            $url = new Url();
            $url->setLoc('/my-entity/' . $entity->getId());
            $url->setLastmod(new \DateTimeImmutable());
            $url->setChangefreq('weekly');
            $url->setPriority(0.5);
            $url->setResource(MyEntityDefinition::class);
            $url->setIdentifier($entity->getId());

            $urls[] = $url;
        }

        $nextOffset = ($entities->getTotal() > $offset + $limit) ? $offset + $limit : null;

        return new UrlResult($urls, $nextOffset);
    }
}
```

Register:

```xml
<service id="VendorName\PluginName\Seo\MyEntityUrlProvider">
    <argument type="service" id="my_entity.repository"/>
    <tag name="shopware.sitemap_url_provider"/>
</service>
```

Via YAML config for static URLs:

```yaml
# config/packages/shopware.yaml
shopware:
    sitemap:
        custom_urls:
            - url: '/special-page'
              salesChannelId: null   # null = all channels
              changeFreq: 'monthly'
              priority: 0.8
              lastMod: '2024-01-01 00:00:00'
```

---

## Mail Templates

Create custom mail templates via migration. Shopware discovers templates by type.

### Migration — Create Mail Template Type + Template

```php
public function update(Connection $connection): void
{
    $typeId = Uuid::randomBytes();
    $connection->executeStatement('
        INSERT IGNORE INTO `mail_template_type`
            (`id`, `name`, `technical_name`, `available_entities`, `created_at`)
        VALUES
            (:id, :name, :technicalName, :entities, NOW())
    ', [
        'id'            => $typeId,
        'name'          => 'My Plugin Event Mail',
        'technicalName' => 'my_plugin.order_synced',
        'entities'      => json_encode(['order' => 'order']),
    ]);

    $connection->executeStatement('
        INSERT IGNORE INTO `mail_template_type_translation`
            (`mail_template_type_id`, `language_id`, `name`, `created_at`)
        SELECT :typeId, id, :name, NOW()
        FROM `language`
        WHERE locale_id IN (
            SELECT id FROM locale WHERE code IN (\'en-GB\', \'de-DE\')
        )
    ', [
        'typeId' => $typeId,
        'name'   => 'Order Synced Notification',
    ]);

    $templateId = Uuid::randomBytes();
    $connection->executeStatement('
        INSERT IGNORE INTO `mail_template`
            (`id`, `mail_template_type_id`, `system_default`, `created_at`)
        VALUES (:id, :typeId, 0, NOW())
    ', [
        'id'     => $templateId,
        'typeId' => $typeId,
    ]);

    $connection->executeStatement('
        INSERT IGNORE INTO `mail_template_translation`
            (`mail_template_id`, `language_id`, `sender_name`, `subject`,
             `content_html`, `content_plain`, `created_at`)
        SELECT
            :templateId,
            l.id,
            \'{{ salesChannel.name }}\',
            \'Order #{{ order.orderNumber }} synced\',
            :html,
            :text,
            NOW()
        FROM `language` l
        INNER JOIN `locale` loc ON l.locale_id = loc.id
        WHERE loc.code = \'en-GB\'
    ', [
        'templateId' => $templateId,
        'html'       => '<p>Your order #{{ order.orderNumber }} has been synced.</p>',
        'text'       => 'Your order #{{ order.orderNumber }} has been synced.',
    ]);

    $connection->executeStatement('
        INSERT IGNORE INTO `mail_template_translation`
            (`mail_template_id`, `language_id`, `sender_name`, `subject`,
             `content_html`, `content_plain`, `created_at`)
        SELECT
            :templateId,
            l.id,
            \'{{ salesChannel.name }}\',
            \'Bestellung #{{ order.orderNumber }} synchronisiert\',
            :html,
            :text,
            NOW()
        FROM `language` l
        INNER JOIN `locale` loc ON l.locale_id = loc.id
        WHERE loc.code = \'de-DE\'
    ', [
        'templateId' => $templateId,
        'html'       => '<p>Ihre Bestellung #{{ order.orderNumber }} wurde synchronisiert.</p>',
        'text'       => 'Ihre Bestellung #{{ order.orderNumber }} wurde synchronisiert.',
    ]);
}
```

### Send Mail via MailService

```php
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class MyMailSender
{
    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
    ) {}

    public function sendOrderSyncedMail(Order $order, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('mailTemplateType.technicalName', 'my_plugin.order_synced')
        );
        $criteria->addAssociation('translations');

        $template = $this->mailTemplateRepository->search($criteria, $context)->first();

        if ($template === null) {
            return;
        }

        $customer = $order->getOrderCustomer();

        $this->mailService->send([
            'recipients'   => [$customer->getEmail() => $customer->getFirstName()],
            'senderName'   => '{{ salesChannel.name }}',
            'subject'      => $template->getSubject(),
            'contentHtml'  => $template->getContentHtml(),
            'contentPlain' => $template->getContentPlain(),
            'templateData' => [
                'order'       => $order,
                'salesChannel' => $order->getSalesChannel(),
            ],
        ], $context);
    }
}
```

---

## Order State Machine

Orders have three separate state machines:
- **Order** (`order.state`): open → in_progress → completed / cancelled
- **Transaction** (`order_transaction.state`): open → paid / failed / refunded
- **Delivery** (`order_delivery.state`): open → shipped → returned

### Perform a Transition

```php
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;

class OrderStateService
{
    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly EntityRepository $orderRepository,
    ) {}

    public function processOrder(string $orderId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderDefinition::ENTITY_NAME,
                $orderId,
                'process',   // transition action name
                'stateId'
            ),
            $context
        );
    }

    public function markTransactionPaid(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                'paid',
                'stateId'
            ),
            $context
        );
    }

    public function shipDelivery(string $orderId, Context $context): void
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('deliveries');
        $order = $this->orderRepository->search($criteria, $context)->first();

        $deliveryId = $order->getDeliveries()->first()->getId();

        $this->stateMachineRegistry->transition(
            new Transition(
                OrderDeliveryDefinition::ENTITY_NAME,
                $deliveryId,
                'ship',
                'stateId'
            ),
            $context
        );
    }
}
```

### Payment Handler Convenience Methods

Prefer `OrderTransactionStateHandler` in payment handlers:

```php
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class MyPaymentHandler
{
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
    ) {}

    public function finalize(AsyncPaymentTransactionStruct $transaction, ...): void
    {
        $transactionId = $transaction->getOrderTransaction()->getId();

        $this->transactionStateHandler->paid($transactionId, $context);

        $this->transactionStateHandler->fail($transactionId, $context);

        $this->transactionStateHandler->payPartially($transactionId, $context);

        $this->transactionStateHandler->refund($transactionId, $context);
    }
}
```

### Available Transitions

**Order (`order`):**

| From | Action | To |
|------|--------|----|
| open | process | in_progress |
| open | cancel | cancelled |
| in_progress | complete | completed |
| in_progress | cancel | cancelled |
| cancelled | reopen | open |

**Transaction (`order_transaction`):**

| Action | Notes |
|--------|-------|
| `do_pay` | Marks as in_progress |
| `paid` | Fully paid |
| `paid_partially` | Partial payment |
| `refund` | Full refund |
| `refund_partially` | Partial refund |
| `fail` | Payment failed |
| `cancel` | Cancelled |
| `reopen` | Back to open |

**Delivery (`order_delivery`):**

| Action | Notes |
|--------|-------|
| `ship` | Shipped |
| `ship_partially` | Partial shipment |
| `retour` | Returned |
| `retour_partially` | Partial return |
| `cancel` | Cancelled |
| `reopen` | Back to open |

### Get Available Transitions

```php
$transitions = $this->stateMachineRegistry->getAvailableTransitions(
    OrderDefinition::ENTITY_NAME,
    $orderId,
    'stateId',
    $context
);

foreach ($transitions as $transition) {
    echo $transition->getActionName(); // e.g. "process"
    echo $transition->getToStateMachineState()->getTechnicalName(); // e.g. "in_progress"
}
```

### Listen to State Changes

```php
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;

public static function getSubscribedEvents(): array
{
    return [StateMachineTransitionEvent::class => 'onTransition'];
}

public function onTransition(StateMachineTransitionEvent $event): void
{
    if ($event->getEntityName() !== 'order_transaction') {
        return;
    }

    $toState = $event->getToPlace()->getTechnicalName();
    $entityId = $event->getEntityId();

    if ($toState === 'paid') {
    }
}
```

Or listen to the specific typed events:

```php
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;

public static function getSubscribedEvents(): array
{
    return [
        'state_enter.order_transaction.state.paid' => 'onTransactionPaid',
    ];
}
```

---

## Custom Documents (PDF generation)

### Document Type Class

Use `AbstractDocumentRenderer` (SW 6.5+). The old `DocumentGeneratorInterface` was deprecated in 6.4.14 and removed in 6.5.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Document;

use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Renderer\RendererResult;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Framework\Context;
use Twig\Environment;

class MyDocumentRenderer extends AbstractDocumentRenderer
{
    public const TYPE = 'my_document';

    public function __construct(private readonly Environment $twig) {}

    public function supports(): string
    {
        return self::TYPE;
    }

    public function render(
        array $operations,
        Context $context,
        DocumentRendererConfig $rendererConfig
    ): RendererResult {
        $result = new RendererResult();

        foreach ($operations as $operation) {
            \assert($operation instanceof DocumentGenerateOperation);

            $html = $this->twig->render(
                '@VendorNamePluginName/documents/my-document.html.twig',
                ['config' => $operation->getConfig()]
            );

            $document = new RenderedDocument($html);
            $document->setName(sprintf('my-document-%s.pdf', $operation->getOrderId()));
            $document->setContentType('application/pdf');

            $result->addSuccess($operation->getOrderId(), $document);
        }

        return $result;
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        throw new \Shopware\Core\Framework\Plugin\Exception\DecorationPatternException(self::class);
    }
}
```

Register:

```xml
<service id="VendorName\PluginName\Document\MyDocumentRenderer">
    <argument type="service" id="twig"/>
    <tag name="shopware.document.renderer"/>
</service>
```

Register document type in migration:

```php
$connection->executeStatement('
    INSERT IGNORE INTO `document_type`
        (`id`, `technical_name`, `created_at`)
    VALUES
        (UNHEX(REPLACE(UUID(), \'-\', \'\')), \'my_document\', NOW())
');
```
