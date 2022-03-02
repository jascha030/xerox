<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Jascha030\Xerox\Xerographer;

/**
 * Loader loads autoload.php, locally or from $HOME/.composer/vendor/autoload.php.
 */
require_once __DIR__ . '/loader.php';

/**
 * Create ContainerBuilder.
 */
$containerBuilder = new ContainerBuilder(Container::class);

/**
 * Add Container definition config files.
 */
foreach (Xerographer::getConfigurationFiles() as $definitionFile) {
    $containerBuilder->addDefinitions($definitionFile);
}

/**
 * Set Container settings, for the console Application.
 */
$containerBuilder->useAutowiring(false);
$containerBuilder->useAnnotations(false);

/**
 * Build and return Container.
 *
 * @noinspection PhpUnhandledExceptionInspection
 *
 * @return Container
 */
return $containerBuilder->build();
