# DAL — Data Abstraction Layer

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

Register in `services.xml`:

```xml
<service id="VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition">
    <tag name="shopware.entity.definition"/>
</service>
```

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

## Criteria — Filtering, Sorting, Pagination

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
```

---

## Repository Usage

```php
public function __construct(
    private readonly EntityRepository $myEntityRepository,
) {}

$result = $this->myEntityRepository->search($criteria, $context);
$entities = $result->getEntities(); // EntityCollection
$total    = $result->getTotal();
$entity   = $result->first();       // nullable

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
```

The repository ID in `services.xml` follows the pattern `{entity_name}.repository`.

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

Custom fields are schema-less key/value storage on existing entities. Create them via API or through a plugin:

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

### 1. Main Definition — add TranslatedField

```php
protected function defineFields(): FieldCollection
{
    return new FieldCollection([
        (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

        // TranslatedField wraps the actual field — reads from translation table
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
            // id, language_id, my_entity_id are added automatically by EntityTranslationDefinition
            (new StringField('name', 'name'))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('description', 'description'))->addFlags(new ApiAware()),
        ]);
    }
}
```

Register in `services.xml`:

```xml
<service id="VendorName\PluginName\Core\Content\MyEntity\MyEntityDefinition">
    <tag name="shopware.entity.definition"/>
</service>
<service id="VendorName\PluginName\Core\Content\MyEntity\MyEntityTranslationDefinition">
    <tag name="shopware.entity.definition"/>
</service>
```

### 3. Migration — create `_translation` table

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
$entity = $this->myEntityRepository->search($criteria, $context)->first();

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

$context = Context::createDefaultContext();

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
| `EntityWrittenContainerEvent` | Wraps all entity writes in one batch — use this to react to any write regardless of entity type |

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
$connection->executeStatement('
    ALTER TABLE `product`
    ADD COLUMN `my_plugin_score` INT NULL AFTER `updated_at`;
');

$connection->executeStatement('
    ALTER TABLE `product` DROP COLUMN IF EXISTS `my_plugin_score`;
');

$schemaManager = $connection->createSchemaManager();
$columns = $schemaManager->listTableColumns('product');
if (!isset($columns['my_plugin_score'])) {
    $connection->executeStatement('ALTER TABLE `product` ADD COLUMN ...');
}
```

Always guard destructive changes with `IF EXISTS` or schema inspection.

---

## Entity Extensions (Extend Core Entities)

Entity Extensions add fields and associations to existing Shopware entities **without modifying core files**. Unlike custom fields (JSON blob), extensions add typed database columns.

### When to use EntityExtension vs Custom Fields

| | EntityExtension | Custom Fields |
|---|---|---|
| Type safety | Yes | No (JSON) |
| DAL filters | Yes | Yes (`customFields.key`) |
| Admin UI | Manual | Auto-generated |
| DB column | Yes (migration needed) | No (stored in JSON) |
| Good for | Plugin-internal typed data | User-configurable data |

### Extension Class

```php
<?php declare(strict_types=1);

namespace VendorName\PluginName\Extension;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        // Prefix with your plugin name to avoid conflicts
        $collection->add(
            (new FloatField('my_plugin_score', 'myPluginScore'))
                ->addFlags(new ApiAware())
            // NEVER add Required() flag to extension fields on existing entities
            // — this would break all existing products missing the value
        );

        $collection->add(
            (new StringField('my_plugin_external_id', 'myPluginExternalId'))
                ->addFlags(new ApiAware())
        );
    }

    public function getDefinitionClass(): string
    {
        return ProductDefinition::class;
    }
}
```

Register with **`shopware.entity.extension`** tag (not `shopware.entity.definition`):

```xml
<service id="VendorName\PluginName\Extension\ProductExtension">
    <tag name="shopware.entity.extension"/>
</service>
```

### Migration for Extension Fields

```php
public function update(Connection $connection): void
{
    $schemaManager = $connection->createSchemaManager();
    $columns = $schemaManager->listTableColumns('product');

    if (!isset($columns['my_plugin_score'])) {
        $connection->executeStatement('
            ALTER TABLE `product`
            ADD COLUMN `my_plugin_score` FLOAT NULL,
            ADD COLUMN `my_plugin_external_id` VARCHAR(255) NULL
        ');
    }
}
```

### Read and Write Extension Fields

```php
$criteria = new Criteria([$productId]);
$product = $this->productRepository->search($criteria, $context)->first();

$score      = $product->get('myPluginScore');      // via dynamic getter
$externalId = $product->get('myPluginExternalId');

$this->productRepository->update([
    [
        'id'                  => $productId,
        'myPluginScore'       => 4.8,
        'myPluginExternalId'  => 'ERP-12345',
    ],
], $context);

$criteria->addFilter(new RangeFilter('myPluginScore', [RangeFilter::GTE => 4.0]));
$criteria->addFilter(new EqualsFilter('myPluginExternalId', 'ERP-12345'));
```

### OneToOne Association Extension

```php
public function extendFields(FieldCollection $collection): void
{
    $collection->add(
        new OneToOneAssociationField(
            'myPluginData',                   // property name
            'id',                             // local key
            'product_id',                     // foreign key in your table
            MyPluginProductDataDefinition::class,
            false                             // autoload = false
        )
    );
}
```

Load the association explicitly:

```php
$criteria->addAssociation('myPluginData');
$product = $this->productRepository->search($criteria, $context)->first();
$myData  = $product->getExtension('myPluginData');
```
