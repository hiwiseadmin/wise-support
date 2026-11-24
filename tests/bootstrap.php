<?php
declare(strict_types=1);

/**
 * Wise-Support 测试引导文件
 *
 * 不依赖 Composer autoload，直接注册 PSR-4 自动加载器。
 */

$mockeryBaseDir = __DIR__ . '/../../../../vendor/mockery/mockery/';
$mockeryMainFile = $mockeryBaseDir . 'Mockery.php';
$mockeryHelpersFile = $mockeryBaseDir . 'helpers.php';
$mockeryPsr4Dir = $mockeryBaseDir . 'Mockery';

if (!file_exists($mockeryMainFile)) {
    $mockeryMainFile = $mockeryBaseDir . 'library/Mockery.php';
    $mockeryHelpersFile = $mockeryBaseDir . 'library/helpers.php';
    $mockeryPsr4Dir = $mockeryBaseDir . 'library/Mockery';
}

if (file_exists($mockeryMainFile)) {
    spl_autoload_register(function (string $class) use ($mockeryPsr4Dir): void {
        $prefix = 'Mockery\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $mockeryPsr4Dir . '/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
    if (file_exists($mockeryHelpersFile)) {
        require_once $mockeryHelpersFile;
    }
    require_once $mockeryMainFile;
}

$composerAutoload = __DIR__ . '/../../../../vendor/autoload.php';

if (file_exists($composerAutoload)) {
    $prevErrorReporting = error_reporting(E_ALL & ~E_WARNING);
    try {
        require_once $composerAutoload;
    } catch (\Throwable $e) {
    }
    error_reporting($prevErrorReporting);
}

spl_autoload_register(function (string $class): void {
    $prefix = 'wise\\support\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

$helpersFile = __DIR__ . '/../src/helper.php';
if (file_exists($helpersFile)) {
    require_once $helpersFile;
}

$frameworkHelper = __DIR__ . '/../../../../vendor/topthink/framework/src/helper.php';
if (!function_exists('app') && file_exists($frameworkHelper)) {
    require_once $frameworkHelper;
}
