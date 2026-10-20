<?php
declare(strict_types=1);

namespace wise\support\circuit;

/**
 * 断路器异常接口
 *
 * 任何触发熔断时抛出的异常应实现本接口，使 wise\support\traits\JsonResponse
 * 的 circuitResponse() 能统一映射为 HTTP 503 + Retry-After 响应，
 * 而不依赖具体包命名空间。
 *
 * 实现类示例：
 *   - wise\api\exception\ServiceUnavailableException
 *
 * @package wise\support\circuit
 */
interface CircuitBreakerExceptionInterface extends \Throwable
{
    /**
     * 获取建议客户端重试的等待秒数
     *
     * @return int 秒数（通常等于断路器冷却时间）
     */
    public function getRetryAfter(): int;
}
