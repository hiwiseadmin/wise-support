<?php

declare(strict_types=1);

namespace wise\support\tools;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * 时间助手类, 提供常用的时间操作方法
 * Class TimeHelper
 * @package wise\support\tools
 */
class TimeHelper
{
    //常见的特殊日期格式
    private static $date_formats = [
        'Y-m-d',
        'm/d/Y',
        'd.m.Y',
        'm.d.y',
        'd/m/Y',
        'Y年m月d日',
        'Y年m月',
        'Y年m月d号',
        'Y/m/d',
        'Y.m.d',
        'Y.m',
        'F d, Y',
        'M d, Y',
        'F j, Y',
        'M j, Y',
        'F jS, Y',
        'M jS, Y'
    ];

    //常见的特殊时间格式
    private static $time_formats = ['H', 'H:i', 'H:i:s', 'H点', 'H点i分', 'H点i分s秒', 'H时', 'H时i分', 'H时i分s秒', 'g:i a', 'h:i a'];

    private $datetime;
    private $timestamp;

    /**
     * 构造函数
     * @param int|string|DateTime|null $datetime 时间字符串或时间戳或DateTime实例(默认为当前时间)
     */
    private function __construct($datetime = null)
    {
        if ($datetime === null) {
            $this->datetime = new DateTime();
            $this->timestamp = time();
        } elseif ($datetime instanceof DateTime) {
            $this->datetime = $datetime;
            $this->timestamp = $datetime->getTimestamp();
        } else {
            $this->timestamp = self::toTimestamp($datetime);
            $this->datetime = (new DateTime())->setTimestamp($this->timestamp);
        }
    }

    /**
     * 静态工厂方法：创建当前时间实例
     * @return self
     */
    public static function now(): self
    {
        return new self();
    }

    /**
     * 从任意时间创建实例
     * @param int|string|DateTime|null $datetime 时间字符串或时间戳或DateTime实例(默认为当前时间)
     * @return self
     */
    public static function parse($datetime): self
    {
        return new self($datetime);
    }

    /**
     * 判断是否为时间戳格式（静态方法）
     * @param int|string $timestamp 要判断的字符串
     * @return bool
     */
    public static function isTimestamp($timestamp): bool
    {
        $start = strtotime('1970-01-01 00:00:00');
        $end = strtotime('2099-12-31 23:59:59');
        return !empty($timestamp) && is_numeric($timestamp) && $timestamp <= $end && $timestamp >= $start;
    }

    /**
     * 将任意时间转换为时间戳（静态方法）
     * 请注意 m/d/y 或 d-m-y 格式的日期，如果分隔符是斜线（/），则使用美洲的 m/d/y 格式。如果分隔符是横杠（-）或者点（.），则使用欧洲的 d-m-y 格式。
     * 为了避免潜在的错误，您应该尽可能使用 YYYY-MM-DD 格式或者使用 date_create_from_format() 函数。
     * @param int|string|DateTime|null $datetime 时间字符串或时间戳或DateTime实例(默认为当前时间)
     * @return int
     */
    private static function toTimestamp($datetime = null): int
    {
        if (empty($datetime)) {
            return time();
        }

        if (self::isTimestamp($datetime)) {
            return intval($datetime);
        }

        $timestamp = strtotime($datetime);
        if ($timestamp) {
            return $timestamp;
        }

        // 尝试处理特殊的日期格式
        $parts = explode(' ', $datetime, 2);
        $date = $parts[0] ?? '';
        $time = $parts[1] ?? '';

        $time_format_str = '';
        if ($time) {
            foreach (self::$time_formats as $time_format) {
                if (date_create_from_format($time_format, $time) !== false) {
                    $time_format_str = $time_format;
                    break;
                }
            }
        }

        foreach (self::$date_formats as $date_format) {
            if (date_create_from_format($date_format, $date) !== false) {
                $datetime_format = $time_format_str ? "$date_format $time_format_str" : $date_format;
                $datetime_obj = date_create_from_format($datetime_format, $datetime);
                if ($datetime_obj !== false) {
                    return strtotime($datetime_obj->format('Y-m-d' . ($time_format_str ? ' H:i:s' : '')));
                }
            }
        }

        throw new InvalidArgumentException('Param datetime must be a timestamp or a string time');
    }

    /**
     * 获取时间戳
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * 获取DateTime对象
     * @return DateTime
     */
    public function getDateTime(): DateTime
    {
        return clone $this->datetime;
    }

    /**
     * 格式化为字符串
     * @param string $format 格式化字符串(默认为:Y-m-d H:i:s)
     * @return string
     */
    public function format(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->datetime->format($format);
    }

    /**
     * 转换为友好显示格式
     * @param string $lang 语言(zh/en) 默认为中文 样式为: 1天前, 1小时前, 1分钟前, 1秒前
     * @return string
     */
    public function toFriendly(string $lang = 'zh'): string
    {
        $interval = $this->datetime->diff(new DateTime());
        
        $count = 0;
        $type = '';

        if ($interval->y) {
            $count = $interval->y;
            $type = $lang == 'zh' ? '年' : ' year';
        } elseif ($interval->m) {
            $count = $interval->m;
            $type = $lang == 'zh' ? '月' : ' month';
        } elseif ($interval->d) {
            $count = $interval->d;
            $type = $lang == 'zh' ? '天' : ' day';
        } elseif ($interval->h) {
            $count = $interval->h;
            $type = $lang == 'zh' ? '小时' : ' hour';
        } elseif ($interval->i) {
            $count = $interval->i;
            $type = $lang == 'zh' ? '分钟' : ' minute';
        } elseif ($interval->s) {
            $count = $interval->s;
            $type = $lang == 'zh' ? '秒' : ' second';
        }

        if (empty($type)) {
            return $lang == 'zh' ? '刚刚' : 'just now';
        } else {
            return $count . $type . ($lang == 'zh' ? '前' : (($count > 1 ? 's' : '') . ' ago'));
        }
    }

    /**
     * 修改时间
     * @param string $modifier 修改字符串(如:+1 day, -2 hours)
     * @return self
     */
    public function modify(string $modifier): self
    {
        $newDateTime = clone $this->datetime;
        $newDateTime->modify($modifier);
        return new self($newDateTime);
    }

    /**
     * 增加分钟
     * @param int $minutes 分钟数(默认为1)
     * @return self
     */
    public function addMinutes(int $minutes = 1): self
    {
        return $this->modify("+{$minutes} minute");
    }

    /**
     * 减少分钟
     * @param int $minutes 分钟数(默认为1)
     * @return self
     */
    public function subMinutes(int $minutes = 1): self
    {
        return $this->modify("-{$minutes} minute");
    }

    /**
     * 增加小时
     * @param int $hours 小时数(默认为1)
     * @return self
     */
    public function addHours(int $hours = 1): self
    {
        return $this->modify("+{$hours} hour");
    }

    /**
     * 减少小时
     * @param int $hours 小时数(默认为1)
     * @return self
     */
    public function subHours(int $hours = 1): self
    {
        return $this->modify("-{$hours} hour");
    }

    /**
     * 增加天数
     * @param int $days 天数(默认为1)
     * @return self
     */
    public function addDays(int $days = 1): self
    {
        return $this->modify("+{$days} day");
    }

    /**
     * 减少天数
     * @param int $days 天数(默认为1)
     * @return self
     */
    public function subDays(int $days = 1): self
    {
        return $this->modify("-{$days} day");
    }

    /**
     * 增加周数
     * @param int $weeks 周数(默认为1)
     * @return self
     */
    public function addWeeks(int $weeks = 1): self
    {
        return $this->modify("+{$weeks} week");
    }

    /**
     * 减少周数
     * @param int $weeks 周数(默认为1)
     * @return self
     */
    public function subWeeks(int $weeks = 1): self
    {
        return $this->modify("-{$weeks} week");
    }

    /**
     * 增加月数
     * @param int $months 月数(默认为1)
     * @return self
     */
    public function addMonths(int $months = 1): self
    {
        return $this->modify("+{$months} month");
    }

    /**
     * 减少月数
     * @param int $months 月数(默认为1)
     * @return self
     */
    public function subMonths(int $months = 1): self
    {
        return $this->modify("-{$months} month");
    }

    /**
     * 增加年数
     * @param int $years 年数(默认为1)
     * @return self
     */
    public function addYears(int $years = 1): self
    {
        return $this->modify("+{$years} year");
    }

    /**
     * 减少年数
     * @param int $years 年数(默认为1)
     * @return self
     */
    public function subYears(int $years = 1): self
    {
        return $this->modify("-{$years} year");
    }

    /**
     * 判断是否为今天
     * @return bool
     */
    public function isToday(): bool
    {
        return $this->format('Y-m-d') === self::now()->format('Y-m-d');
    }

    /**
     * 判断是否为昨天
     * @return bool
     */
    public function isYesterday(): bool
    {
        return $this->format('Y-m-d') === self::now()->subDays(1)->format('Y-m-d');
    }

    /**
     * 判断是否为明天
     * @return bool
     */
    public function isTomorrow(): bool
    {
        return $this->format('Y-m-d') === self::now()->addDays(1)->format('Y-m-d');
    }

    /**
     * 判断是否为本周
     * @return bool
     */
    public function isThisWeek(): bool
    {
        $week_start = strtotime(date('Y-m-d 00:00:00', strtotime('this week')));
        $week_end = strtotime(date('Y-m-d 23:59:59', strtotime('last day next week')));
        return $this->timestamp >= $week_start && $this->timestamp <= $week_end;
    }

    /**
     * 判断是否为本月
     * @return bool
     */
    public function isThisMonth(): bool
    {
        return $this->format('Y-m') === self::now()->format('Y-m');
    }

    /**
     * 判断是否为今年
     * @return bool
     */
    public function isThisYear(): bool
    {
        return $this->format('Y') === self::now()->format('Y');
    }

    /**
     * 获取星期几
     * @return int 1-7
     */
    public function getWeekDay(): int
    {
        return (int)$this->format('N');
    }

    /**
     * 判断是否为工作日（周一到周五）
     * @return bool
     */
    public function isWeekday(): bool
    {
        return in_array($this->getWeekDay(), [1, 2, 3, 4, 5]);
    }

    /**
     * 判断是否为周末
     * @return bool
     */
    public function isWeekend(): bool
    {
        return in_array($this->getWeekDay(), [6, 7]);
    }

    /**
     * 获取年份
     * @return int
     */
    public function getYear(): int
    {
        return (int)$this->format('Y');
    }

    /**
     * 获取季度
     * @return int
     */
    public function getQuarter(): int
    {
        return (int)ceil($this->format('n') / 3);
    }

    /**
     * 获取月份
     * @return int
     */
    public function getMonth(): int
    {
        return (int)$this->format('m');
    }

    /**
     * 获取周数
     * @return int
     */
    public function getWeek(): int
    {
        return (int)$this->format('W');
    }

    /**
     * 获取天数
     * @return int
     */
    public function getDay(): int
    {
        return (int)$this->format('d');
    }

    /**
     * 获取小时
     * @return int
     */
    public function getHour(): int
    {
        return (int)$this->format('H');
    }

    /**
     * 获取分钟
     * @return int
     */
    public function getMinute(): int
    {
        return (int)$this->format('i');
    }

    /**
     * 获取秒数
     * @return int
     */
    public function getSecond(): int
    {
        return (int)$this->format('s');
    }

    /**
     * 判断是否为闰年
     * @return bool
     */
    public function isLeapYear(): bool
    {
        return $this->format('L') == 1;
    }

    /**
     * 获取该年天数
     * @return int
     */
    public function daysInYear(): int
    {
        return $this->isLeapYear() ? 366 : 365;
    }

    /**
     * 获取该月天数
     * @return int
     */
    public function daysInMonth(): int
    {
        return (int)$this->format('t');
    }

    /**
     * 时区转换
     * @param string $toTimezone 目标时区
     * @param string|null $fromTimezone 原时区
     * @param string $format 格式
     * @return string
     * @throws Exception
     */
    public function timezoneFormat(string $toTimezone, ?string $fromTimezone = null, string $format = 'Y-m-d H:i:s'): string
    {
        $date = clone $this->datetime;
        
        if ($fromTimezone !== null) {
            $date->setTimezone(new DateTimeZone($fromTimezone));
        }
        
        $date->setTimezone(new DateTimeZone($toTimezone));
        return $date->format($format);
    }

    /**
     * 比较时间
     * @param self|int|string $other 另一个时间
     * @return int 1:大于, -1:小于, 0:等于
     */
    public function compare($other): int
    {
        if (!$other instanceof self) {
            $other = self::parse($other);
        }
        
        if ($this->timestamp > $other->timestamp) {
            return 1;
        } elseif ($this->timestamp < $other->timestamp) {
            return -1;
        } else {
            return 0;
        }
    }

    /**
     * 计算与另一个时间的差值（秒）
     * @param self|int|string $other 另一个时间
     * @return int
     */
    public function diffSeconds($other): int
    {
        if (!$other instanceof self) {
            $other = self::parse($other);
        }
        
        return abs($other->timestamp - $this->timestamp);
    }

    /**
     * 计算与另一个时间的差值（天）
     * @param self|int|string $other 另一个时间
     * @return int
     */
    public function diffDays($other): int
    {
        if (!$other instanceof self) {
            $other = self::parse($other);
        }
        
        $interval = $this->datetime->diff($other->datetime);
        return $interval->days;
    }

    /**
     * 获取年份的第一天（1月1日）
     * @param bool $startOfDay 是否将时间设置为当天的开始（00:00:00）
     * @return self
     */
    public function startOfYear(bool $startOfDay = true): self
    {
        $year = $this->getYear();
        $datetimeStr = sprintf('%d-01-01', $year);
        
        if ($startOfDay) {
            $datetimeStr .= ' 00:00:00';
        }
        
        return self::parse($datetimeStr);
    }

    /**
     * 获取年份的最后一天（12月31日）
     * @param bool $endOfDay 是否将时间设置为当天的结束（23:59:59）
     * @return self
     */
    public function endOfYear(bool $endOfDay = true): self
    {
        $year = $this->getYear();
        $datetimeStr = sprintf('%d-12-31', $year);
        
        if ($endOfDay) {
            $datetimeStr .= ' 23:59:59';
        }
        
        return self::parse($datetimeStr);
    }

    /**
     * 获取季度的第一天
     * @param bool $startOfDay 是否将时间设置为当天的开始（00:00:00）
     * @return self
     */
    public function startOfQuarter(bool $startOfDay = true): self
    {
        $year = $this->getYear();
        $quarter = $this->getQuarter();
        
        // 计算季度开始的月份
        $startMonth = ($quarter - 1) * 3 + 1;
        
        $datetimeStr = sprintf('%d-%02d-01', $year, $startMonth);
        
        if ($startOfDay) {
            $datetimeStr .= ' 00:00:00';
        }
        
        return self::parse($datetimeStr);
    }

    /**
     * 获取季度的最后一天
     * @param bool $endOfDay 是否将时间设置为当天的结束（23:59:59）
     * @return self
     */
    public function endOfQuarter(bool $endOfDay = true): self
    {
        $year = $this->getYear();
        $quarter = $this->getQuarter();
        
        // 计算季度结束的月份
        $endMonth = $quarter * 3;
        
        // 获取该月的最后一天
        $lastDay = date('t', strtotime(sprintf('%d-%02d-01', $year, $endMonth)));
        
        $datetimeStr = sprintf('%d-%02d-%02d', $year, $endMonth, $lastDay);
        
        if ($endOfDay) {
            $datetimeStr .= ' 23:59:59';
        }
        
        return self::parse($datetimeStr);
    }

    /**
     * 获取月份的第一天
     * @param bool $startOfDay 是否将时间设置为当天的开始（00:00:00）
     * @return self
     */
    public function startOfMonth(bool $startOfDay = true): self
    {
        $year = $this->getYear();
        $month = $this->getMonth();
        
        $datetimeStr = sprintf('%d-%02d-01', $year, $month);
        
        if ($startOfDay) {
            $datetimeStr .= ' 00:00:00';
        }
        
        return self::parse($datetimeStr);
    }

    /**
     * 获取月份的最后一天
     * @param bool $endOfDay 是否将时间设置为当天的结束（23:59:59）
     * @return self
     */
    public function endOfMonth(bool $endOfDay = true): self
    {
        $year = $this->getYear();
        $month = $this->getMonth();
        
        // 获取该月的最后一天
        $lastDay = $this->daysInMonth();
        
        $datetimeStr = sprintf('%d-%02d-%02d', $year, $month, $lastDay);
        
        if ($endOfDay) {
            $datetimeStr .= ' 23:59:59';
        }
        
        return self::parse($datetimeStr);
    }

    /**
     * 获取周的第一天（周一）
     * @param bool $startOfDay 是否将时间设置为当天的开始（00:00:00）
     * @return self
     */
    public function startOfWeek(bool $startOfDay = true): self
    {
        $weekDay = $this->getWeekDay(); // 1-7，1=周一
        
        // 计算到周一的偏移量（如果是周一，偏移0天）
        $offset = $weekDay - 1;
        
        $datetime = clone $this->datetime;
        if ($offset > 0) {
            $datetime->modify(sprintf('-%d day', $offset));
        }
        
        if ($startOfDay) {
            $datetime->setTime(0, 0, 0);
        }
        
        return new self($datetime);
    }

    /**
     * 获取周的最后一天（周日）
     * @param bool $endOfDay 是否将时间设置为当天的结束（23:59:59）
     * @return self
     */
    public function endOfWeek(bool $endOfDay = true): self
    {
        $weekDay = $this->getWeekDay(); // 1-7，1=周一
        
        // 计算到周日的偏移量（如果是周日，偏移0天）
        $offset = 7 - $weekDay;
        
        $datetime = clone $this->datetime;
        if ($offset > 0) {
            $datetime->modify(sprintf('+%d day', $offset));
        }
        
        if ($endOfDay) {
            $datetime->setTime(23, 59, 59);
        }
        
        return new self($datetime);
    }

    /**
     * 获取当天的开始时间（00:00:00）
     * @return self
     */
    public function startOfDay(): self
    {
        $datetime = clone $this->datetime;
        $datetime->setTime(0, 0, 0);
        return new self($datetime);
    }

    /**
     * 获取当天的结束时间（23:59:59）
     * @return self
     */
    public function endOfDay(): self
    {
        $datetime = clone $this->datetime;
        $datetime->setTime(23, 59, 59);
        return new self($datetime);
    }

    /**
     * 获取当天的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function dayRange(): array
    {
        return [
            'start' => $this->startOfDay(),
            'end' => $this->endOfDay()
        ];
    }

    /**
     * 获取当周的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function weekRange(): array
    {
        return [
            'start' => $this->startOfWeek(),
            'end' => $this->endOfWeek()
        ];
    }

    /**
     * 获取当月的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function monthRange(): array
    {
        return [
            'start' => $this->startOfMonth(),
            'end' => $this->endOfMonth()
        ];
    }

    /**
     * 获取当季度的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function quarterRange(): array
    {
        return [
            'start' => $this->startOfQuarter(),
            'end' => $this->endOfQuarter()
        ];
    }

    /**
     * 获取当年的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function yearRange(): array
    {
        return [
            'start' => $this->startOfYear(),
            'end' => $this->endOfYear()
        ];
    }

    /**
     * 获取上一周的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function lastWeekRange(): array
    {
        $lastWeek = $this->subWeeks(1);
        return $lastWeek->weekRange();
    }

    /**
     * 获取下一周的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function nextWeekRange(): array
    {
        $nextWeek = $this->addWeeks(1);
        return $nextWeek->weekRange();
    }

    /**
     * 获取上一个月的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function lastMonthRange(): array
    {
        $lastMonth = $this->subMonths(1);
        return $lastMonth->monthRange();
    }

    /**
     * 获取下一个月的开始和结束时间
     * @return array ['start' => self, 'end' => self]
     */
    public function nextMonthRange(): array
    {
        $nextMonth = $this->addMonths(1);
        return $nextMonth->monthRange();
    }

    /**
     * 获取指定周的开始和结束时间
     * @param int $weekNumber 周数（1-53）
     * @param int|null $year 年份，null表示当前年份
     * @return array ['start' => self, 'end' => self]
     */
    public static function weekRangeByNumber(int $weekNumber, ?int $year = null): array
    {
        $year = $year ?? date('Y');
        
        // 创建指定年份的第一天
        $date = new DateTime();
        $date->setDate($year, 1, 1);
        
        // 获取第一天是星期几（1=周一, 7=周日）
        $firstDayOfWeek = (int)$date->format('N');
        
        // 计算第一周的偏移量
        $offset = $firstDayOfWeek <= 4 ? 1 - $firstDayOfWeek : 8 - $firstDayOfWeek;
        $date->modify("{$offset} days");
        
        // 计算指定周的日期
        $date->modify("+" . ($weekNumber - 1) . " weeks");
        
        $instance = new self($date);
        return $instance->weekRange();
    }

    /**
     * 获取指定月份的开始和结束时间
     * @param int $month 月份（1-12）
     * @param int|null $year 年份，null表示当前年份
     * @return array ['start' => self, 'end' => self]
     */
    public static function monthRangeByNumber(int $month, ?int $year = null): array
    {
        $year = $year ?? date('Y');
        $instance = self::parse(sprintf('%d-%02d-01', $year, $month));
        return $instance->monthRange();
    }

    /**
     * 获取指定季度的开始和结束时间
     * @param int $quarter 季度（1-4）
     * @param int|null $year 年份，null表示当前年份
     * @return array ['start' => self, 'end' => self]
     */
    public static function quarterRangeByNumber(int $quarter, ?int $year = null): array
    {
        $year = $year ?? date('Y');
        $instance = self::parse(sprintf('%d-%02d-01', $year, ($quarter - 1) * 3 + 1));
        return $instance->quarterRange();
    }

    /**
     * 静态工具方法：获取分钟秒数
     */
    public static function secondMinute(int $minutes = 1): int
    {
        return 60 * $minutes;
    }

    /**
     * 静态工具方法：获取小时秒数
     */
    public static function secondHour(int $hours = 1): int
    {
        return 3600 * $hours;
    }

    /**
     * 静态工具方法：获取天秒数
     */
    public static function secondDay(int $days = 1): int
    {
        return 86400 * $days;
    }

    /**
     * 静态工具方法：获取周秒数
     */
    public static function secondWeek(int $weeks = 1): int
    {
        return 604800 * $weeks;
    }
}