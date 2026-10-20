<?php
declare(strict_types=1);

namespace wise\support\backoff;

/**
 * 退避策略
 *
 * 提供 4 种延迟计算模式，天然兼容 wise-support retry() 函数的 $sleepMs 参数
 * 和 ThinkPHP 队列 Job 的 backoff() 方法。
 *
 * 用法：
 *   // 配合 retry()
 *   retry(5, fn() => callApi(), BackoffStrategy::exponential(500, 30000));
 *
 *   // 队列 Job
 *   class MyJob { public function backoff(): array { return BackoffStrategy::linear(1000)->toArray(5); } }
 */
class BackoffStrategy
{
    private const LINEAR      = 'linear';
    private const EXPONENTIAL = 'exponential';
    private const FIXED       = 'fixed';
    private const CUSTOM      = 'custom';

    private function __construct(
        private readonly string $method,
        private readonly int $baseDelayMs = 1000,
        private readonly ?int $maxDelayMs = null,
        private readonly ?array $customDelays = null,
    ) {
    }

    // ── 工厂方法 ──

    /** 线性退避：step, step*2, step*3... */
    public static function linear(int $stepMs = 1000): self
    {
        return new self(self::LINEAR, $stepMs);
    }

    /** 指数退避：base, base*2, base*4... maxMs 加顶 */
    public static function exponential(int $baseMs = 1000, ?int $maxMs = 60000): self
    {
        return new self(self::EXPONENTIAL, $baseMs, $maxMs);
    }

    /** 固定延迟 */
    public static function fixed(int $delayMs = 5000): self
    {
        return new self(self::FIXED, $delayMs);
    }

    /** 自定义数组：按序使用，超出用最后一个值 */
    public static function custom(array $delaysMs): self
    {
        if (count($delaysMs) === 0) {
            throw new \InvalidArgumentException('Custom delays array must not be empty.');
        }
        return new self(self::CUSTOM, 0, null, $delaysMs);
    }

    // ── 核心方法 ──

    /**
     * 计算第 N 次重试的延迟（毫秒）
     *
     * @param int $attempt 重试序号（从 1 开始）
     */
    public function delayForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            return 0;
        }

        return match ($this->method) {
            self::LINEAR      => $attempt * $this->baseDelayMs,
            self::EXPONENTIAL => $this->calcExponential($attempt),
            self::FIXED       => $this->baseDelayMs,
            self::CUSTOM      => $this->calcCustom($attempt),
            default           => $this->baseDelayMs,
        };
    }

    /**
     * 序列化策略参数或生成延迟数组
     *
     * 无参 → 序列化策略配置（用于队列传输）
     * 传参 → 生成延迟数组（用于 Job::backoff()）
     *
     * @param int|null $maxAttempts 最大重试次数，null 表示序列化策略
     * @return array
     */
    public function toArray(?int $maxAttempts = null): array
    {
        if ($maxAttempts === null) {
            return $this->serialize();
        }

        $delays = [];
        for ($i = 1; $i <= $maxAttempts; $i++) {
            $delays[] = $this->delayForAttempt($i);
        }
        return $delays;
    }

    /**
     * __invoke：使实例可直接作为 retry() 的 $sleepMs 参数
     *
     * $strategy = BackoffStrategy::exponential(500);
     * retry(5, $callback, $strategy);
     */
    public function __invoke(int $attempt): int
    {
        return $this->delayForAttempt($attempt);
    }

    /**
     * 序列化为数组（用于队列传输）
     */
    public function serialize(): array
    {
        $data = [
            'method'        => $this->method,
            'base_delay_ms' => $this->baseDelayMs,
        ];
        if ($this->maxDelayMs !== null) {
            $data['max_delay_ms'] = $this->maxDelayMs;
        }
        if ($this->customDelays !== null) {
            $data['custom_delays'] = $this->customDelays;
        }
        return $data;
    }

    /**
     * 从数组还原（队列反序列化）
     */
    private const VALID_METHODS = [self::LINEAR, self::EXPONENTIAL, self::FIXED, self::CUSTOM];

    public static function fromArray(array $data): self
    {
        $method = $data['method'] ?? self::LINEAR;
        if (!in_array($method, self::VALID_METHODS, true)) {
            $method = self::LINEAR;
        }
        return new self(
            $method,
            $data['base_delay_ms'] ?? 1000,
            $data['max_delay_ms'] ?? null,
            $data['custom_delays'] ?? null,
        );
    }

    /** 获取退避方法名 */
    public function getMethod(): string
    {
        return $this->method;
    }

    // ── 内部 ──

    private function calcExponential(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** ($attempt - 1));
        $delay = (int) $delay;
        if ($this->maxDelayMs !== null && $delay > $this->maxDelayMs) {
            return $this->maxDelayMs;
        }
        return $delay;
    }

    private function calcCustom(int $attempt): int
    {
        if ($this->customDelays === null || count($this->customDelays) === 0) {
            return 5000;
        }
        $index = $attempt - 1;
        if ($index >= count($this->customDelays)) {
            return $this->customDelays[count($this->customDelays) - 1];
        }
        return $this->customDelays[$index];
    }
}
