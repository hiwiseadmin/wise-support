<?php
declare(strict_types=1);

namespace wise\support\tools;

/**
 * 通用文本差异对比＼�
 *
 * 零依赖实现（不引入 jfcherng/php-diff 等外部包）：
 *   1. normalizeToLines() —— 归一换行 + 按 HTML 块级标签切行（编辑态 HTML 常为整行）；
 *   2. diff()             —— 去除公共前后缀后做 LCS 动态规划，产出 equal/del/ins 序列；
 *   3. stats()            —— 汇总增删行数；
 *   4. toHtml()           —— 渲染为统一 diff（长 equal 段折叠），**所有文本强制转义**；
 *   5. diffFields()       —— 字段级差异摘要。
 *
 * 性能护栏（防 O(n·m) 内存爆炸）：
 *   - 先剥离公共前后缀，只对差异中段做 DP（常见场景下中段极小）；
 *   - 中段行数乘积超过 MAX_CELLS，或单侧超过构造参数 maxLines 时，
 *     降级为「整块替换」并标记 degraded = true。
 *
 * 方向约定：diff(old, new) 中 old = 基准、new = 目标；
 *   del = 应用目标后被移除的内容，ins = 应用目标后新增的内容。
 *
 * 设计约束（放 wise-support 而非宿主的原因）：
 *   - 纯算法、零框架依赖，宿主 / wise-* 包 / 插件均可直接复用；
 *   - **不读任何配置**：行数上限由调用方通过构造参数注入，
 *     避免基础设施包反向依赖宿主或插件的配置域；
 *   - **不含业务规则**：字段中文标签、跳过某字段等属于调用方业务，
 *     通过 $labels 入参与调用方自行过滤实现。
 */
class TextDiff
{
    /** LCS 动态规划的最大单元格数（超出即降级） */
    public const MAX_CELLS = 250000;

    /** 默认最大行数 */
    public const DEFAULT_MAX_LINES = 2000;

    /** 单侧最大对比行数（0 表示不限制，仅靠 MAX_CELLS 护栏） */
    protected int $maxLines;

    public function __construct(int $maxLines = self::DEFAULT_MAX_LINES)
    {
        $this->maxLines = max(0, $maxLines);
    }


    /**
     * 文本归一为「行」数组
     *
     * @return string[]
     */
    public function normalizeToLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $pattern = '#(</(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|thead|tbody|blockquote|pre|section|article|header|footer|figure|figcaption)>|<br\s*/?>|<hr\s*/?>)#i';
        $text = preg_replace($pattern, "$1\n", $text) ?? $text;

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }


    /**
     * 行级差异
     *
     * @param string[] $old
     * @param string[] $new
     * @return array{ops:array<int,array{op:string,text:string}>,degraded:bool}
     */
    public function diff(array $old, array $new): array
    {
        $old = array_values(array_map('strval', $old));
        $new = array_values(array_map('strval', $new));

        $ops = [];

        $head = 0;
        $oldCount = count($old);
        $newCount = count($new);
        while ($head < $oldCount && $head < $newCount && $old[$head] === $new[$head]) {
            $ops[] = ['op' => 'equal', 'text' => $old[$head]];
            $head++;
        }

        $tail = 0;
        while (
            $tail < ($oldCount - $head)
            && $tail < ($newCount - $head)
            && $old[$oldCount - 1 - $tail] === $new[$newCount - 1 - $tail]
        ) {
            $tail++;
        }

        $oldMid = array_slice($old, $head, $oldCount - $head - $tail);
        $newMid = array_slice($new, $head, $newCount - $head - $tail);

        $degraded = $this->shouldDegrade($oldMid, $newMid);
        if ($degraded) {
            foreach ($oldMid as $line) {
                $ops[] = ['op' => 'del', 'text' => $line];
            }
            foreach ($newMid as $line) {
                $ops[] = ['op' => 'ins', 'text' => $line];
            }
        } else {
            foreach ($this->lcsOps($oldMid, $newMid) as $op) {
                $ops[] = $op;
            }
        }

        for ($i = $tail; $i > 0; $i--) {
            $ops[] = ['op' => 'equal', 'text' => $old[$oldCount - $i]];
        }

        return ['ops' => $ops, 'degraded' => $degraded];
    }

    /**
     * 文本级差异（自动归一为行）
     */
    public function diffText(string $old, string $new): array
    {
        return $this->diff($this->normalizeToLines($old), $this->normalizeToLines($new));
    }

    /**
     * 增删统计
     *
     * @param array<int,array{op:string,text:string}> $ops
     * @return array{added:int,removed:int,unchanged:int}
     */
    public function stats(array $ops): array
    {
        $stats = ['added' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($ops as $op) {
            $type = $op['op'] ?? '';
            if ($type === 'ins') {
                $stats['added']++;
            } elseif ($type === 'del') {
                $stats['removed']++;
            } elseif ($type === 'equal') {
                $stats['unchanged']++;
            }
        }

        return $stats;
    }


    /**
     * 渲染为统一 diff HTML（长 equal 段折叠）
     *
     * 安全：所有文本经 htmlspecialchars(ENT_QUOTES) 转义，调用方可直接注入 DOM。
     *
     * @param array<int,array{op:string,text:string}> $ops
     */
    public function toHtml(array $ops, int $context = 3): string
    {
        $context = max(0, $context);
        $groups  = $this->groupOps($ops);
        $lastIdx = count($groups) - 1;

        $html   = '';
        $oldNo  = 1;
        $newNo  = 1;

        foreach ($groups as $idx => $group) {
            $lines = $group['lines'];
            $count = count($lines);

            if ($group['op'] === 'equal') {
                $foldable = $context > 0 && $count > $context * 2 + 1 && $idx > 0 && $idx < $lastIdx;

                if (!$foldable) {
                    foreach ($lines as $line) {
                        $html .= $this->rowHtml('equal', $line, $oldNo++, $newNo++);
                    }
                    continue;
                }

                foreach (array_slice($lines, 0, $context) as $line) {
                    $html .= $this->rowHtml('equal', $line, $oldNo++, $newNo++);
                }

                $skipped = $count - $context * 2;
                $html .= '<div class="diff-row diff-skip">… 省略 ' . $skipped . " 行 …</div>";
                $oldNo += $skipped;
                $newNo += $skipped;

                foreach (array_slice($lines, -$context) as $line) {
                    $html .= $this->rowHtml('equal', $line, $oldNo++, $newNo++);
                }
                continue;
            }

            if ($group['op'] === 'del') {
                foreach ($lines as $line) {
                    $html .= $this->rowHtml('del', $line, $oldNo++, null);
                }
                continue;
            }

            foreach ($lines as $line) {
                $html .= $this->rowHtml('ins', $line, null, $newNo++);
            }
        }

        return $html;
    }


    /**
     * 字段级差异摘要
     *
     * 不做任何字段过滤（跳过某字段属调用方业务规则）。
     *
     * @param array<string,mixed>  $old
     * @param array<string,mixed>  $new
     * @param array<string,string> $labels 字段中文标签（缺省用字段名）
     * @return array<int,array{field:string,label:string,old:string,new:string}>
     */
    public function diffFields(array $old, array $new, array $labels = []): array
    {
        $keys = [];
        foreach ([$old, $new] as $set) {
            foreach (array_keys($set) as $key) {
                $keys[(string) $key] = true;
            }
        }

        $result = [];
        foreach (array_keys($keys) as $key) {
            $before = $old[$key] ?? null;
            $after  = $new[$key] ?? null;

            if (json_encode($before) === json_encode($after)) {
                continue;
            }

            $result[] = [
                'field' => (string) $key,
                'label' => (string) ($labels[$key] ?? $key),
                'old'   => $this->stringify($before),
                'new'   => $this->stringify($after),
            ];
        }

        return $result;
    }


    /**
     * 是否降级为整块替换
     */
    protected function shouldDegrade(array $oldMid, array $newMid): bool
    {
        if ($this->maxLines > 0 && (count($oldMid) > $this->maxLines || count($newMid) > $this->maxLines)) {
            return true;
        }

        return count($oldMid) * count($newMid) > self::MAX_CELLS;
    }

    /**
     * LCS 动态规划 + 回溯，产出 equal/del/ins 序列
     *
     * @param string[] $old
     * @param string[] $new
     * @return array<int,array{op:string,text:string}>
     */
    protected function lcsOps(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);

        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return array_map(static fn (string $line) => ['op' => 'ins', 'text' => $line], $new);
        }
        if ($m === 0) {
            return array_map(static fn (string $line) => ['op' => 'del', 'text' => $line], $old);
        }

        $dp = [];
        for ($i = $n; $i >= 0; $i--) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $old[$i] === $new[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i   = 0;
        $j   = 0;
        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $ops[] = ['op' => 'equal', 'text' => $old[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['op' => 'del', 'text' => $old[$i]];
                $i++;
            } else {
                $ops[] = ['op' => 'ins', 'text' => $new[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['op' => 'del', 'text' => $old[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['op' => 'ins', 'text' => $new[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * 合并同类型连续行为块
     *
     * @return array<int,array{op:string,lines:string[]}>
     */
    protected function groupOps(array $ops): array
    {
        $groups = [];
        foreach ($ops as $op) {
            $type = (string) ($op['op'] ?? '');
            $text = (string) ($op['text'] ?? '');
            $last = count($groups) - 1;

            if ($last >= 0 && $groups[$last]['op'] === $type) {
                $groups[$last]['lines'][] = $text;
            } else {
                $groups[] = ['op' => $type, 'lines' => [$text]];
            }
        }

        return $groups;
    }

    /**
     * 单行 HTML（唯一的转义出口）
     */
    protected function rowHtml(string $op, string $line, ?int $oldNo, ?int $newNo): string
    {
        [$class, $prefix] = match ($op) {
            'del'   => ['diff-row diff-del', '-'],
            'ins'   => ['diff-row diff-ins', '+'],
            default => ['diff-row diff-equal', ' '],
        };

        return sprintf(
            '<div class="%s"><span class="diff-no">%s</span><span class="diff-no">%s</span>'
            . '<span class="diff-code">%s</span>%s</div>',
            $class,
            $oldNo === null ? '' : (string) $oldNo,
            $newNo === null ? '' : (string) $newNo,
            htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($line, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * 任意值 → 展示用字符串
     */
    protected function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
