<?php
declare(strict_types=1);

namespace wise\support\config;

/**
 * 统一配置检查接口
 *
 * 每个包/插件实现此接口，将配置检查注册到 ConfigCheckCollector 统一管理。
 *
 * 用法：
 *   app(ConfigCheckCollector::class)->register(new MyCheck());
 */
interface ConfigCheckInterface
{
    /** 检查项唯一标识，如 'wise-jwt:secret' */
    public function id(): string;

    /** 所属包名，如 'wise-jwt' */
    public function package(): string;

    /** 严重级别 */
    public function level(): CheckLevel;

    /** 执行检查 */
    public function check(): CheckResult;
}
