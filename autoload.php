<?php

if (!defined('ABSPATH')) { exit; }

// Keep the original public class names available to snippets and integrations.
$archivio_micologico_aliases = [
    'am_activator' => ArchivioMicologico\Activator::class,
    'am_post_types' => ArchivioMicologico\PostTypes::class,
    'am_taxonomies' => ArchivioMicologico\Taxonomies::class,
    'am_admin' => ArchivioMicologico\Admin::class,
    'am_frontend' => ArchivioMicologico\Frontend::class,
    'am_widget_random_funghi' => ArchivioMicologico\RandomFungiWidget::class,
];

spl_autoload_register(static function (string $class) use ($archivio_micologico_aliases): void {
    $prefix = 'ArchivioMicologico\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $relative)) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }

    $target = $archivio_micologico_aliases[strtolower($class)] ?? null;
    if ($target && class_exists($target)) {
        class_alias($target, $class);
    }
});
unset($archivio_micologico_aliases);
