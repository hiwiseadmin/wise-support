<?php
declare(strict_types=1);

namespace wise\support\exception;

/**
 * 异常渲染器接口
 *
 * 各 wise-* 包可通过实现此接口并注册到 ExtPoint('exception.renderer')
 * 来定制异常的渲染方式（API JSON / HTML 页面 / CLI 输出 等）。
 *
 * @package wise\support\exception
 */
interface ExceptionRendererInterface
{
    /**
     * 将任意异常渲染为统一结构数组
     *
     * @param \Throwable $e 待渲染的异常（WiseException 或任意 Throwable）
     * @return array{code: int, msg: string, data?: array} 基础结构，
     *         由调用方（如 app\ExceptionHandle）补全 request_id/timestamp 后输出
     */
    public function render(\Throwable $e): array;
}
