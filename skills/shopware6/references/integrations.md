# Integrations — Payment, Shipping, CMS, Rule Builder

---

## Payment Handlers

### When to use which interface

| Interface | Use when |
|---|---|
| `SynchronousPaymentHandlerInterface` | Payment result is immediate (credit card, prepayment confirmation) |
| `AsynchronousPaymentHandlerInterface` | Customer is redirected to external provider (PayPal, Stripe redirect) |
| `RecurringPaymentHandlerInterface` | Subscription billing |

### Synchronous Handler

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Payment;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

class MyPaymentHandler implements SynchronousPaymentHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function pay(
        SyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $order       = $transaction->getOrder();
        $totalAmount = $order->getAmountTotal();
        $currency    = $salesChannelContext->getCurrency()->getIsoCode();

        try {
            $result = $this->gateway->charge($totalAmount, $currency);

            if (!$result->isSuccessful()) {
                throw PaymentException::syncProcessInterrupted(
                    $transaction->getOrderTransaction()->getId(),
                    'Payment declined: ' . $result->getMessage()
                );
            }

            $this->logger->info('Payment successful', [
                'orderId'    => $order->getId(),
                'amount'     => $totalAmount,
                'externalId' => $result->getTransactionId(),
            ]);
        } catch (\Exception $e) {
            throw PaymentException::syncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                $e->getMessage()
            );
        }
    }
}
```

### Asynchronous Handler

```php
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class MyRedirectPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $returnUrl = $transaction->getReturnUrl(); // Shopware's finalize URL

        $paymentUrl = $this->gateway->createSession(
            amount:    $transaction->getOrder()->getAmountTotal(),
            currency:  $salesChannelContext->getCurrency()->getIsoCode(),
            returnUrl: $returnUrl,
        );

        return new RedirectResponse($paymentUrl);
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $request->query->get('transaction_id');

        $status = $this->gateway->verifyPayment($transactionId);

        if ($status !== 'paid') {
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                'Payment not completed. Status: ' . $status
            );
        }
        // If no exception is thrown, Shopware marks the order as paid
    }
}
```

### Register Payment Handler

```xml
<service id="VendorName\PluginName\Payment\MyPaymentHandler">
    <argument type="service" id="logger"/>
    <tag name="shopware.payment.method.sync"/>
    <!-- OR: <tag name="shopware.payment.method.async"/> -->
</service>
```

### Install Payment Method in Plugin

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
