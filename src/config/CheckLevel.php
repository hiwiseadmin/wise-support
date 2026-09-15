<?php
declare(strict_types=1);

namespace wise\support\config;

/**
 * 配置检查严重级别
 */
enum CheckLevel: string
{
    /** 警告：配置缺失但不影响核心功能 */
    case WARNING = 'warning';

    /** 错误：配置缺失将导致功能不可用 */
    case ERROR   = 'error';

    /** 致命：运行时环境不可用（如 Redis/DB 连接失败），核心功能无法正常工作 */
    case FATAL   = 'fatal';
}
