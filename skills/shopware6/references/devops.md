# DevOps: Logging, Static Analysis, CI/CD, Media, Debugging

---

## Structured Logging

Always inject `LoggerInterface`. Never use `error_log()`, `var_dump()`, or `dump()` in production code.

```php
use Psr\Log\LoggerInterface;

class MyService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function processOrder(string $orderId): void
    {
        $this->logger->info('Processing order', ['orderId' => $orderId]);

        try {
            $result = $this->doHeavyWork($orderId);

            $this->logger->info('Order processed successfully', [
                'orderId'    => $orderId,
                'resultCode' => $result->getCode(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Order processing failed', [
                'orderId'   => $orderId,
                'error'     => $e->getMessage(),
                'exception' => $e,  // Monolog serializes it safely
            ]);

            throw $e;
        }
    }
}
```

Inject in `src/Resources/config/services.php`:

```php
use VendorName\PluginName\Service\MyService;

$services->set(MyService::class)
    ->args([service('logger')]);
    // Or a dedicated channel: ->args([service('monolog.logger.my_plugin')])
```

### Custom Monolog Channel

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - my_plugin

    handlers:
        my_plugin:
            type:     rotating_file
            path:     '%kernel.logs_dir%/my_plugin.log'
            level:    info
            channels: [my_plugin]
            max_files: 14
```

Service reference: `monolog.logger.my_plugin`

### Log Levels

| Level | When to use |
|---|---|
| `debug` | Detailed diagnostic info (dev only) |
| `info` | Successful operations, state changes |
| `notice` | Unusual but expected events |
| `warning` | Recoverable issues, deprecated usage |
| `error` | Failures that need attention |
| `critical` | System component unavailable |
| `emergency` | System is unusable |

Never log: passwords, session tokens, full credit card data, customer PII without consent.

---

## Static Analysis (PHPStan)

Target PHPStan level 8 for Shopware plugins.

### phpstan.neon

```neon
includes:
    - vendor/phpstan/phpstan-symfony/extension.neon
    - vendor/phpstan/phpstan-doctrine/extension.neon

parameters:
    level: 8
    paths:
        - src
    bootstrapFiles:
        - vendor/autoload.php
    symfony:
        # written on boot with APP_DEBUG=1; the hash changes with the set of active plugins
        containerXmlPath: var/cache/dev_h<hash>/Shopware_Core_KernelDevDebugContainer.xml
    ignoreErrors:
        # Shopware-specific dynamic property access
        - '#Access to an undefined property Shopware\\Core\\Framework\\DataAbstractionLayer\\Entity::#'
```

Since 6.7.14, planned BC changes that are not removals are marked with attributes from `Shopware\Core\Framework\Deprecation\BCChange` (`#[ReturnTypeNarrowing]`, `#[NewOptionalParameter]`, `#[BecomesFinal]`, `#[ExperimentalReplacement]`, ...). A `@deprecated` tag on core code now always means removal, so `phpstan-deprecation-rules` reports only those.

### Run

```bash
vendor/bin/phpstan analyse src --level=8

# With Shopware extension
vendor/bin/phpstan analyse src --configuration=phpstan.neon
```

### PHP CS Fixer

```php
<?php
// .php-cs-fixer.php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS'                       => true,
        '@Symfony'                      => true,
        'declare_strict_types'          => true,
        'array_syntax'                  => ['syntax' => 'short'],
        'ordered_imports'               => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'             => true,
        'single_quote'                  => true,
        'trailing_comma_in_multiline'   => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
```

```bash
# Check
vendor/bin/php-cs-fixer fix --dry-run --diff

# Fix
vendor/bin/php-cs-fixer fix
```

---

## CI/CD Pipeline (GitHub Actions)

```yaml
# .github/workflows/ci.yml
name: CI

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: shopware_test
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, pdo_mysql, zip, gd
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: PHP CS Fixer
        run: vendor/bin/php-cs-fixer fix --dry-run --diff

      - name: PHPStan
        run: vendor/bin/phpstan analyse src --level=8 --no-progress

      - name: PHPUnit Unit
        run: vendor/bin/phpunit --testsuite=unit

      - name: PHPUnit Integration
        env:
          DATABASE_URL: mysql://root:root@127.0.0.1:3306/shopware_test
        run: vendor/bin/phpunit --testsuite=integration
```

---

## Deployment

Recommended for 6.7: build the artifact with Shopware CLI, deploy with the Deployment Helper (`composer require shopware/deployment-helper`, 6.5.8+):

```bash
# CI build: composer install (no dev), extension assets, snippet merge, SBOM
shopware-cli project ci .

# On the target: installs Shopware on first run; on updates runs system:update:finish
# (only if the version changed), refreshes/installs/updates extensions, compiles themes,
# runs one-time tasks and hooks
vendor/bin/shopware-deployment-helper run
# --skip-theme-compile / --skip-assets-install only if the build already produced them
```

Configure it in `.config/shopware-project.yml` (`deployment.hooks.pre|post|pre-install|post-install|pre-update|post-update`, `deployment.extension-management`, `deployment.one-time-tasks`, `deployment.maintenance.enabled`, `deployment.cache.always_clear`). Docs: https://developer.shopware.com/docs/guides/hosting/installation-updates/deployments/deployment-helper.html

Manual sequence, if you do not use the helper:

```bash
# Standard deployment sequence
composer install --no-dev --optimize-autoloader

# Run DB migrations
bin/console system:update:finish

# Or granular:
bin/console database:migrate --all
bin/console database:migrate-destructive --all

# Refresh plugin list
bin/console plugin:refresh

# Build assets (if admin or storefront were changed)
bin/build-administration.sh
bin/build-storefront.sh

# Compile theme (--no-cleanup is deprecated and has no effect since 6.7.15.1: drop it)
bin/console theme:compile
bin/console theme:change --all MyTheme  # if needed

# Clear cache
bin/console cache:clear
bin/console cache:warmup

# Restart message queue workers (example: supervisord)
supervisorctl restart shopware-worker:*
```

Old `public/theme/<hash>` directories are removed only by the `theme.delete_files` scheduled task (marked `.retired`, deleted 24 to 48 hours after the switch since 6.7.15.1), so scheduled tasks must run. Workers should consume `webhook async low_priority` (see performance.md).

### Development Setup

```bash
# Clone Shopware and install
composer create-project shopware/production shopware
cd shopware

# Configure .env
DATABASE_URL=mysql://root:root@localhost/shopware
APP_URL=http://shopware.test

# Install
bin/console system:install --create-database --basic-setup

# Symlink your plugin
ln -s /path/to/MyPlugin custom/plugins/MyPlugin

# Install plugin
bin/console plugin:refresh
bin/console plugin:install --activate MyPlugin

# Run migrations
bin/console database:migrate --all MyPlugin
```

---

## Debugging

### Xdebug Setup

```ini
# php.ini
[xdebug]
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
xdebug.idekey=PHPSTORM
```

### Symfony Profiler

Access at `/_profiler` in dev mode. Shows:

- SQL queries (count, duration)
- Events dispatched
- Cache hits/misses
- Request/Response details

Enable in `.env`:

```env
APP_ENV=dev
APP_DEBUG=1
```

### Useful Debug Commands

```bash
# Show all registered services
bin/console debug:container

# Show specific service definition
bin/console debug:container VendorName\\PluginName\\Service\\MyService

# Show all routes
bin/console debug:router

# Show event subscribers
bin/console debug:event-dispatcher

# Container XML for the PHPStan Symfony plugin: written automatically in debug mode to
# var/cache/<env>_h<hash>/Shopware_Core_Kernel<Env>DebugContainer.xml (no dump command needed)

# Validate DAL entity definitions
bin/console dal:validate

# List business events (Flow Builder triggers)
bin/console debug:business-events

# Show installed plugins
bin/console plugin:list
```

---

## Media Handling

```php
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Uuid\Uuid;

class MyMediaService
{
    public function __construct(
        private readonly FileSaver $fileSaver,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
    ) {}

    public function uploadFile(string $filePath, string $fileName, Context $context): string
    {
        $mediaId = Uuid::randomHex();
        $this->mediaRepository->create([
            ['id' => $mediaId],
        ], $context);

        $folderId = $this->getOrCreateMediaFolder('My Plugin Uploads', $context);

        $mediaFile = new MediaFile(
            $filePath,
            mime_content_type($filePath),
            pathinfo($fileName, PATHINFO_EXTENSION),
            filesize($filePath)
        );

        // destination = file name minus extension; the extension comes from MediaFile
        $this->fileSaver->persistFileToMedia($mediaFile, pathinfo($fileName, PATHINFO_FILENAME), $mediaId, $context);

        if ($folderId) {
            $this->mediaRepository->update([
                ['id' => $mediaId, 'mediaFolderId' => $folderId],
            ], $context);
        }

        return $mediaId;
    }

    private function getOrCreateMediaFolder(string $folderName, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $folderName));

        $existing = $this->mediaFolderRepository->searchIds($criteria, $context)->firstId();
        if ($existing !== null) {
            return $existing;
        }

        $folderId = Uuid::randomHex();
        $this->mediaFolderRepository->create([
            ['id' => $folderId, 'name' => $folderName, 'useParentConfiguration' => false],
        ], $context);

        return $folderId;
    }
}
```

`MediaService::saveMediaFile($mediaFile, $fileNameWithoutExtension, $context, 'product', private: false)` creates and persists in one call, in the default folder of that entity. `private` defaults to `true` there.

### Link Media to Entity

```php
$this->productRepository->update([
    [
        'id'    => $productId,
        'cover' => ['mediaId' => $mediaId],
        'media' => [['mediaId' => $mediaId, 'position' => 0]],
    ],
], $context);
```

### Generate Thumbnails

```bash
bin/console media:generate-thumbnails
# Or for a specific folder:
bin/console media:generate-thumbnails --folder-name="My Plugin Uploads"
# 6.7.14+: regenerate existing thumbnails too (same paths, so CDN/browser caches may serve old files)
bin/console media:generate-thumbnails --force
# 6.7.14+: remove orphaned thumbnail files only (safe in every setup)
bin/console media:delete-local-thumbnails --orphans
```

---

## Upgrade & Migration Safety

### XML Configuration Is Deprecated (6.7.14+)

Loading `services.xml`, `routes.xml` and `packages/**/*.xml` from plugins, bundles and the project `config/` directory is deprecated since 6.7.14 and removed in 6.8 (Symfony 8 drops XML config). Each loaded XML file already triggers a deprecation log entry that names the file and, for plugins, the bundle. Plugins that still ship them fail to load on 6.8. Migrate to `services.php` (`ContainerConfigurator`) and `routes.php` (`RoutingConfigurator`); ids, arguments and tags stay identical, YAML stays supported. `config.xml`, `custom-fields.xml` and app manifests are not affected.

`src/Resources/config/routes.php` (for `services.php` see plugin-structure.md):

```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Storefront/Controller/**/*Controller.php', 'attribute');
};
```

### Backward-Compatible Migrations

```php
public function update(Connection $connection): void
{
    // Always use IF NOT EXISTS / IF EXISTS
    $connection->executeStatement('
        CREATE TABLE IF NOT EXISTS `my_table` (...)
    ');

    // MigrationStep helper: no-op if the column exists, retries on MySQL 8.4 bug #118151 (6.7.14+)
    $this->addColumn($connection, 'product', 'my_plugin_score', 'FLOAT');
}

public function updateDestructive(Connection $connection): void
{
    // Only destructive ops here (DROP TABLE, DROP COLUMN)
    // These run only when explicitly called:
    // bin/console database:migrate-destructive --all
    // MySQL has no DROP COLUMN IF EXISTS; the helper returns false if the column is missing
    $this->dropColumnIfExists($connection, 'product', 'my_plugin_score');
}
```

Other raw DDL that may hit MySQL 8.4's `restrict_fk_on_non_standard_key` should go through `$this->executeDdlStatement($connection, $sql)` (marked `@internal` only because it disappears once MySQL fixes the bug).

### Version Check in Plugin Bootstrap

```php
public function update(UpdateContext $updateContext): void
{
    parent::update($updateContext);

    if (version_compare($updateContext->getCurrentPluginVersion(), '2.0.0', '<')) {
        $this->migrateToV2($updateContext->getContext());
    }
}
```
