# Shopware image filler

This package provides a command to fill missing images in Shopware from placeholder image websites. This simplifies
Shopware development as you don't have to download all the images from your shop's live server.

The package provides a new command `netzarbeiter:images:fill` which scans media items and adds missing images.

## Usage

```bash
bin/console netzarbeiter:images:fill
```

### Options

#### `--dry-run`

Do not install or uninstall plugins, just show what would be done.

#### `--limit=<NUMBER>`

Only check the first `NUMBER` of media items. This is useful for testing.

## Installation

Make sure Composer is installed globally, as explained in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Applications that use Symfony Flex

Open a command console, enter your project directory and execute:

```console
$ composer require <package-name>
```

### Applications that don't use Symfony Flex

#### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the following command to download the latest stable
version of this bundle:

```console
$ composer require <package-name>
```

#### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Netzarbeiter\Shopware\ImageFiller\NetzarbeiterShopwareImageFillerBundle::class => ['all' => true],
];
```
