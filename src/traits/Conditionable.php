<?php
declare(strict_types=1);

namespace wise\support\traits;

/**
 * 条件链式调用 Trait（Conditionable）
 *
 * 在链式调用中嵌入条件分支，而不中断链式调用的流畅性。
 *
 * 核心方法：
 * - when($condition, $callback, $default)    条件为真时执行回调
 * - unless($condition, $callback, $default)   条件为假时执行回调
 *
 * 回调签名: fn($this, $condition): mixed
 * - 返回 $this 或 null → 继续链式调用
 * - 返回其他值 → 替换当前实例（值转换场景）
 *
 * 使用示例：
 * <pre>
 * class QrcodeBuilder
 * {
 *     use Conditionable;
 *
 *     public function size(int $size): self { return $this; }
 *     public function logo(string $path): self { return $this; }
 * }
 *
 * $qr = (new QrcodeBuilder())
 *     ->size(300)
 *     ->when($hasLogo, fn($q) => $q->logo($path))
 *     ->when($colorize, fn($q) => $q->color('#ff0000'))
 *     ->generate();
 * </pre>
 *
 * @package wise\support\traits
 */
trait Conditionable
{
    /**
     * 条件为真时执行回调
     *
     * @param mixed          $condition 条件（truthy → 执行 callback，falsy → 执行 $default 或跳过）
     * @param callable       $callback  条件为真时的回调 fn($this, $condition): mixed
     * @param callable|null  $default   条件为假时的回调 fn($this, $condition): mixed
     * @return mixed  $this 或回调返回值
     */
    public function when($condition, callable $callback, ?callable $default = null)
    {
        if ($condition) {
            $result = $callback($this, $condition);

            // 返回值不是自身 → 替换调用链（值转换场景）
            if ($result !== null && $result !== $this) {
                return $result;
            }

            return $this;
        }

        if ($default !== null) {
            $result = $default($this, $condition);

            if ($result !== null && $result !== $this) {
                return $result;
            }

            return $this;
        }

        return $this;
    }

    /**
     * 条件为假时执行回调（when 的取反）
     *
     * @param mixed          $condition 条件（falsy → 执行 callback）
     * @param callable       $callback  条件为假时的回调 fn($this, $condition): mixed
     * @param callable|null  $default   条件为真时的回调 fn($this, $condition): mixed
     * @return mixed  $this 或回调返回值
     */
    public function unless($condition, callable $callback, ?callable $default = null)
    {
        return $this->when(!$condition, $callback, $default);
    }
}
