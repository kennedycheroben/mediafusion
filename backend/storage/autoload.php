<?php
declare(strict_types=1);

/**
 * Storage autoloader — registers PSR-4 style autoloading for MediaFusion\Storage namespace.
 * Included by bootstrap.php.
 */

spl_autoload_register(function (string $class) {
    $prefix = 'MediaFusion\\Storage\\';
    if (!str_starts_with($class, $prefix)) return;

    $relative = str_replace($prefix, '', $class);
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
