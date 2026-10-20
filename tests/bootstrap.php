<?php
declare(strict_types=1);

/**
 * Wise-Support 测试引导文件
 *
 * 不依赖 Composer autoload，直接注册 PSR-4 自动加载器。
 */

// ==================== 加载 Mockery ====================
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

// ==================== 尝试加载 Composer autoload ====================
$composerAutoload = __DIR__ . '/../../../../vendor/autoload.php';

if (file_exists($composerAutoload)) {
    $prevErrorReporting = error_reporting(E_ALL & ~E_WARNING);
    try {
        require_once $composerAutoload;
    } catch (\Throwable $e) {
        // 回退到手动加载
    }
    error_reporting($prevErrorReporting);
}

// ==================== PSR-4 手动注册 ====================
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

// 加载 wise-support 全局函数（composer autoload 不可用时的兜底）
$helpersFile = __DIR__ . '/../src/helper.php';
if (file_exists($helpersFile)) {
    require_once $helpersFile;
}

// ==================== 加载 ThinkPHP 框架全局函数 ====================
//
// topthink/framework 的 src/helper.php 定义了 app() / config() / json() /
// runtime_path() 等全局函数，但宿主项目未把它登记进 composer autoload_files，
// 导致纯 CLI 测试环境下这些函数缺失。此处按需手动引入（已存在则跳过）。
$frameworkHelper = __DIR__ . '/../../../../vendor/topthink/framework/src/helper.php';
if (!function_exists('app') && file_exists($frameworkHelper)) {
    require_once $frameworkHelper;
}
