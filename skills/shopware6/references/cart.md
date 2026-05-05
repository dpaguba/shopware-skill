# Cart — Processors, Collectors, Validators, Discounts, Price Manipulation

The Shopware cart pipeline runs on every cart calculation. It follows this order:
1. **Collectors** gather external data needed for calculation
2. **Processors** apply changes to the cart
3. **Validators** check the final cart for errors before checkout

---

## Cart Collector

Collectors implement `CartDataCollectorInterface`. Fetch database or API data here — never in processors.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyCartCollector implements CartDataCollectorInterface
{
    public function __construct(
        private readonly EntityRepository $myEntityRepository,
    ) {}

    public function collect(
        CartDataCollection $data,
        Cart $original,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $productIds = $original->getLineItems()
            ->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE)
            ->getReferenceIds();

        if (empty($productIds)) {
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productId', $productIds));

        $myData = $this->myEntityRepository->search($criteria, $context->getContext());

        foreach ($myData as $item) {
            $data->set('my_plugin_data_' . $item->getProductId(), $item);
        }
    }
}
```

Register with `shopware.cart.collector` tag:

```xml
<service id="VendorName\PluginName\Checkout\Cart\MyCartCollector">
    <argument type="service" id="my_entity.repository"/>
    <tag name="shopware.cart.collector"/>
</service>
```

---

## Cart Processor

Processors implement `CartProcessorInterface`. Only modify `$toCalculate` — never `$original`.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyCartProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
    ) {}

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $myData = $data->get('my_plugin_data_' . $lineItem->getReferencedId());

            if ($myData === null) {
                continue;
            }

            $lineItem->setPayload(array_merge(
                $lineItem->getPayload(),
                ['myPluginFlag' => $myData->getFlag()]
            ));
        }
    }
}
```

Register with `shopware.cart.processor` tag and set a `priority`:

```xml
<service id="VendorName\PluginName\Checkout\Cart\MyCartProcessor">
    <argument type="service" id="Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator"/>
    <tag name="shopware.cart.processor" priority="1000"/>
</service>
```

Higher priority = runs earlier. Default product processor priority is around 4900.

---

## Change Item Price

Use `QuantityPriceCalculator` to recalculate a price with a modified definition:

```php
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;

class PriceAdjustmentProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
    ) {}

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getLineItems() as $lineItem) {
            $priceDefinition = $lineItem->getPriceDefinition();

            if (!$priceDefinition instanceof QuantityPriceDefinition) {
                continue;
            }

            $discountedPrice = $priceDefinition->getPrice() * 0.9;

            $newDefinition = new QuantityPriceDefinition(
                $discountedPrice,
                $priceDefinition->getTaxRules(),
                $priceDefinition->getQuantity()
            );

            $lineItem->setPrice($this->calculator->calculate($newDefinition, $context));
        }
    }
}
```

---

## Add Discount Line Item

### Percentage Discount (recommended)

```php
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;

class DiscountProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly PercentagePriceCalculator $calculator,
    ) {}

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        if ($toCalculate->has('my-plugin-discount')) {
            return;
        }

        $discountLineItem = new LineItem('my-plugin-discount', 'my_discount', null, 1);
        $discountLineItem->setLabel('My 10% Discount');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(false);

        // -10.0 = 10% off (negative percentage)
        $definition = new PercentagePriceDefinition(-10.0);
        $discountLineItem->setPriceDefinition($definition);

        $prices = $toCalculate->getLineItems()->filterGoods()->getPrices();
        $discountLineItem->setPrice(
            $this->calculator->calculate(-10.0, $prices, $context)
        );

        $toCalculate->add($discountLineItem);
    }
}
```

Register with priority **below** 4900 so it runs **after** the product processor (which sets product prices at ~4900). Lower number = runs later:

```xml
<service id="VendorName\PluginName\Checkout\Cart\DiscountProcessor">
    <argument type="service" id="Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator"/>
    <tag name="shopware.cart.processor" priority="4500"/>
</service>
```

### Absolute Discount

```php
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;

$definition = new AbsolutePriceDefinition(-5.00); // -5€ off
$discountLineItem->setPriceDefinition($definition);

$prices = $toCalculate->getLineItems()->filterGoods()->getPrices();
$discountLineItem->setPrice(
    $this->calculator->calculate(-5.00, $prices, $context)
);
```

---

## Cart Validator

Validators run after processors and block checkout if errors are added.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyCartValidator implements CartValidatorInterface
{
    public function validate(
        Cart $cart,
        ErrorCollection $errors,
        SalesChannelContext $context
    ): void {
        foreach ($cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            if ($this->isBlocked($lineItem, $context)) {
                $errors->add(new MyCartBlockedError($lineItem->getId()));
            }
        }
    }

    private function isBlocked(LineItem $lineItem, SalesChannelContext $context): bool
    {
        return false;
    }
}
```

Register:

```xml
<service id="VendorName\PluginName\Checkout\Cart\MyCartValidator">
    <tag name="shopware.cart.validator"/>
</service>
```

### Custom Error Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

class MyCartBlockedError extends Error
{
    private const KEY = 'my-plugin-item-blocked';

    public function __construct(
        private readonly string $lineItemId,
    ) {}

    public function getId(): string
    {
        return $this->lineItemId;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;  // LEVEL_WARNING = soft warning, LEVEL_ERROR = blocks order
    }

    public function blockOrder(): bool
    {
        return true;  // false = warning only, order still possible
    }

    public function getParameters(): array
    {
        return ['lineItemId' => $this->lineItemId];
    }
}
```

Add snippet for the error message in `snippet/en_GB/my-plugin.en-GB.json`:

```json
{
    "checkout": {
        "my-plugin-item-blocked": "This item cannot be ordered: {{ lineItemId }}"
    }
}
```

---

## Tax Provider

Register a custom tax provider to replace the default tax calculation for specific line items:

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Tax;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\TaxProvider\AbstractTaxProvider;
use Shopware\Core\Checkout\Cart\TaxProvider\Struct\TaxProviderResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyTaxProvider extends AbstractTaxProvider
{
    public function provide(
        Cart $cart,
        SalesChannelContext $context
    ): TaxProviderResult {
        $taxMap = [];

        foreach ($cart->getLineItems() as $lineItem) {
            $taxRate = $this->lookupTaxRate($lineItem, $context);

            $taxMap[$lineItem->getId()] = new TaxRuleCollection([
                new TaxRule($taxRate, 100.0),
            ]);
        }

        return new TaxProviderResult($taxMap);
    }
}
```

Register as a tax provider and link it to a tax provider entity via the Admin UI or migration.

---

## Cart Events

Listen to cart lifecycle events:

```php
use Shopware\Core\Checkout\Cart\Event\CartSavedEvent;
use Shopware\Core\Checkout\Cart\Event\CartDeletedEvent;
use Shopware\Core\Checkout\Cart\Order\Event\CheckoutOrderPlacedEvent;

public static function getSubscribedEvents(): array
{
    return [
        CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        CartSavedEvent::class           => 'onCartSaved',
    ];
}

public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
{
    $order = $event->getOrder();
    $context = $event->getSalesChannelContext();
}
```
