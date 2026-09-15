<?php
declare(strict_types=1);

namespace wise\support;

use Closure;

/**
 * 通用管道（Pipeline）
 *
 * 洋葱模型管道：将一组 Pipe（闭包或类名）按顺序包裹，
 * 形成 "Pipe1 → Pipe2 → ... → destination" 的执行链。
 *
 * 核心机制：
 *  - 从右向左包裹所有 Pipe，形成洋葱层
 *  - 每个 Pipe 调用 $next($passable) 传递给下一层
 *  - 任何 Pipe 抛出异常即中止管道
 *
 * 使用场景：
 *  - HTTP 中间件职责链
 *  - 数据 ETL 处理管道
 *  - 通知处理链
 *  - API 请求/响应转换
 *  - 任何需要多层处理的编排场景
 *
 * 使用示例：
 * <pre>
 * $result = (new Pipeline())
 *     ->send($data)
 *     ->through([
 *         TrimStringsPipe::class,
 *         NullToEmptyPipe::class,
 *         fn($data, $next) => $next(array_map('strtolower', $data)),
 *     ])
 *     ->then(fn($data) => DataModel::create($data));
 * </pre>
 *
 * @package wise\support
 */
class Pipeline
{
    /**
     * 管道载荷（任意类型）
     * @var mixed
     */
    protected $passable;

    /**
     * 是否已通过 send() 显式设置载荷（区分"未设置"与"设为 null"）
     * @var bool
     */
    protected bool $passableSet = false;

    /**
     * 管道工序列表
     * @var array<Closure|string|object>
     */
    protected array $pipes = [];

    /**
     * 要调用的方法名（Pipe 为类名时调用此方法）
     * @var string
     */
    protected string $method = 'handle';

    /**
     * Pipe 实例缓存（避免重复实例化类名 pipe）
     * @var array<string, object>
     */
    protected array $pipeInstances = [];

    /**
     * 设置管道载荷
     *
     * @param mixed $passable 任意类型载荷（包括 null）
     * @return $this
     */
    public function send($passable): self
    {
        $this->passable = $passable;
        $this->passableSet = true;
        return $this;
    }

    /**
     * 设置管道工序列表
     *
     * @param array<Closure|string|object> $pipes Pipe 列表：
     *   - Closure → 直接作为 pipe
     *   - string   → 类名，通过 via() 指定的方法调用
     *   - object   → 已有实例，直接调用
     * @return $this
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;
        return $this;
    }

    /**
     * 设置 Pipe 类调用的方法名（默认 'handle'）
     *
     * @param string $method
     * @return $this
     */
    public function via(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * 执行管道
     *
     * @param Closure $destination 最终回调（所有 pipe 通过后执行）
     * @return mixed
     *
     * @throws \RuntimeException 未设置载荷时抛出
     */
    public function then(Closure $destination)
    {
        if (!$this->passableSet && empty($this->pipes)) {
            // 空管道，未设置载荷 → 直接返回 destination(null)
            return $destination(null);
        }

        // 空管道但已设置载荷（包括 null） → 直接调用 destination
        if (empty($this->pipes)) {
            return $destination($this->passable);
        }

        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination)
        );

        return $pipeline($this->passable);
    }

    /**
     * 执行管道，原样返回 passable（无 destination）
     *
     * @return mixed
     */
    public function thenReturn()
    {
        return $this->then(function ($passable) {
            return $passable;
        });
    }

    /**
     * 洋葱包裹器：将当前 pipe 包裹在已归约的管道外侧
     *
     * 执行顺序（从 send 方向看）：
     *   Pipe1 → Pipe2 → Pipe3 → destination
     *
     * 归约方向（从右向左包裹）：
     *   Pipe3(destination) → Pipe2(Pipe3+destination) → Pipe1(Pipe2+Pipe3+destination)
     *
     * @return Closure
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                // 如果 pipe 是闭包，直接执行
                if ($pipe instanceof Closure) {
                    return $pipe($passable, $stack);
                }

                // 如果 pipe 已经是对象，调用其方法
                if (is_object($pipe)) {
                    return $pipe->{$this->method}($passable, $stack);
                }

                // 类名字符串：先获取/创建实例，再调用
                $instance = $this->resolvePipe($pipe);

                return $instance->{$this->method}($passable, $stack);
            };
        };
    }

    /**
     * 准备最终回调（destination）
     *
     * @param Closure $destination
     * @return Closure
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * 解析 Pipe 实例
     *
     * 支持带缓存的类实例化，避免同一 Pipe 类重复创建。
     *
     * @param string $pipeClass Pipe 类名
     * @return object
     *
     * @throws \RuntimeException Pipe 类不存在时抛出
     */
    protected function resolvePipe(string $pipeClass): object
    {
        // 缓存命中
        if (isset($this->pipeInstances[$pipeClass])) {
            return $this->pipeInstances[$pipeClass];
        }

        if (!class_exists($pipeClass)) {
            throw new \RuntimeException(sprintf(
                '流水线工序类不存在: [%s]',
                $pipeClass
            ));
        }

        $instance = new $pipeClass();
        $this->pipeInstances[$pipeClass] = $instance;

        return $instance;
    }

    /**
     * 清空 Pipe 实例缓存
     *
     * @return $this
     */
    public function flushPipeCache(): self
    {
        $this->pipeInstances = [];
        return $this;
    }
}
