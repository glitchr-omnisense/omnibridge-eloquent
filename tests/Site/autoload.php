<?php

/*
 * omnibridge/eloquent beside an application that does not install it (plan "omnibase agnostic", step F7b: the
 * proofs on lesringostars, its vendor left as its lock says): the package copied into the application's
 * vendor/omnibridge/eloquent with its own vendor/, and its classes - Illuminate's, Carbon's, the package's and
 * its tests' - found by a class loader appended after the application's, so that any class the application has
 * stays the application's. Illuminate's helper files are loaded where the application has none.
 */

use Composer\Autoload\ClassLoader;

(static function (): void {
    $package = dirname(__DIR__, 2);
    $vendor = $package.'/vendor';
    if (class_exists(\Omnibridge\Eloquent\EntityStore::class, true) && class_exists(\Illuminate\Database\Connection::class, true)) {
        return;
    }
    if (!is_file($vendor.'/composer/autoload_psr4.php')) {
        throw new \RuntimeException('omnibridge/eloquent needs its own vendor/ (composer install in '.$package.').');
    }
    $loader = new ClassLoader($vendor);
    foreach (require $vendor.'/composer/autoload_psr4.php' as $prefix => $paths) {
        $loader->setPsr4($prefix, $paths);
    }
    $loader->setPsr4('Omnibridge\\Eloquent\\', [$package.'/src']);
    $loader->setPsr4('Omnibridge\\Eloquent\\Tests\\', [$package.'/tests']);
    $loader->register(false);
    foreach (require $vendor.'/composer/autoload_files.php' as $file) {
        if (preg_match('#/vendor/(illuminate|laravel|nesbot|carbonphp|voku)/#', $file) && is_file($file)) {
            require_once $file;
        }
    }
})();
