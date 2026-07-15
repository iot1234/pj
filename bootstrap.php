<?php
declare(strict_types=1);

use Dormitory\Application;
use Dormitory\Config;

define('DORMITORY_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dormitory\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = DORMITORY_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

date_default_timezone_set('UTC');

$config = Config::fromEnvironment(DORMITORY_ROOT);
$config->configurePhp();
$config->enforceHttps();

return new Application($config);
