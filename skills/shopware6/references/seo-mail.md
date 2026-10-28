# SEO URLs, Sitemap, Mail Templates, Documents, State Machine

---

## Custom SEO URLs (Dynamic, entity-based)

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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition;

class MyEntitySeoUrlRoute implements SeoUrlRouteInterface
{
    public const ROUTE_NAME = 'frontend.my-entity.page';
    public const DEFAULT_TEMPLATE = 'my-entity/{{ entity.name | slugify }}';

    public function __construct(
        private readonly MyEntityDefinition $definition,
    ) {}

    public function getConfig(): SeoUrlRouteConfig
    {
        return new SeoUrlRouteConfig(
            $this->definition,  // EntityDefinition instance (a class-string fails)
            self::ROUTE_NAME,
            self::DEFAULT_TEMPLATE,
            true,               // skipInvalid: skip entities whose template fails to render
            'myEntityId'        // route parameter that receives the primary key
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
            // route parameters: path_info is generated from ROUTE_NAME (/my-entity/{myEntityId})
            $this->getConfig()->getPrimaryKeyParameter($entity->getId()),
            [
                'entity' => $entity,
            ]
        );
    }
}
```

Register in `src/Resources/config/services.php` (snippets below are `$services->set(...)` calls in the same closure):

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition;
use VendorName\PluginName\Seo\MyEntitySeoUrlRoute;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(MyEntitySeoUrlRoute::class)
        ->args([service(MyEntityDefinition::class)])
        ->tag('shopware.seo_url.route');
};
```

Routes tagged `shopware.seo_url.route` generate SEO URLs for Storefront sales channels only. Since 6.7.14, headless (API type) sales channels get SEO URLs for products, categories and landing pages through core `store-api.*` routes, separate `seo_url_template` rows (`is_headless = 1`) and domains flagged `is_external_storefront`.

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
        $this->seoUrlUpdater->update(MyEntitySeoUrlRoute::ROUTE_NAME, $event->getIds());
    }
}
```

### 3. Register Default Template in Migration

A default template (`sales_channel_id` NULL) is required: `SeoUrlUpdater` throws `Default templates not configured` without one.

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

Since 6.7.14, changing `template` through the DAL (Admin, Sync API, repository) queues `SeoUrlTemplateIndexingMessage` on `async` and regenerates the route's SEO URLs. Raw SQL like this does not. If install or update code writes the template via the DAL every time, compare values first or each run queues a full regeneration.

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

## Sitemap: Custom URL Provider

Add custom URLs to the XML sitemap.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Seo;

use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition;

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

    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        $offset ??= 0;

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        $entities = $this->myEntityRepository->search($criteria, $context->getContext())->getEntities();

        $urls = [];
        foreach ($entities as $entity) {
            $url = new Url();
            $url->setLoc('/my-entity/' . $entity->getId());
            $url->setLastmod($entity->getUpdatedAt() ?? $entity->getCreatedAt() ?? new \DateTimeImmutable());
            $url->setChangefreq('weekly');
            $url->setPriority(0.5);
            $url->setResource(MyEntityDefinition::class);
            $url->setIdentifier($entity->getId());

            $urls[] = $url;
        }

        // a full batch means there may be more; the core providers use the same check
        $nextOffset = $entities->count() === $limit ? $offset + $limit : null;

        return new UrlResult($urls, $nextOffset);
    }
}
```

Register:

```php
use VendorName\PluginName\Seo\MyEntityUrlProvider;

$services->set(MyEntityUrlProvider::class)
    ->args([service('my_entity.repository')])
    ->tag('shopware.sitemap_url_provider');
```

Since 6.7.15, sitemaps are also generated for headless sales channels with an external storefront domain, and every provider is called for them. Check `$context->getSalesChannel()->getTypeId()` if your URLs exist only in the Storefront.

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

### Migration: Create Mail Template Type + Template

```php
public function update(Connection $connection): void
{
    $typeId = Uuid::randomBytes();
    // the type name lives only in mail_template_type_translation
    $connection->executeStatement('
        INSERT IGNORE INTO `mail_template_type`
            (`id`, `technical_name`, `available_entities`, `created_at`)
        VALUES
            (:id, :technicalName, :entities, NOW())
    ', [
        'id'            => $typeId,
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

Do not write `mail_template_type.template_data`: the column is deprecated and removed in 6.8.

### Send Mail via MailService

```php
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class MyMailSender
{
    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
    ) {}

    public function sendOrderSyncedMail(OrderEntity $order, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('mailTemplateType.technicalName', 'my_plugin.order_synced')
        );
        $criteria->setLimit(1);

        $template = $this->mailTemplateRepository->search($criteria, $context)->getEntities()->first();
        $customer = $order->getOrderCustomer();

        if ($template === null || $customer === null) {
            return;
        }

        $this->mailService->send(
            [
                'recipients'     => [$customer->getEmail() => $customer->getFirstName()],
                'salesChannelId' => $order->getSalesChannelId(), // sets salesChannel, sender and snippets
                'senderName'     => $template->getTranslation('senderName'),
                'subject'        => $template->getTranslation('subject'),
                'contentHtml'    => $template->getTranslation('contentHtml'),
                'contentPlain'   => $template->getTranslation('contentPlain'),
            ],
            $context,
            ['order' => $order], // Twig variables go in the third argument
        );
    }
}
```

For a known template id, `Shopware\Core\Content\Mail\Service\SendMailTemplate::send(new SendMailTemplateParams(...), $context)` loads the template and builds this payload for you.

Since 6.7.15, `MailService` itself switches the translator to the mail's sales channel while rendering. A full replacement of `AbstractMailService` that does not call the decorated service must do this with `AbstractTranslator::injectSettings()` and `resetInjection()`.

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
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->getEntities()->first();

        // primaryOrderDelivery replaces deliveries->first() (UPGRADE-6.8)
        $deliveryId = $order?->getPrimaryOrderDeliveryId();
        if ($deliveryId === null) {
            return;
        }

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
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

class MyPaymentHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
    ) {}

    // supports() and pay() omitted
    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $transactionId = $transaction->getOrderTransactionId();

        // pick the one matching the provider's answer:
        $this->transactionStateHandler->paid($transactionId, $context);
        // $this->transactionStateHandler->fail($transactionId, $context);
        // $this->transactionStateHandler->paidPartially($transactionId, $context); // payPartially() is deprecated
        // $this->transactionStateHandler->authorize($transactionId, $context);
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
| completed | reopen | open |

**Transaction (`order_transaction`):**

| Action | Notes |
|--------|-------|
| `process` | Marks as in_progress (`do_pay` is the deprecated 6.6 name) |
| `process_unconfirmed` | Marks as unconfirmed |
| `authorize` | Authorized, capture pending |
| `paid` | Fully paid |
| `paid_partially` | Partial payment |
| `refund` | Full refund |
| `refund_partially` | Partial refund |
| `fail` | Payment failed |
| `cancel` | Cancelled |
| `remind` | Reminded |
| `chargeback` | Chargeback |
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

**Custom transitions need their own action name.** Since 6.7.15, an action with several transitions from the same source state resolves to the oldest one and triggers a deprecation. In 6.8 a migration drops such duplicates and the unique key `uniq.state_machine_transition.action_name_from_state` (`action_name`, `state_machine_id`, `from_state_id`) rejects new ones.

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

Since 6.7.14, `state_machine_history` rows carry `sourceType` (`admin-api`, `sales-channel`, `system`, or a custom `ContextSource::$type`), so a customer's storefront cancellation can be told apart from one by an integration.

---

## Custom Documents (PDF generation)

### Document Type Class

Use `AbstractDocumentRenderer` (SW 6.5+). The old `DocumentGeneratorInterface` was deprecated in 6.4.14 and removed in 6.5.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Document;

use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\OrderDocumentCriteriaFactory;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Renderer\RendererResult;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class MyDocumentRenderer extends AbstractDocumentRenderer
{
    public const TYPE = 'my_document';

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly DocumentConfigLoader $documentConfigLoader,
        private readonly DocumentFileRendererRegistry $fileRendererRegistry,
    ) {}

    public function supports(): string
    {
        return self::TYPE;
    }

    /**
     * @param array<string, DocumentGenerateOperation> $operations keyed by order id
     */
    public function render(
        array $operations,
        Context $context,
        DocumentRendererConfig $rendererConfig
    ): RendererResult {
        $result = new RendererResult();

        $criteria = OrderDocumentCriteriaFactory::create(array_keys($operations), $rendererConfig->deepLinkCode, self::TYPE);
        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        foreach ($orders as $order) {
            $orderId = $order->getId();
            $operation = $operations[$orderId] ?? null;

            if (!$operation instanceof DocumentGenerateOperation) {
                continue;
            }

            try {
                $config = clone $this->documentConfigLoader->load(self::TYPE, $order->getSalesChannelId(), $context);
                $config->merge($operation->getConfig());

                // or a number range "document_my_document" via AbstractNumberRangeValueGenerator
                $number = $config->getDocumentNumber() ?: (string) $order->getOrderNumber();
                $config->merge(['documentNumber' => $number]);

                // snapshot the order so the document does not change with it
                $operation->setOrderVersionId($this->orderRepository->createVersion($orderId, $context, 'document'));

                $document = new RenderedDocument($number, $config->buildName(), $operation->getFileType(), $config->jsonSerialize());
                $document->setTemplate('@VendorNamePluginName/documents/my-document.html.twig');
                $document->setOrder($order);
                $document->setContext($context);
                $document->setContent($this->fileRendererRegistry->render($document)); // HTML -> PDF etc.

                $result->addSuccess($orderId, $document);
            } catch (\Throwable $e) {
                $result->addError($orderId, $e);
            }
        }

        return $result;
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        throw new DecorationPatternException(self::class);
    }
}
```

Register with the `document.renderer` tag:

```php
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use VendorName\PluginName\Document\MyDocumentRenderer;

$services->set(MyDocumentRenderer::class)
    ->args([
        service('order.repository'),
        service(DocumentConfigLoader::class),
        service(DocumentFileRendererRegistry::class),
    ])
    ->tag('document.renderer');
```

Register document type in migration:

```php
$exists = $connection->fetchOne('SELECT 1 FROM `document_type` WHERE `technical_name` = ?', ['my_document']);
if ($exists === false) {
    $typeId = Uuid::randomBytes();
    $connection->executeStatement('
        INSERT INTO `document_type` (`id`, `technical_name`, `created_at`)
        VALUES (:id, \'my_document\', NOW())
    ', ['id' => $typeId]);

    $connection->executeStatement('
        INSERT IGNORE INTO `document_type_translation` (`document_type_id`, `language_id`, `name`, `created_at`)
        SELECT :id, l.id, \'My document\', NOW()
        FROM `language` l
    ', ['id' => $typeId]);
}
```

### Document generation v2 (6.7.15+, default in 6.8)

6.7.15 ships a new document pipeline behind the `DOCUMENT_GENERATION_REWORK` feature flag. In 6.8 the flag defaults to `true` (opt-out), in 6.9 the legacy pipeline above and the flag are removed. The legacy classes carry `#[ExperimentalReplacement(version: 'v6.9.0', ...)]` and are not `@deprecated` yet; the `document_type` entities are `@deprecated tag:v6.9.0`.

- v2 never calls the legacy extension points: the `document.renderer` and `document_type.renderer` tags, legacy document events and `DocumentGenerator` decorators. Register both variants side by side during the transition
- v2 extension points are tagged services: `shopware.document_v2.type` (extend `Shopware\Core\Checkout\DocumentV2\Type\AbstractDocumentType`), `shopware.document_v2.provider` (`...\DocumentV2\Provider\AbstractDocumentDataProvider`, supplies render data), `shopware.document_v2.renderer` (`...\DocumentV2\Renderer\AbstractDocumentRenderer`, one output format such as html, pdf, ZUGFeRD XML). They are `@experimental stableVersion:v6.8.0` until 6.8
- v2 renders the same `@Framework/documents/*.html.twig` templates, so Twig overrides keep working. ZUGFeRD stops being a document type and becomes a file format of invoice, credit note and cancellation invoice
- Admin API: `POST /api/_action/order/document-v2/create|upload|preview|download-archive`, `GET /api/_action/order/document-v2/{documentId}/download/{format}`, available regardless of the flag
- Apps register document types via a `<documents>` manifest block and the `document-generation` script hook
- Company data printed on documents moves to Settings > Basic information > Company information (per sales channel); v2 falls back to the legacy document settings when empty

Concept guide: https://developer.shopware.com/docs/concepts/commerce/checkout-concept/document/

**Document lifecycle events (6.7.15+):** subscribe to `Shopware\Core\Checkout\DocumentV2\Event\DocumentGeneratedEvent` (`document.generation.completed`: `documentId`, `orderId`, `orderVersionId`, `documentType`, `documentNumber`) and `DocumentDeletedEvent` (`document.generation.deleted`). Both fire for legacy and v2 documents and are Flow Builder triggers. `document.orderId` is optional now; `DocumentEntity::getOrderId()` returns `?string` in 6.8.
