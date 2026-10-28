# DAL: Data Abstraction Layer

Shopware 6's DAL is a Symfony-based ORM that maps database tables to typed PHP objects. All persistence goes through repositories, never through raw SQL in business logic.

---

## EntityDefinition

Every table needs a definition class:

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Content\MyEntity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class MyEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'my_entity';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return MyEntity::class;
    }

    public function getCollectionClass(): string
    {
        return MyEntityCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new ApiAware(), new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

Register in `services.php` (XML service config is deprecated since 6.7.14 and removed in 6.8; PHP config
works on 6.6 and 6.7 too):

```php
$services->set(MyEntityDefinition::class)
    ->tag('shopware.entity.definition');
```

`createdAt` and `updatedAt` are added by `EntityDefinition::defaultFields()` anyway; listing them is harmless.

---

## Entity Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Content\MyEntity;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class MyEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
```

---

## EntityCollection

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Content\MyEntity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MyEntity>
 */
class MyEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return MyEntity::class;
    }
}
```

---

## Criteria: Filtering, Sorting, Pagination

```php
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

$criteria = new Criteria();

$criteria->addFilter(new EqualsFilter('active', true));
$criteria->addFilter(new ContainsFilter('name', 'shirt'));
$criteria->addFilter(new RangeFilter('price', [
    RangeFilter::GTE => 10.00,
    RangeFilter::LTE => 100.00,
]));

$criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
    new EqualsFilter('manufacturerId', $idA),
    new EqualsFilter('manufacturerId', $idB),
]));

$criteria->addAssociation('manufacturer');
$criteria->addAssociation('categories.media');

$criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

$criteria->setLimit(25);
$criteria->setOffset(0);

$criteria->setIds([$id1, $id2]);

// Narrow the SQL read itself (unlike API `includes`, which only shape the response).
// The two are mutually exclusive:
$criteria->addFields(['id', 'name']);       // allowlist, returns PartialEntity instances
$criteria->excludeFields(['description']);  // denylist, keeps the typed entity (6.7.13+)
$criteria->resetFields();                   // drop either selection again (6.7.14+),
$criteria->resetExcludedFields();           // e.g. to switch from one to the other
```

---

## Repository Usage

```php
/**
 * @param EntityRepository<MyEntityCollection> $myEntityRepository
 */
public function __construct(
    private readonly EntityRepository $myEntityRepository,
) {}

$result = $this->myEntityRepository->search($criteria, $context);
$entities = $result->getEntities();          // MyEntityCollection
$entity   = $result->getEntities()->first(); // nullable
$total    = $result->getTotal();             // real total only with
                                             // $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT)

$idResult = $this->myEntityRepository->searchIds($criteria, $context);
$ids      = $idResult->getIds();

$this->myEntityRepository->create([
    [
        'id'   => Uuid::randomHex(),
        'name' => 'Example',
    ],
], $context);

$this->myEntityRepository->update([
    ['id' => $existingId, 'name' => 'Updated'],
], $context);

$this->myEntityRepository->upsert([
    ['id' => $id, 'name' => 'Value'],
], $context);

$this->myEntityRepository->delete([
    ['id' => $id],
], $context);

// 6.7.15+: IDs in write format, e.g. to delete everything a criteria matches
$this->myEntityRepository->delete($idResult->getPrimaryKeyData(), $context);
```

The repository service ID follows the pattern `{entity_name}.repository`; with autowiring, a
`$myEntityRepository` argument typed `EntityRepository` resolves to `my_entity.repository`.

> **6.8:** `EntitySearchResult` stops extending `EntityCollection`. Call `first()`, `filter()`, `count()`,
> `getElements()` and iterate on `$result->getEntities()`, and use `searchResult.entities` in Twig.
> Both already work on 6.7.

---

## Associations / Relations

### ManyToOne (e.g., Product → Manufacturer)

```php
(new FkField('manufacturer_id', 'manufacturerId', ProductManufacturerDefinition::class))
    ->addFlags(new ApiAware()),
new ManyToOneAssociationField('manufacturer', 'manufacturer_id', ProductManufacturerDefinition::class, 'id'),
```

### OneToMany (e.g., Product → LineItems)

```php
new OneToManyAssociationField('lineItems', OrderLineItemDefinition::class, 'product_id'),
```

### ManyToMany (e.g., Product ↔ Category)

```php
new ManyToManyAssociationField(
    'categories',
    CategoryDefinition::class,
    ProductCategoryDefinition::class, // mapping table definition
    'product_id',
    'category_id',
),
```

---

## Aggregations

```php
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;

$criteria->addAggregation(new CountAggregation('count', 'id'));
$criteria->addAggregation(new SumAggregation('revenue', 'price'));
$criteria->addAggregation(new TermsAggregation('by-manufacturer', 'manufacturerId'));

$result       = $this->repo->search($criteria, $context);
$count        = $result->getAggregations()->get('count');    // CountResult
$byManufacturer = $result->getAggregations()->get('by-manufacturer'); // TermsResult
```

---

## Custom Fields

Custom fields are schema-less key/value storage on existing entities. Since SW 6.7.13 the preferred way to
ship them is **declarative**, in `src/Resources/config/custom-fields.xml`. The programmatic API still
works and is what you need on 6.6 or when the set is built at runtime.

### Declarative (SW 6.7.13+, recommended)

```xml
<?xml version="1.0" encoding="utf-8"?>
<custom-fields xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/CustomField/Schema/custom-fields-1.0.xsd">
    <custom-field-set>
        <name>swag_example_set</name>
        <label>Example custom field set</label>
        <label lang="de-DE">Beispiel-Zusatzfeld-Set</label>
        <related-entities>
            <product/>
        </related-entities>
        <fields>
            <int name="swag_example_size">
                <label>Size</label>
                <label lang="de-DE">Größe</label>
                <position>1</position>
            </int>
            <single-select name="swag_example_mode">
                <label>Mode</label>
                <options>
                    <option value="fast">
                        <name>Fast</name>
                    </option>
                </options>
            </single-select>
        </fields>
    </custom-field-set>
</custom-fields>
```

Field elements: `int`, `float`, `text`, `text-area`, `bool`, `datetime`, `single-select`, `multi-select`,
`single-entity-select`, `multi-entity-select`, `color-picker`, `media-selection`, `price`.

Common child elements on every field: `label`, `help-text`, `required`, `position`,
`allow-customer-write`, `allow-cart-expose`, `include-in-search`. Text, numeric and select fields add
`placeholder`; numeric fields add `min`, `max`, `steps`; entity selects add `entity` and `label-property`.

Custom field names are **global across the shop**, so prefix both the set name and every field name with
your vendor short code. Scaffold a starting file with
`bin/console plugin:create --create-custom-fieldset`.

On uninstall without keeping user data, the plugin's declared field sets are removed automatically.

> **Apps:** the same file works at the app root. Declaring `<custom-fields>` inline in `manifest.xml` is
> deprecated since 6.7.13 and will be removed in 6.8.

### Programmatic (any version)

```php
$customFieldSetRepository->create([
    [
        'name'   => 'my_plugin_product_set',
        'config' => ['label' => ['en-GB' => 'My Plugin Fields']],
        'customFields' => [
            [
                'name'   => 'my_plugin_special_note',
                'type'   => CustomFieldTypes::TEXT,
                'config' => [
                    'label'       => ['en-GB' => 'Special Note'],
                    'placeholder' => ['en-GB' => 'Enter note...'],
                    'customFieldType'     => 'text',
                    'customFieldPosition' => 1,
                ],
            ],
        ],
        'relations' => [
            ['entityName' => 'product'],
        ],
    ],
], $context);
```

Read via:

```php
$entity->getCustomFields()['my_plugin_special_note'] ?? null;
```

---

## Translations (TranslationDefinition)

Translatable entities need three things: a `TranslatedField` in the main definition, a `TranslationDefinition` class, and a `_translation` database table.

### 1. Main Definition: add TranslatedField

```php
protected function defineFields(): FieldCollection
{
    return new FieldCollection([
        (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

        // TranslatedField wraps the actual field, reads from translation table
        (new TranslatedField('name'))->addFlags(new ApiAware(), new Required()),
        (new TranslatedField('description'))->addFlags(new ApiAware()),

        new CreatedAtField(),
        new UpdatedAtField(),

        // Required: link to translation table
        new TranslationsAssociationField(
            MyEntityTranslationDefinition::class,
            'my_entity_id'
        ),
    ]);
}
```

### 2. Translation Definition

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Core\Content\MyEntity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityTranslationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class MyEntityTranslationDefinition extends EntityTranslationDefinition
{
    public const ENTITY_NAME = 'my_entity_translation';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getParentDefinitionClass(): string
    {
        return MyEntityDefinition::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            // my_entity_id + language_id (composite primary key, no `id`), created_at and updated_at
            // are added automatically by EntityTranslationDefinition
            (new StringField('name', 'name'))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('description', 'description'))->addFlags(new ApiAware()),
        ]);
    }
}
```

Register in `services.php`:

```php
$services->set(MyEntityDefinition::class)
    ->tag('shopware.entity.definition');
$services->set(MyEntityTranslationDefinition::class)
    ->tag('shopware.entity.definition');
```

### 3. Migration: create `_translation` table

```php
<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1234567890MyEntity extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1234567890;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
        CREATE TABLE IF NOT EXISTS `my_entity` (
            `id`         BINARY(16)   NOT NULL,
            `created_at` DATETIME(3)  NOT NULL,
            `updated_at` DATETIME(3)  NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');

    $connection->executeStatement('
        CREATE TABLE IF NOT EXISTS `my_entity_translation` (
            `my_entity_id`  BINARY(16)    NOT NULL,
            `language_id`   BINARY(16)    NOT NULL,
            `name`          VARCHAR(255)  NOT NULL,
            `description`   LONGTEXT      NULL,
            `created_at`    DATETIME(3)   NOT NULL,
            `updated_at`    DATETIME(3)   NULL,
            PRIMARY KEY (`my_entity_id`, `language_id`),
            CONSTRAINT `fk.my_entity_translation.my_entity_id`
                FOREIGN KEY (`my_entity_id`) REFERENCES `my_entity` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk.my_entity_translation.language_id`
                FOREIGN KEY (`language_id`) REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');
    }

    public function updateDestructive(Connection $connection): void {}
}
```

### 4. Write with Translations

```php
$this->myEntityRepository->create([
    [
        'id'           => Uuid::randomHex(),
        'translations' => [
            'en-GB' => [
                'name'        => 'My Entity Name',
                'description' => 'English description',
            ],
            'de-DE' => [
                'name'        => 'Mein Entity-Name',
                'description' => 'Deutsche Beschreibung',
            ],
        ],
    ],
], $context);
```

Or with language IDs directly:

```php
'translations' => [
    Defaults::LANGUAGE_SYSTEM => ['name' => 'Default Name'],
    $deLanguageId             => ['name' => 'Deutschsprachiger Name'],
],
```

### 5. Read Translated Fields

Translated fields are resolved automatically to the context language:

```php
$criteria = new Criteria([$id]);
$entity = $this->myEntityRepository->search($criteria, $context)->getEntities()->first();

$name = $entity->getName();        // resolved to context language, falls back to default
$translated = $entity->getTranslated(); // ['name' => '...', 'description' => '...']
```

In Twig:

```twig
{{ page.myEntity.name }}               {# auto-resolved to current language #}
{{ page.myEntity.translated.name }}    {# same, explicit #}
```

---

## Context

```php
use Shopware\Core\Framework\Context;

// CLI commands, scheduled tasks, migrations: createDefaultContext() is @internal
$context = Context::createCLIContext();

public function myAction(Request $request, Context $context): Response {}

public function mySalesChannelAction(SalesChannelContext $context): Response
{
    $languageId = $context->getLanguageId();
    $currency   = $context->getCurrency();
}
```

---

## Events

The DAL dispatches lifecycle events for every write operation:

| Event | When |
|---|---|
| `{entity}.written` | After create/update/upsert for a specific entity |
| `{entity}.deleted` | After delete |
| `{entity}.loaded`  | After search/read hydration |
| `EntityWrittenContainerEvent` | Wraps all entity writes in one batch; use this to react to any write regardless of entity type |

```php
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;

public static function getSubscribedEvents(): array
{
    return ['product.written' => 'onProductWritten'];
}

public function onProductWritten(EntityWrittenEvent $event): void
{
    foreach ($event->getWriteResults() as $result) {
        $id      = $result->getPrimaryKey();
        $payload = $result->getPayload(); // changed fields
    }
}
```

---

## Migration Patterns

```php
public function update(Connection $connection): void
{
    if (!$this->columnExists($connection, 'my_entity', 'score')) {
        $connection->executeStatement('ALTER TABLE `my_entity` ADD COLUMN `score` INT NULL');
    }
}

public function updateDestructive(Connection $connection): void
{
    $this->dropColumnIfExists($connection, 'my_entity', 'legacy_score'); // 6.6.4+
}
```

MySQL has no `DROP COLUMN IF EXISTS` (that is MariaDB syntax). Guard changes with the `MigrationStep`
helpers: `columnExists()`, `indexExists()`, `dropColumnIfExists()`, `dropIndexIfExists()`,
`dropForeignKeyIfExists()`, `dropTableIfExists()`.

Foreign keys to versioned core tables (`product`, `order`, `category`, ...) must cover the whole primary
key `(id, version_id)`; since 6.7.13 `bin/console dal:validate` reports non-standard FKs, which MySQL 8.4
rejects. Since 6.7.14 the helpers above retry DDL that MySQL 8.4 refuses through bug #118151; send raw DDL
through `$this->executeDdlStatement($connection, $sql)` for the same retry (marked `@internal` only
because it goes away once MySQL fixes the bug).

---

## Entity Extensions (Extend Core Entities)

Entity Extensions add associations to existing Shopware entities **without modifying core files**. The
typed data lives in your own table, joined to the core entity; unlike custom fields (JSON blob) it has a
real schema.

`extendFields()` only accepts association fields, an `FkField` or `ReferenceVersionField` that belongs to
a ManyToOne added in the same extension, and fields flagged `Runtime` (computed, not persisted). A plain
`StringField` or `FloatField` throws `FRAMEWORK__WRONG_FIELD_TYPE_FOR_EXTENSION`, so an extension cannot
add columns to `product`.

### When to use EntityExtension vs Custom Fields

| | EntityExtension | Custom Fields |
|---|---|---|
| Type safety | Yes | No (JSON) |
| DAL filters | Yes (`myPluginData.score`) | Yes (`customFields.key`) |
| Admin UI | Manual | Auto-generated |
| Storage | Own table (migration needed) | JSON column of the entity |
| Good for | Plugin-internal typed data | User-configurable data |

### Extension Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Extension;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use VendorName\PluginName\Core\Content\ProductData\MyPluginProductDataDefinition;

class ProductExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        // Prefix with your plugin name to avoid conflicts
        $collection->add(
            (new OneToOneAssociationField(
                'myPluginData',                       // property name
                'id',                                 // local column (product.id)
                'product_id',                         // column in your table
                MyPluginProductDataDefinition::class,
                false                                 // autoload
            ))->addFlags(new ApiAware())
        );
    }

    /**
     * Abstract since SW 6.7: every extension must return the entity name it extends.
     * Return the table-style name, e.g. 'product', 'product_media', 'order_line_item'.
     */
    public function getEntityName(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }
}
```

> **SW 6.7 breaking change.** `EntityExtension::getEntityName()` is abstract and `getDefinitionClass()`
> (deprecated in 6.6) is gone from the base class. An extension written for 6.6 that only implements
> `getDefinitionClass()` is fatal on 6.7; keep both methods only if the plugin must also run on 6.6.

The definition of your table points back to the versioned `product`:

```php
class MyPluginProductDataDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'my_plugin_product_data';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new ReferenceVersionField(ProductDefinition::class))->addFlags(new Required()),
            (new FloatField('score', 'score'))->addFlags(new ApiAware()),
            (new StringField('external_id', 'externalId'))->addFlags(new ApiAware()),
            new OneToOneAssociationField('product', 'product_id', 'id', ProductDefinition::class, false),
        ]);
    }
}
```

Register the definition with **`shopware.entity.definition`** and the extension with
**`shopware.entity.extension`** (both are autoconfigured):

```php
$services->set(MyPluginProductDataDefinition::class)
    ->tag('shopware.entity.definition');
$services->set(ProductExtension::class)
    ->tag('shopware.entity.extension');
```

### Migration for the Extension Table

```php
public function update(Connection $connection): void
{
    $connection->executeStatement('
        CREATE TABLE IF NOT EXISTS `my_plugin_product_data` (
            `id`                 BINARY(16)   NOT NULL,
            `product_id`         BINARY(16)   NOT NULL,
            `product_version_id` BINARY(16)   NOT NULL,
            `score`              DOUBLE       NULL,
            `external_id`        VARCHAR(255) NULL,
            `created_at`         DATETIME(3)  NOT NULL,
            `updated_at`         DATETIME(3)  NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq.my_plugin_product_data.product` (`product_id`, `product_version_id`),
            CONSTRAINT `fk.my_plugin_product_data.product_id` FOREIGN KEY (`product_id`, `product_version_id`)
                REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');
}
```

### Read and Write Extension Data

```php
$criteria = new Criteria([$productId]);
$criteria->addAssociation('myPluginData');   // autoload is false
$product = $this->productRepository->search($criteria, $context)->getEntities()->first();

$data  = $product?->getExtension('myPluginData'); // ArrayEntity|null (no entity class defined)
$score = $data?->get('score');

$this->productRepository->update([
    [
        'id'           => $productId,
        // pass the row's own `id` once it exists, otherwise DAL tries to insert a second row
        'myPluginData' => ['id' => $dataId, 'score' => 4.8, 'externalId' => 'ERP-12345'],
    ],
], $context);

$criteria->addFilter(new RangeFilter('myPluginData.score', [RangeFilter::GTE => 4.0]));
$criteria->addFilter(new EqualsFilter('myPluginData.externalId', 'ERP-12345'));
```

### Modifying existing fields (6.7.1+)

`modifyFields()` runs after all fields are collected. It cannot add or remove fields, but it can change
flags or, since 6.7.15, extend a structured `JsonField` with `addPropertyMapping()`. The collection is
keyed by property name:

```php
public function modifyFields(FieldCollection $collection): void
{
    $stats = $collection->get('stats'); // a JsonField with property mapping on the extended entity
    if (!$stats instanceof JsonField) {
        return;
    }

    $stats->addPropertyMapping(new JsonField('landing_page', 'landing_page', [
        new IntField('maxSuggestCount', 'maxSuggestCount'),
        new IntField('maxSearchCount', 'maxSearchCount'),
    ]));
}
```
