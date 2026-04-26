<?php

/**
 * FarmIT namespace alias autoloader.
 *
 * Registers an SPL autoload handler that maps FarmIT\ClassName
 * to Tina4\ClassName on demand. When code does `use FarmIT\Route`,
 * the autoloader checks if Tina4\Route exists (triggering Composer's
 * PSR-4 loader), then creates the alias.
 *
 * This file is registered in composer.json autoload.files so it runs
 * automatically during Composer autoload. Only needs to live in the
 * top-level tina4php package — sub-packages (debug, orm, database, etc.)
 * all declare their classes in the flat Tina4\ namespace which this
 * autoloader covers.
 */

spl_autoload_register(function (string $class): void {
    // Only handle FarmIT\ namespace
    if (strncmp($class, 'FarmIT\\', 7) !== 0) {
        return;
    }

    // Map FarmIT\Foo to Tina4\Foo
    $tina4Class = 'Tina4\\' . substr($class, 7);

    // Let Composer's autoloader load the Tina4 class (second param true = autoload)
    if (class_exists($tina4Class, true) || interface_exists($tina4Class, true) || trait_exists($tina4Class, true)) {
        class_alias($tina4Class, $class);
    }
}, true, true);
