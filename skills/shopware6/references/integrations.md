# Integrations: Payment, Shipping, CMS, Rule Builder

---

## Payment Handlers

> **SW 6.7 breaking change.** `SynchronousPaymentHandlerInterface`, `AsynchronousPaymentHandlerInterface`,
> `PreparedPaymentHandlerInterface`, `RefundPaymentHandlerInterface` and `RecurringPaymentHandlerInterface`
> are deprecated and consolidated into a single `AbstractPaymentHandler`. Write new handlers against the
> abstract class. The old interfaces are only relevant when maintaining a 6.6 branch.

### AbstractPaymentHandler (SW 6.7+)

One class covers every payment flow. Which optional capabilities are active is decided by `supports()`:

| Method | Required | Called |
|---|---|---|
| `supports()` | yes | Before `refund` and `recurring` to check capability |
| `pay()` | yes | Every payment. Return `RedirectResponse` for redirect flows, `null` for direct capture |
| `validate()` | no | Before the order is persisted. Throw to abort order creation |
| `finalize()` | no | After the redirect returns. Only called when `pay()` returned a `RedirectResponse` |
| `refund()` | no | Only if `supports(PaymentHandlerType::REFUND, ...)` is true |
| `recurring()` | no | Only if `supports(PaymentHandlerType::RECURRING, ...)` is true |

Handlers receive only the `orderTransactionId`, the `Request` and a `Context`. There is no
`SalesChannelContext` any more: it may hold data that does not match the order (for example a customer
address changed after checkout). Load whatever else you need yourself.

Shopware never marks a transaction as paid on its own: call `OrderTransactionStateHandler::paid()` (or
`authorize()`, `process()`, ...) once the provider confirms. Any exception thrown from `pay()` or `finalize()`
moves the transaction to `failed`; `PaymentException::customerCanceled()` from `finalize()` moves it to
`cancelled`. Since 6.7.14 an active
default flow mails the customer on `state_enter.order_transaction.state.failed`.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Payment;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class MyPaymentHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly MyGateway $gateway,
    ) {}

    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        // Only refunds are supported, recurring is not
        return $type === PaymentHandlerType::REFUND;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $order = $this->loadOrder($transaction->getOrderTransactionId(), $context);

        $result = $this->gateway->charge(
            amount:   $order->getAmountTotal(),
            currency: $order->getCurrency()?->getIsoCode() ?? 'EUR',
        );

        if (!$result->isSuccessful()) {
            throw PaymentException::syncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'Payment declined: ' . $result->getMessage()
            );
        }

        // Direct capture: no redirect, finalize() will not be called
        $this->transactionStateHandler->paid($transaction->getOrderTransactionId(), $context);

        return null;
    }

    private function loadOrder(string $orderTransactionId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order.currency');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        return $transaction->getOrder();
    }
}
```

### Redirect flow

Return a `RedirectResponse` from `pay()`. Shopware then calls `finalize()` when the customer comes back
via the return URL. `PaymentTransactionStruct::getReturnUrl()` provides that URL.

```php
public function pay(
    Request $request,
    PaymentTransactionStruct $transaction,
    Context $context,
    ?Struct $validateStruct
): ?RedirectResponse {
    $session = $this->gateway->createSession(
        transactionId: $transaction->getOrderTransactionId(),
        returnUrl:     $transaction->getReturnUrl(),
    );

    return new RedirectResponse($session->getPaymentUrl());
}

public function finalize(
    Request $request,
    PaymentTransactionStruct $transaction,
    Context $context
): void {
    $transactionId = $transaction->getOrderTransactionId();
    $status = $this->gateway->verifyPayment($request->query->getString('transaction_id'));

    if ($status === 'canceled') {
        throw PaymentException::customerCanceled($transactionId, 'Canceled at the provider');
    }

    if ($status !== 'paid') {
        throw PaymentException::asyncFinalizeInterrupted($transactionId, 'Payment not completed. Status: ' . $status);
    }

    $this->transactionStateHandler->paid($transactionId, $context);
}
```

`validate()` runs before the order exists and can carry data forward into `pay()` via the returned
`Struct`, which arrives as `$validateStruct`:

```php
public function validate(Cart $cart, RequestDataBag $dataBag, SalesChannelContext $context): ?Struct
{
    $token = $dataBag->get('myGatewayToken');

    if (!$token) {
        throw PaymentException::validatePreparedPaymentInterrupted('Missing gateway token');
    }

    return new ArrayStruct(['token' => $token]);
}
```

### Register Payment Handler

A single tag for every handler type. The `.sync` and `.async` variants are gone. Services go in
`src/Resources/config/services.php` (XML service config is deprecated since 6.7.14 and removed in 6.8; PHP config
works on 6.6 and 6.7 too). With autoconfiguration the tag is added for every `AbstractPaymentHandler`.

```php
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use VendorName\PluginName\Gateway\MyGateway;
use VendorName\PluginName\Payment\MyPaymentHandler;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

$services->set(MyPaymentHandler::class)
    ->args([
        service('order_transaction.repository'),
        service(OrderTransactionStateHandler::class),
        service(MyGateway::class),
    ])
    ->tag('shopware.payment.method');
```

### Install Payment Method in Plugin

`technicalName` is **required** since SW 6.7 (the `technical_name` column is non-nullable for both
`payment_method` and `shipping_method`). Creating a method without it fails.

```php
public function install(InstallContext $installContext): void
{
    $this->addPaymentMethod($installContext->getContext());
}

public function activate(ActivateContext $activateContext): void
{
    $this->setPaymentMethodActive(true, $activateContext->getContext());
}

public function deactivate(DeactivateContext $deactivateContext): void
{
    $this->setPaymentMethodActive(false, $deactivateContext->getContext());
}

private function addPaymentMethod(Context $context): void
{
    $paymentMethodRepository = $this->container->get('payment_method.repository');

    $paymentMethodExists = $this->getPaymentMethodId($context) !== null;
    if ($paymentMethodExists) {
        return;
    }

    // Links the method to the plugin; plugin payment methods cannot be deleted via the API
    $pluginId = $this->container->get(PluginIdProvider::class)
        ->getPluginIdByBaseClass(static::class, $context);

    $paymentMethodRepository->create([
        [
            'handlerIdentifier' => MyPaymentHandler::class,
            'technicalName'     => 'payment_myplugin_gateway', // required since 6.7
            'pluginId'          => $pluginId,
            'name'              => 'My Payment',
            'description'       => 'Pay via My Gateway',
            'translations'      => [
                'de-DE' => ['name' => 'Meine Zahlung'],
                'en-GB' => ['name' => 'My Payment'],
            ],
            'afterOrderEnabled' => true,
        ],
    ], $context);
}

private function getPaymentMethodId(Context $context): ?string
{
    $paymentCriteria = new Criteria();
    $paymentCriteria->addFilter(
        new EqualsFilter('handlerIdentifier', MyPaymentHandler::class)
    );

    return $this->container->get('payment_method.repository')
        ->searchIds($paymentCriteria, $context)
        ->firstId();
}

private function setPaymentMethodActive(bool $active, Context $context): void
{
    $id = $this->getPaymentMethodId($context);
    if ($id === null) return;

    $this->container->get('payment_method.repository')->update([
        ['id' => $id, 'active' => $active],
    ], $context);
}
```

### Legacy handlers (SW 6.6 and older)

`SynchronousPaymentHandlerInterface::pay(SyncPaymentTransactionStruct, RequestDataBag, SalesChannelContext)`
and `AsynchronousPaymentHandlerInterface::pay(...): RedirectResponse` plus `finalize(...)`, registered with
`shopware.payment.method.sync` / `.async`. Only use these when the plugin must run on 6.6.

---

## Shipping Costs

There is **no** `shopware.shipping_method.calculator` tag in Shopware 6. Shipping cost logic has three
levels, pick the lowest one that solves the problem:

| Level | Mechanism | Use when |
|---|---|---|
| Config | `shipping_method_price` records (Administration: price matrix) | Costs depend on weight, price, quantity or a rule. No code needed |
| Rules | Rule Builder condition + price matrix | The condition is business logic the merchant should control |
| Code | Custom `CartProcessorInterface` running after `DeliveryProcessor` | Costs come from an external carrier API or logic the matrix cannot express |

### Custom cart processor for shipping costs

`DeliveryProcessor` is registered with `priority: -5000`. Register below that so deliveries already exist
and carry calculated costs you can overwrite.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollection;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class WeightBasedShippingProcessor implements CartProcessorInterface
{
    private const TECHNICAL_NAME = 'shipping_myplugin_weight';

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getDeliveries() as $delivery) {
            if ($delivery->getShippingMethod()->getTechnicalName() !== self::TECHNICAL_NAME) {
                continue;
            }

            $weight = 0.0;
            foreach ($delivery->getPositions() as $position) {
                $itemWeight = $position->getLineItem()->getDeliveryInformation()?->getWeight() ?? 0.0;
                $weight += $itemWeight * $position->getQuantity();
            }

            $price = match (true) {
                $weight <= 1.0 => 4.99,
                $weight <= 5.0 => 8.99,
                default        => 14.99,
            };

            $delivery->setShippingCosts(new CalculatedPrice(
                $price,
                $price,
                new CalculatedTaxCollection(),
                new TaxRuleCollection()
            ));
        }
    }
}
```

```php
$services->set(WeightBasedShippingProcessor::class)
    ->tag('shopware.cart.processor', ['priority' => -6000]);
```

Match on `technicalName`, not on `handlerIdentifier`: shipping methods have no handler class, and
`technicalName` is required for them since SW 6.7.

### Install a shipping method

```php
$this->container->get('shipping_method.repository')->create([
    [
        'name'                 => 'Weight based',
        'technicalName'        => 'shipping_myplugin_weight', // required since 6.7
        'active'               => false,
        'deliveryTimeId'       => $deliveryTimeId,
        'availabilityRuleId'   => $ruleId,
        'prices'               => [
            [
                'calculation'   => DeliveryCalculator::CALCULATION_BY_LINE_ITEM_COUNT,
                'quantityStart' => 1,
                'currencyPrice' => [
                    ['currencyId' => Defaults::CURRENCY, 'gross' => 4.99, 'net' => 4.19, 'linked' => false],
                ],
            ],
        ],
    ],
], $context);
```

Since 6.7.14 an **active** shipping method must keep at least one usable price row: activating a method without
one, or removing its last usable price, fails with 400 (`active_shipping_method_without_price`). Replace a matrix
in a single sync request, and the Store API no longer offers price-less methods with `onlyAvailable=1`.

For taxes on the shipping cost, resolve the tax rules from the delivery positions instead of passing an
empty `TaxRuleCollection`, otherwise the shipping cost is treated as tax free.

---

## CMS Elements (Page Builder)

CMS Elements add custom blocks to the Experience / Shopping Worlds page builder.

### PHP Definition

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Content\Cms;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;

class MyBannerCmsElementResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'my-banner'; // matches the element name in admin JS
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(
        CmsSlotEntity $slot,
        ResolverContext $resolverContext,
        ElementDataCollection $result
    ): void {
        $config = $slot->getFieldConfig();

        // setData() takes a Struct, not an array
        $slot->setData(new ArrayStruct([
            'title'    => $config->get('title')?->getStringValue() ?? '',
            'subtitle' => $config->get('subtitle')?->getStringValue() ?? '',
        ]));
    }
}
```

Register (the tag is `shopware.cms.data_resolver`, added automatically with autoconfiguration):

```php
$services->set(MyBannerCmsElementResolver::class)
    ->tag('shopware.cms.data_resolver');
```

### Admin Vue Component

```js
Shopware.Component.register('cms-element-my-banner', {
    template: `
        <div class="cms-element-my-banner">
            <h2>{{ element.config.title.value }}</h2>
            <p>{{ element.config.subtitle.value }}</p>
        </div>
    `,

    // provides the element prop, cmsService and initElementConfig()
    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig();
    },
});

// Register cms-element-config-my-banner and cms-element-my-banner-preview the same way
Shopware.Service('cmsService').registerCmsElement({
    name: 'my-banner',
    label: 'My Banner Element',
    component: 'cms-element-my-banner',
    configComponent: 'cms-element-config-my-banner',
    previewComponent: 'cms-element-my-banner-preview',
    defaultConfig: {
        title: { source: 'static', value: 'Banner Title' },
        subtitle: { source: 'static', value: '' },
    },
});
```

### Storefront Twig

```twig
{# src/Resources/views/storefront/element/cms-element-my-banner.html.twig #}
{% block element_my_banner %}
<div class="my-banner">
    <h2>{{ element.data.title }}</h2>
    {% if element.data.subtitle %}
        <p>{{ element.data.subtitle }}</p>
    {% endif %}
</div>
{% endblock %}
```

---

## Rule Builder: Custom Conditions

Custom rules let you add conditions to the Rule Builder for pricing, shipping, promotions, and CMS visibility.

### Rule Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Rule;

use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

class CustomerTierRule extends Rule
{
    // Rule::getName() returns RULE_NAME, no override needed
    final public const RULE_NAME = 'my_plugin_customer_tier';

    public function __construct(
        protected string $operator = self::OPERATOR_EQ,
        protected string $tier = 'gold',
    ) {
        parent::__construct();
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CheckoutRuleScope) {
            return false;
        }

        $customer = $scope->getSalesChannelContext()->getCustomer();
        if ($customer === null) {
            return false;
        }

        $customerTier = $customer->getCustomFields()['my_plugin_tier'] ?? 'none';

        return RuleComparison::string($customerTier, $this->tier, $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'tier'     => RuleConstraints::string(),
            'operator' => RuleConstraints::stringOperators(false),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_STRING)
            ->selectField('tier', ['none', 'bronze', 'silver', 'gold', 'platinum']);
    }
}
```

Register:

```php
$services->set(CustomerTierRule::class)
    ->tag('shopware.rule.definition');
```

### Admin Registration for Rule UI

With `getConfig()` the generic `sw-condition-generic` component renders operator and fields, no custom Vue
component needed. Register the condition type:

```js
Shopware.Application.addServiceProviderDecorator('ruleConditionDataProviderService', (ruleConditionService) => {
    ruleConditionService.addCondition('my_plugin_customer_tier', {
        component: 'sw-condition-generic',
        label: 'my-plugin.rule.customerTier',
        scopes: ['checkout'],
        group: 'customer',
    });

    return ruleConditionService;
});
```

---

## Import / Export Profiles

Add a custom import/export profile for your entity:

```php
$importExportProfileRepository = $this->container->get('import_export_profile.repository');

$importExportProfileRepository->create([
    [
        'technicalName' => 'my_entity_export', // required, the profile identifier
        'label'         => 'My Entity',        // translated label, removed in 6.8
        'systemDefault' => false,
        'sourceEntity'  => 'my_entity',
        'fileType'      => 'text/csv',
        'delimiter'     => ';',
        'enclosure'     => '"',
        'mapping'       => [
            ['key' => 'id',   'mappedKey' => 'id'],
            ['key' => 'name', 'mappedKey' => 'Name'],
        ],
        'translations' => [
            'de-DE' => ['label' => 'Meine Entity'],
            'en-GB' => ['label' => 'My Entity'],
        ],
    ],
], Context::createDefaultContext());
```

---

## Business Events (Flow Builder)

Register a custom event that appears in the Flow Builder trigger list:

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\OrderAware;
use Symfony\Contracts\EventDispatcher\Event;

class MyPluginOrderSyncedEvent extends Event implements FlowEventAware, OrderAware
{
    public const EVENT_NAME = 'my_plugin.order.synced';

    public function __construct(
        private readonly string $orderId,
        private readonly string $externalId,
        private readonly Context $context,
    ) {}

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('orderId',    new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('externalId', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    public function getName(): string { return self::EVENT_NAME; }
    public function getContext(): Context { return $this->context; }
    public function getOrderId(): string { return $this->orderId; }
    public function getExternalId(): string { return $this->externalId; }
}
```

`OrderAware` (and the other `*Aware` interfaces) makes the order available to flow actions such as mails and tags.
Register the class in your plugin base class, otherwise it does not appear as a trigger:

```php
protected function getActionEventClasses(): array
{
    return [MyPluginOrderSyncedEvent::class];
}
```

Dispatch it:

```php
$this->eventDispatcher->dispatch(new MyPluginOrderSyncedEvent($orderId, $externalId, $context));
```

Flow events are also delivered to app webhooks. Keep secrets out of webhook payloads with
`->add('token', new ScalarValueType(ScalarValueType::TYPE_STRING), [EventDataCollection::HIDDEN_FROM_WEBHOOK => true])`
(SW 6.7.14.1+), or mark the whole event class `#[\Shopware\Core\Framework\Webhook\NotHookable]`. Both stay
available in Flow Builder.

> **SW 6.8.** Flows triggered during a request, message or command are buffered and run after the main
> unit of work, so `dispatch()` returns before flow actions (mails, webhooks, custom actions) have run. Logic
> that must be part of the business transaction belongs in a synchronous event subscriber.
