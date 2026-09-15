<?php
declare(strict_types=1);

namespace wise\support\config;

use think\facade\Log;
use think\facade\Cache;

/**
 * 配置检查收集器
 *
 * 收集所有包的 ConfigCheckInterface 实现，统一汇总和输出。
 *
 * 用法：
 * 1. 在包的 ServiceProvider 中调用 register() 注册检查项
 * 2. 在应用启动/请求入口处调用 report() 执行检查并上报日志
 *
 * 设计说明（针对 2026-08-16 日志重复问题）：
 * 原先使用实例属性 $reported 防止重复上报，但每个 HTTP 请求都是新进程、
 * 新容器实例，因此 $reported 每次都重置为 false，导致每个请求都会重新打印
 * 同一条失败配置（如 Redis 不可用 / API Key 缺失），形成日志刷屏。
 * 现改为使用持久化缓存（shared_file，基于文件）按检查项 id 记录上次打印时间，
 * 在冷却期内不再重复打印同一条失败，从根本上抑制跨请求的重复日志。
 */
class ConfigCheckCollector
{
    /** @var ConfigCheckInterface[] */
    private array $checks = [];

    /**
     * 同一检查项日志冷却时间（秒）。
     * 在冷却期内，同一检查项的失败日志只打印一次；超过冷却期后会再次打印，
     * 以便运维感知配置问题是否持续存在。默认 5 分钟。
     */
    private int $cooldownSeconds = 300;

    public function register(ConfigCheckInterface $check): self
    {
        $this->checks[$check->id()] = $check;
        return $this;
    }

    /**
     * 收集所有检查项结果（供健康检查接口等场景使用）
     *
     * @return array<string, CheckResult>
     */
    public function collect(): array
    {
        $results = [];
        foreach ($this->checks as $id => $check) {
            try {
                $results[$id] = $check->check();
            } catch (\Throwable $e) {
                $results[$id] = CheckResult::fail(
                    "检查项 [{$id}] 执行异常: {$e->getMessage()}",
                    '请检查该检查项的实现是否存在缺陷'
                );
            }
        }
        return $results;
    }

    /**
     * 返回所有未通过的检查结果，供健康检查接口等场景使用。
     * 失败日志受冷却时间约束，避免每个请求重复刷屏。
     * 
     * @return array<string, CheckResult> 未通过的检查结果
     */
    public function report(): array
    {
        $failed = [];
        foreach ($this->collect() as $id => $result) {
            if ($result->passed) {
                // 检查已恢复：清除冷却标记，下次若再次失败可立即打印
                Cache::store('shared_file')->delete($this->cacheKey($id));
                continue;
            }

            $failed[$id] = $result;
            if ($this->shouldLog($id)) {
                $level = $this->checks[$id]->level();
                $this->logResult($id, $level, $result);
                $this->markLogged($id);
            }
        }
        return $failed;
    }

    /**
     * 执行全部检查并返回带元信息的明细结果（一次调用即可完成"检查 + 归类"）
     *
     * 相比 collect() 额外携带所属包（package）与严重级别（level）；
     * passed 来源于本次真实执行各检查项的 check() 结果，供后台安全体检等
     * 需要聚合展示的场景直接消费，避免调用方误把"已注册实例"当作检查结果。
     *
     * @return array<string, array{id:string,package:string,level:string,passed:bool,message:string,fix:?string}>
     */
    public function detail(): array
    {
        $results = $this->collect();
        $details = [];

        foreach ($this->checks as $id => $check) {
            $result = $results[$id] ?? CheckResult::fail('未获取到检查结果');
            $details[$id] = [
                'id'      => $id,
                'package' => (string) $check->package(),
                'level'   => $check->level()->value,
                'passed'  => $result->passed,
                'message' => $result->message,
                'fix'     => $result->fixHint,
            ];
        }

        return $details;
    }

    /**
     * 缓存键，按检查项 id 隔离
     */
    private function cacheKey(string $id): string
    {
        return 'config_check_log:' . $id;
    }

    /**
     * 是否需要在本次打印该检查项的失败日志（冷却期内已打印过则返回 false）
     */
    private function shouldLog(string $id): bool
    {
        return Cache::store('shared_file')->get($this->cacheKey($id)) === null;
    }

    /**
     * 记录该检查项已在本次打印（写入冷却时间戳）
     */
    private function markLogged(string $id): void
    {
        Cache::store('shared_file')->set($this->cacheKey($id), time(), $this->cooldownSeconds);
    }

    /**
     * 根据检查项的严重级别打印对应级别的日志
     */
    private function logResult(string $id, CheckLevel $level, CheckResult $result): void
    {
        $name = basename(str_replace('\\', '/', $id));
        $message = "[{$name}] {$result->message}";
        Log::record($message, $level->value);
    }
}
