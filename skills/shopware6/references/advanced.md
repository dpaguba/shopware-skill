# Advanced: PHP Attributes Entities, Flysystem, Redis, Rate Limiter, Data Indexer, Field Inheritance, In-App Purchases

---

## Entities via PHP Attributes (SW 6.6.3+)

A modern alternative to `EntityDefinition` classes. Less boilerplate, same DAL features.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Content\MyEntity;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\CustomFields;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ManyToOne;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\OneToMany;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ReferenceVersion;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Translations;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

#[EntityAttribute('my_entity')]
class MyEntity extends Entity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID)]
    public string $id;

    #[Field(type: FieldType::STRING)]
    #[Required]
    public string $name;

    #[Field(type: FieldType::TEXT)]
    public ?string $description = null;

    #[Field(type: FieldType::BOOL)]
    public bool $active = true;

    #[Field(type: FieldType::INT)]
    public int $priority = 0;

    #[Field(type: FieldType::FLOAT)]
    public ?float $score = null;

    #[Field(type: FieldType::JSON)]
    public ?array $config = null;

    // api: true exposes the field to Admin API and Store API; without `api` it is hidden from both.
    // Admin API only:
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $externalId = null;

    // Translated field, stored in the auto-generated `my_entity_translation` table
    #[Field(type: FieldType::STRING, translated: true)]
    public ?string $label = null;

    /**
     * @var array<string, ArrayEntity>|null
     */
    #[Translations]
    public ?array $translations = null;

    #[ForeignKey(entity: 'product')]
    public ?string $productId = null;

    // `product` is versioned: the reference needs its version column too
    #[ReferenceVersion(entity: 'product')]
    public ?string $productVersionId = null;

    #[ManyToOne(entity: 'product', column: 'product_id')]
    public ?ProductEntity $product = null;

    /**
     * @var array<string, MyOtherEntity>|null
     */
    #[OneToMany(entity: 'my_other_entity', ref: 'my_entity_id')]
    public ?array $otherEntities = null;

    // Custom fields (JSON blob)
    #[CustomFields]
    public ?array $customFields = null;
}
```

A non-nullable property type adds the `Required` flag, so `$name`, `$active` and `$priority` above must be
present on every create; `#[Required]` is only needed on nullable properties. The attribute classes are
`final` since 6.7 (except the base `Field`).

### Registration in services.php

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VendorName\PluginName\Core\Content\MyEntity\MyEntity;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(MyEntity::class)
        ->tag('shopware.entity');
};
```

Shopware auto-registers `my_entity.definition` and `my_entity.repository`.

### Inject the auto-generated repository

```php
$services->set(MyService::class)
    ->args([service('my_entity.repository')]);
```

### Migration still required

```php
public function update(Connection $connection): void
{
    $connection->executeStatement('
        CREATE TABLE IF NOT EXISTS `my_entity` (
            `id`          BINARY(16) NOT NULL,
            `name`        VARCHAR(255) NOT NULL,
            `description` LONGTEXT NULL,
            `active`      TINYINT(1) NOT NULL DEFAULT 1,
            `priority`    INT NOT NULL DEFAULT 0,
            `score`       FLOAT NULL,
            `config`      JSON NULL,
            `external_id` VARCHAR(255) NULL,
            `product_id`  BINARY(16) NULL,
            `product_version_id` BINARY(16) NULL,
            `custom_fields` JSON NULL,
            `created_at`  DATETIME(3) NOT NULL,
            `updated_at`  DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk.my_entity.product_id` FOREIGN KEY (`product_id`, `product_version_id`)
                REFERENCES `product` (`id`, `version_id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');

    // Translated fields (`label`) go into <entity>_translation
    $connection->executeStatement('
        CREATE TABLE IF NOT EXISTS `my_entity_translation` (
            `my_entity_id` BINARY(16) NOT NULL,
            `language_id`  BINARY(16) NOT NULL,
            `label`        VARCHAR(255) NULL,
            `created_at`   DATETIME(3) NOT NULL,
            `updated_at`   DATETIME(3) NULL,
            PRIMARY KEY (`my_entity_id`, `language_id`),
            CONSTRAINT `fk.my_entity_translation.my_entity_id` FOREIGN KEY (`my_entity_id`)
                REFERENCES `my_entity` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk.my_entity_translation.language_id` FOREIGN KEY (`language_id`)
                REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');
}
```

---

## Flysystem: File Storage

Shopware exposes plugin-specific public and private filesystems via DI.

```php
use League\Flysystem\FilesystemOperator;

class MyFileService
{
    public function __construct(
        // Service IDs: <snake_case of the plugin class short name>.filesystem.public / .private
        private readonly FilesystemOperator $publicFilesystem,
        private readonly FilesystemOperator $privateFilesystem,
    ) {}

    public function storePrivateFile(string $filename, string $content): void
    {
        $this->privateFilesystem->write($filename, $content);
    }

    public function readPrivateFile(string $filename): string
    {
        return $this->privateFilesystem->read($filename);
    }

    public function listPrivateFiles(string $directory = '/'): iterable
    {
        return $this->privateFilesystem->listContents($directory, false);
    }

    public function deleteFile(string $filename): void
    {
        if ($this->privateFilesystem->fileExists($filename)) {
            $this->privateFilesystem->delete($filename);
        }
    }

    public function moveToPublic(string $privatePath, string $publicPath): void
    {
        $content = $this->privateFilesystem->read($privatePath);
        $this->publicFilesystem->write($publicPath, $content);
        $this->privateFilesystem->delete($privatePath);
    }
}
```

Register in services.php with the auto-generated filesystem service IDs (plugin class
`VendorNamePluginName` becomes `vendor_name_plugin_name`; files land under `plugins/vendor_name_plugin_name/`):

```php
$services->set(MyFileService::class)
    ->args([
        service('vendor_name_plugin_name.filesystem.public'),
        service('vendor_name_plugin_name.filesystem.private'),
    ]);
```

With autowiring, the aliases `FilesystemOperator $vendorNamePluginNamePublicFilesystem` and
`$vendorNamePluginNamePrivateFilesystem` resolve to the same services.

### Common Flysystem operations

```php
$fs->write('path/to/file.txt', 'content');
$fs->writeStream('path/to/file.pdf', $stream);

$content = $fs->read('path/to/file.txt');
$stream  = $fs->readStream('path/to/file.pdf');

$exists = $fs->fileExists('path/to/file.txt');

$fs->delete('path/to/file.txt');

$fs->copy('source.txt', 'dest.txt');
$fs->move('source.txt', 'dest.txt');

// Get URL (public filesystem only)
$url = $fs->publicUrl('path/to/image.jpg');

foreach ($fs->listContents('/', true) as $item) {
    if ($item instanceof \League\Flysystem\FileAttributes) {
        echo $item->path() . ' (' . $item->fileSize() . ' bytes)';
    }
}
```

---

## Redis Integration (SW 6.6.8+)

Redis can be used for caching, sessions, and queues. It is optional, so always handle its absence gracefully.

### Configuration (environment)

```yaml
# config/packages/shopware.yaml
shopware:
    cache:
        redis_prefix: '%kernel.environment%'

    redis:
        connections:
            my_plugin_cache:
                dsn: '%env(REDIS_URL)%'
```

```env
REDIS_URL=redis://localhost:6379
```

### Access via RedisConnectionProvider

```php
use Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider;

class MyRedisService
{
    public function __construct(
        private readonly RedisConnectionProvider $redisProvider,
    ) {}

    public function cacheData(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!$this->redisProvider->hasConnection('my_plugin_cache')) {
            // Redis not configured: skip or fall back to object cache
            return;
        }

        $redis = $this->redisProvider->getConnection('my_plugin_cache');
        $redis->setex('my_plugin:' . $key, $ttl, serialize($value));
    }

    public function getCachedData(string $key): mixed
    {
        if (!$this->redisProvider->hasConnection('my_plugin_cache')) {
            return null;
        }

        $redis = $this->redisProvider->getConnection('my_plugin_cache');
        $raw = $redis->get('my_plugin:' . $key);

        return $raw !== false ? unserialize($raw) : null;
    }
}
```

Register:

```php
$services->set(MyRedisService::class)
    ->args([service(RedisConnectionProvider::class)]);
```

### Redis for Message Queue transport

Shopware's `async` and `low_priority` transports read their DSN from env vars, so no YAML override is
needed:

```env
MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages
MESSENGER_TRANSPORT_LOW_PRIORITY_DSN=redis://localhost:6379/messages_low
```

---

## Rate Limiter (Custom API Routes)

### Step 1: Rate Limiter Config

```yaml
# src/Resources/config/rate_limiter.yaml
my_plugin_api:
    enabled: true
    policy: 'time_backoff'
    reset: '24 hours'
    limits:
        - limit: 10
          interval: '10 seconds'
        - limit: 100
          interval: '1 hour'
```

### Step 2: Register via Compiler Pass

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

class RateLimiterCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $config = $container->getParameter('shopware.api.rate_limiter');
        $config += Yaml::parseFile(__DIR__ . '/../Resources/config/rate_limiter.yaml');
        $container->setParameter('shopware.api.rate_limiter', $config);
    }
}
```

Register in plugin bootstrap:

```php
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(
        new RateLimiterCompilerPass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        500
    );
}
```

### Step 3: Use in Route

```php
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Symfony\Component\HttpFoundation\Request;

class MyStoreApiRoute
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    #[Route(
        path: '/store-api/my-route',
        name: 'store-api.my-plugin.my-route',
        defaults: ['_routeScope' => ['store-api']],
        methods: ['POST']
    )]
    public function load(Request $request, SalesChannelContext $context): MyRouteResponse
    {
        // Throws RateLimitExceededException (429) if exceeded
        $this->rateLimiter->ensureAccepted('my_plugin_api', $request->getClientIp());

        // Reset on success (optional, for login-style endpoints)
        $this->rateLimiter->reset('my_plugin_api', $request->getClientIp());

        return new MyRouteResponse();
    }
}
```

### Rate Limiter Policies

| Policy | Behaviour |
|--------|-----------|
| `fixed_window` | Hard limit per time window |
| `sliding_window` | Rolling window, smoother |
| `token_bucket` | Burst allowed, refills over time |
| `time_backoff` | Escalating delays on repeated failure (Shopware) |
| `system_config` | Limits read from system config (Shopware) |

---

## Data Indexer

Index pre-computed data on entity write to avoid expensive calculations on every read.

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Indexing;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexer;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

class MyEntityIndexer extends EntityIndexer
{
    public function __construct(
        private readonly IteratorFactory $iteratorFactory,
        private readonly EntityRepository $myEntityRepository,
        private readonly Connection $connection,
    ) {}

    public function getName(): string
    {
        return 'my_plugin.my_entity.indexer';
    }

    public function iterate(?array $offset): ?EntityIndexingMessage
    {
        $iterator = $this->iteratorFactory->createIterator(
            $this->myEntityRepository->getDefinition(),
            $offset
        );

        $ids = $iterator->fetch();

        if ($ids === []) {
            return null;
        }

        return new EntityIndexingMessage(array_values($ids), $iterator->getOffset());
    }

    public function update(EntityWrittenContainerEvent $event): ?EntityIndexingMessage
    {
        $ids = $event->getPrimaryKeys('my_entity');

        if ($ids === []) {
            return null;
        }

        return new EntityIndexingMessage(array_values($ids), null, $event->getContext());
    }

    public function handle(EntityIndexingMessage $message): void
    {
        $ids = $message->getData();

        // Use direct DB queries in handle() to avoid triggering further indexing
        foreach ($ids as $id) {
            $this->connection->executeStatement(
                'UPDATE `my_entity` SET `computed_score` = :score WHERE `id` = :id',
                [
                    'score' => $this->computeScore($id),
                    'id'    => Uuid::fromHexToBytes($id),
                ]
            );
        }
    }

    public function getTotal(): int
    {
        return $this->iteratorFactory->createIterator($this->myEntityRepository->getDefinition())->fetchCount();
    }

    public function getDecorated(): EntityIndexer
    {
        throw new DecorationPatternException(static::class);
    }

    private function computeScore(string $id): float
    {
        // Expensive calculation done once per update
        return 0.0;
    }
}
```

`getTotal()` and `getDecorated()` are abstract on `EntityIndexer`; a class without them is fatal.

Register:

```php
$services->set(MyEntityIndexer::class)
    ->args([
        service(IteratorFactory::class),
        service('my_entity.repository'),
        service(Connection::class),
    ])
    ->tag('shopware.entity_indexer');
```

Trigger full reindex:

```bash
bin/console dal:refresh:index --only=my_plugin.my_entity.indexer
```

---

## Field Inheritance (Product Variants)

Field inheritance lets child entities (variants) fall back to parent values when their own is `null`.

```php
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Inherited;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ParentFkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ParentAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ChildrenAssociationField;

class MyVariantDefinition extends EntityDefinition
{
    public function isInheritanceAware(): bool
    {
        return true;  // required to enable field inheritance
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            new ParentFkField(self::class),
            new ParentAssociationField(self::class, 'id'),
            new ChildrenAssociationField(self::class),

            // Inherited fields: null in child = fall back to parent value
            (new StringField('name', 'name'))->addFlags(new Inherited(), new ApiAware()),
            (new FloatField('price', 'price'))->addFlags(new Inherited(), new ApiAware()),
            (new BoolField('active', 'active'))->addFlags(new Inherited(), new ApiAware()),

            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

Fetch with inheritance resolved. A plain `Context` has `considerInheritance() === false`; only the
`SalesChannelContext` turns it on, so in Admin API, CLI or scheduled code enable it explicitly:

```php
$criteria = new Criteria([$variantId]);
$variant = $context->enableInheritance(
    fn (Context $context) => $this->variantRepository->search($criteria, $context)->getEntities()->first()
);

// name comes from parent if variant.name IS NULL
$name = $variant->getName();
```

Write only the child's own override; `null` means "inherit from parent":

```php
$this->variantRepository->update([
    [
        'id'    => $variantId,
        'name'  => null,    // revert to parent
        'price' => 29.99,   // override only price
    ],
], $context);
```

---

## In-App Purchases (IAP)

Gate premium features behind a paid add-on within the same plugin.

### PHP: Check if feature is purchased

```php
use Shopware\Core\Framework\Store\InAppPurchase;

class MyPremiumService
{
    public function __construct(
        private readonly InAppPurchase $inAppPurchase,
    ) {}

    public function doAdvancedWork(): void
    {
        if (!$this->inAppPurchase->isActive('VendorNamePluginName', 'advanced-feature')) {
            throw new \RuntimeException('Advanced feature not purchased.');
        }
    }
}
```

Inject in services.php:

```php
$services->set(MyPremiumService::class)
    ->args([service(InAppPurchase::class)]);
```

### Admin Vue: Show purchase button

```js
computed: {
    isPremiumActive() {
        return Shopware.InAppPurchase.isActive('VendorNamePluginName', 'advanced-feature');
    },
},

methods: {
    requestPurchase() {
        // opens the checkout modal; the extension name must match the plugin bundle name
        Shopware.Store.get('inAppPurchaseCheckout').request(
            { featureId: 'advanced-feature' },
            'VendorNamePluginName'
        );
    },
},
```

```twig
{# In admin component template: Twig blocks for extensibility, Vue directives for logic #}
{% block my_premium_section %}
    <my-premium-component v-if="isPremiumActive" />
    <mt-button v-else @click="requestPurchase">
        {{ $t('my-plugin.iap.unlockAdvanced') }}
    </mt-button>
{% endblock %}
```

`$tc` is deprecated since 6.7 and removed in 6.8; use `$t`.

### IAP identifiers must be registered in the Shopware Account / Extension Store dashboard before they can be purchased.

---

## Checkout Gateway (App System, SW 6.6.3+)

Apps can intercept checkout to hide/show payment and shipping methods based on cart contents.

### manifest.xml

```xml
<gateways>
    <checkout>https://my-app.com/checkout/gateway</checkout>
</gateways>
```

### App server response

The app server receives cart, sales channel context and the available methods, and answers within 5
seconds with a JSON list of commands. Each entry is `{"command": <key>, "payload": {...}}`; methods are
addressed by **technical name**, not by ID:

```json
[
    {
        "command": "remove-payment-method",
        "payload": { "paymentMethodTechnicalName": "payment_invoicepayment" }
    },
    {
        "command": "add-shipping-method",
        "payload": { "shippingMethodTechnicalName": "shipping_express" }
    }
]
```

Command keys: `add-payment-method`, `remove-payment-method`, `add-shipping-method`,
`remove-shipping-method`, `add-payment-method-extension`, `add-shipping-method-extension`,
`add-cart-error`.

Plugins can react after all app gateways respond:

```php
use Shopware\Core\Checkout\Gateway\Command\Event\CheckoutGatewayCommandsCollectedEvent;

public static function getSubscribedEvents(): array
{
    return [CheckoutGatewayCommandsCollectedEvent::class => 'onGatewayCommands'];
}

public function onGatewayCommands(CheckoutGatewayCommandsCollectedEvent $event): void
{
    $commands = $event->getCommands(); // CheckoutGatewayCommandCollection, also getCart(), getSalesChannelContext()
}
```
