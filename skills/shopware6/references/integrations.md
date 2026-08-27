# Integrations — Payment, Shipping, CMS, Rule Builder

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

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Payment;

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
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'Payment declined: ' . $result->getMessage()
            );
        }

        // Direct capture: no redirect, finalize() will not be called
        return null;
    }

    private function loadOrder(string $orderTransactionId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order.currency');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

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
    $status = $this->gateway->verifyPayment($request->query->get('transaction_id'));

    if ($status !== 'paid') {
        throw PaymentException::customerCanceled(
            $transaction->getOrderTransactionId(),
            'Payment not completed. Status: ' . $status
        );
    }
    // No exception thrown means Shopware transitions the transaction to paid
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

A single tag for every handler type. The `.sync` and `.async` variants are gone.

```xml
<service id="VendorName\PluginName\Payment\MyPaymentHandler">
    <argument type="service" id="order_transaction.repository"/>
    <argument type="service" id="VendorName\PluginName\Gateway\MyGateway"/>
    <tag name="shopware.payment.method"/>
</service>
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

    $paymentMethodRepository->create([
        [
            'handlerIdentifier' => MyPaymentHandler::class,
            'technicalName'     => 'payment_myplugin_gateway', // required since 6.7
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

## Shipping Method Handler

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Shipping;

use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\Cart\ShippingMethodPriceCalculator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyShippingCalculator
{
    public function calculate(
        DeliveryCollection $deliveries,
        SalesChannelContext $context
    ): void {
        foreach ($deliveries as $delivery) {
            $shippingMethod = $delivery->getShippingMethod();

            if ($shippingMethod->getHandlerIdentifier() !== static::class) {
                continue;
            }

            $weight = array_sum(array_map(
                fn($pos) => ($pos->getLineItem()->getDeliveryInformation()?->getWeight() ?? 0.0) * $pos->getQuantity(),
                $delivery->getPositions()->getElements()
            ));

            $price = match(true) {
                $weight <= 1.0  => 4.99,
                $weight <= 5.0  => 8.99,
                default         => 14.99,
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

Register in `services.xml`:

```xml
<service id="VendorName\PluginName\Shipping\MyShippingCalculator">
    <tag name="shopware.shipping_method.calculator"/>
</service>
```

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

class MyBannerCmsElementResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'my-banner'; // matches component name in admin JS
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
        $config = $slot->getConfig();

        $slot->setData([
            'title'    => $config['title']['value'] ?? '',
            'subtitle' => $config['subtitle']['value'] ?? '',
        ]);
    }
}
```

Register:

```xml
<service id="VendorName\PluginName\Content\Cms\MyBannerCmsElementResolver">
    <tag name="shopware.cms.element"/>
</service>
```

### Admin Vue Component

```js
Shopware.Component.register('cms-element-my-banner', {
    template: `
        <div class="cms-element-my-banner">
            <cms-element-config-form-renderer :config="element.config" />
        </div>
    `,

    props: ['element'],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('my-banner');
        },
    },
});

Shopware.Service('cmsService').registerCmsElement({
    name: 'my-banner',
    label: 'My Banner Element',
    component: 'cms-element-my-banner',
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

## Rule Builder — Custom Conditions

Custom rules let you add conditions to the Rule Builder for pricing, shipping, promotions, and CMS visibility.

### Rule Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Rule;

use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;
use Shopware\Core\Checkout\CheckoutRuleScope;

class CustomerTierRule extends Rule
{
    public const RULE_NAME = 'my_plugin_customer_tier';

    protected string $operator = Rule::OPERATOR_EQ;
    protected string $tier     = 'gold';

    public function getName(): string
    {
        return self::RULE_NAME;
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

```xml
<service id="VendorName\PluginName\Core\Rule\CustomerTierRule">
    <tag name="shopware.rule.definition"/>
</service>
```

### Admin Component for Rule UI

```js
Shopware.Component.register('sw-condition-my-plugin-customer-tier', {
    template: `
        <sw-condition-operator-select
            :operators="conditionDataProviderService.getOperatorSet('string')"
            :condition="condition"
        />
        <sw-single-select
            v-model="tier"
            :options="tierOptions"
            :label="$tc('my-plugin.rule.customerTier')"
        />
    `,

    computed: {
        tier: {
            get() { return this.condition.value.tier; },
            set(value) { this.condition.value = { ...this.condition.value, tier: value }; },
        },
        tierOptions() {
            return ['none', 'bronze', 'silver', 'gold', 'platinum'].map(v => ({
                value: v, label: this.$tc(`my-plugin.rule.tier.${v}`),
            }));
        },
    },
});
```

---

## Import / Export Profiles

Add a custom import/export profile for your entity:

```php
$importExportProfileRepository = $this->container->get('import_export_profile.repository');

$importExportProfileRepository->create([
    [
        'name'          => 'My Entity Export',
        'label'         => 'My Entity',
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

use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Context;
use Symfony\Contracts\EventDispatcher\Event;

class MyPluginOrderSyncedEvent extends Event implements FlowEventAware
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

Dispatch it:

```php
$this->eventDispatcher->dispatch(new MyPluginOrderSyncedEvent($orderId, $externalId, $context));
```

The event then appears in the Flow Builder's trigger dropdown automatically.
