# Cart: Processors, Collectors, Validators, Discounts, Price Manipulation

The Shopware cart pipeline runs on every cart calculation. It follows this order:
1. **Collectors** gather external data needed for calculation
2. **Processors** apply changes to the cart
3. **Validators** check the final cart for errors before checkout

Since 6.7.15, `GET|POST /store-api/checkout/cart` returns the cart already calculated during context resolution, so processors run and `CartLoadedEvent` fires once per request instead of twice.

---

## Cart Collector

Collectors implement `CartDataCollectorInterface`. Fetch database or API data here, never in processors.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
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

        foreach ($myData->getEntities() as $item) {
            $data->set('my_plugin_data_' . $item->getProductId(), $item);
        }
    }
}
```

Register with the `shopware.cart.collector` tag in `src/Resources/config/services.php`:

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\PluginName\Checkout\Cart\MyCartCollector;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(MyCartCollector::class)
        ->args([service('my_entity.repository')])
        ->tag('shopware.cart.collector');
};
```

The snippets below are `$services->set(...)` calls inside the same closure.

---

## Cart Processor

Processors implement `CartProcessorInterface`. Only modify `$toCalculate`, never `$original`.

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

Register with the `shopware.cart.processor` tag and set a `priority`:

```php
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use VendorName\PluginName\Checkout\Cart\MyCartProcessor;

$services->set(MyCartProcessor::class)
    ->args([service(QuantityPriceCalculator::class)])
    ->tag('shopware.cart.processor', ['priority' => 1000]);
```

Higher priority = runs earlier. Core priorities: product processor and collector `5000`, promotion collector and processor `4900`, custom line items `4000`, container items `3800`, discount items `3700`, credit items `0`, delivery `-5000`, promotion delivery `-5100`.

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

To change product prices before promotions see them, register between the product (`5000`) and promotion (`4900`) processors, e.g. priority `4950`.

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

Register with priority **below** 5000 so it runs **after** the product processor, which sets product prices at 5000. Lower number = runs later:

```php
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use VendorName\PluginName\Checkout\Cart\DiscountProcessor;

$services->set(DiscountProcessor::class)
    ->args([service(PercentagePriceCalculator::class)])
    ->tag('shopware.cart.processor', ['priority' => 4500]);
```

From 6.8 (`v6.8.0.0` flag in 6.7), `PercentagePriceCalculator` derives the discount's taxes by scaling the calculated taxes of the referenced items. Expect cent-level differences with mixed tax rates.

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
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
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

```php
use VendorName\PluginName\Checkout\Cart\MyCartValidator;

$services->set(MyCartValidator::class)
    ->tag('shopware.cart.validator');
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
        protected readonly string $lineItemId,
    ) {
        parent::__construct(\sprintf('Line item "%s" cannot be ordered.', $lineItemId));
    }

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

Add snippet for the error message in `snippet/en_GB/my-plugin.en-GB.json`. The Storefront translates `checkout.<messageKey>` and passes each parameter as `%name%`:

```json
{
    "checkout": {
        "my-plugin-item-blocked": "This item cannot be ordered: %lineItemId%"
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
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\TaxProvider\AbstractTaxProvider;
use Shopware\Core\Checkout\Cart\TaxProvider\Struct\TaxProviderResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class MyTaxProvider extends AbstractTaxProvider
{
    public function provide(
        Cart $cart,
        SalesChannelContext $context
    ): TaxProviderResult {
        $lineItemTaxes = [];

        foreach ($cart->getLineItems() as $lineItem) {
            $taxRate = $this->lookupTaxRate($lineItem, $context);
            $price = $lineItem->getPrice()->getTotalPrice();

            // keyed by uniqueIdentifier, which also resolves nested line items
            $lineItemTaxes[$lineItem->getUniqueIdentifier()] = new CalculatedTaxCollection([
                new CalculatedTax($price * $taxRate / 100, $taxRate, $price),
            ]);
        }

        return new TaxProviderResult($lineItemTaxes);
    }
}
```

Register with the `shopware.tax.provider` tag, then create a `tax_provider` entity (migration or Admin) whose `identifier` is the provider's FQCN, with `priority`, `active` and an optional `availabilityRuleId`.

---

## Cart Events

Listen to cart lifecycle events:

```php
use Shopware\Core\Checkout\Cart\Event\CartSavedEvent;
use Shopware\Core\Checkout\Cart\Event\CartDeletedEvent;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;

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

Read order data from `$event->getOrder()`. From 6.8 (`v6.8.0.0` flag in 6.7) the cart is deleted before `CheckoutOrderPlacedCriteriaEvent` and `CheckoutOrderPlacedEvent` are dispatched, so it cannot be reloaded by its token. Add needed associations via `CheckoutOrderPlacedCriteriaEvent`.
