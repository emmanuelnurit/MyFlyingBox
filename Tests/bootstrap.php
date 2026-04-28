<?php

declare(strict_types=1);

// Load the parent Thelia project's autoloader first so Propel-generated Base
// classes (in var/cache/.../propel/model/) are reachable, then layer the
// module's local autoloader (phpunit + dev deps) on top.
$projectAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
$moduleAutoload  = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($projectAutoload)) {
    require_once $projectAutoload;
}

if (is_file($moduleAutoload)) {
    require_once $moduleAutoload;
}

// Propel-generated Base/Map classes for the module live in the Thelia cache
// (var/cache/<env>/propel/model/...) rather than the module folder. They are
// normally registered by Thelia's kernel; for isolated unit tests we register
// a tiny PSR-4 fallback so reflective mocks of the model classes resolve.
$projectRoot = dirname(__DIR__, 4);
$propelCacheCandidates = [
    $projectRoot . '/var/cache/dev/propel/model',
    $projectRoot . '/var/cache/test/propel/model',
    $projectRoot . '/var/cache/prod/propel/model',
];

spl_autoload_register(static function (string $class) use ($propelCacheCandidates): void {
    // Map any Propel-generated namespace (Thelia\Model\Base\..., MyFlyingBox\Model\Base\..., etc.)
    // to its location under var/cache/<env>/propel/model/<vendor>/...
    $slashed = str_replace('\\', '/', $class);
    $vendor = strstr($slashed, '/', true); // first segment, e.g. "MyFlyingBox" or "Thelia"

    if ($vendor === false) {
        return;
    }

    $relative = $slashed . '.php';

    foreach ($propelCacheCandidates as $base) {
        $file = $base . '/' . $relative;
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    fwrite(STDERR, "MyFlyingBox tests: PHPUnit not found. Run `composer install` in local/modules/MyFlyingBox.\n");
    exit(1);
}

// Strip `final` from classes at autoload-time so we can mock final readonly
// services (PriceSurchargeService, etc.) without refactoring production code.
if (class_exists(\DG\BypassFinals::class)) {
    \DG\BypassFinals::enable();
}

return;

fwrite(STDERR, "MyFlyingBox tests: no autoloader found. Run `composer install` in the module first.\n");
exit(1);
