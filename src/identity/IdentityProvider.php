<?php
declare(strict_types=1);

namespace wise\support\identity;

/**
 * 玩家身份提供方契约（防沉迷身份）
 *
 * 面向防沉迷规则引擎（如 wise-anti-addiction）定义统一的「身份查询」能力。
 * 各平台（微信 / QQ 小游戏 / 中宣部实名系统）通过 ExtPoint('anti_addiction.identity')
 * 注册自己的实现，规则引擎只依赖本接口，不感知具体平台。
 *
 * 设计要点：
 *  - 平台无关：所有方法只以 openid 为入参，由实现方负责解析真实身份；
 *  - 年龄档位（getAgeTier）只做粗粒度分类，付费档位等细分规则由消费方按 age 计算；
 *  - 契约定义于 wise-support（中性内核），连接器包（wise-wx）实现它，规则引擎消费它，
 *    避免「规则引擎 ↔ 连接器」之间的反向依赖。
 *
 * 注册示例（wise-wx 内）：
 * <pre>
 * ExtPoint::definePoint(ExtPoints::ANTI_ADDICTION_IDENTITY, ExtPoint::POLICY_ROUTED, IdentityProvider::class);
 * ExtPoint::register(ExtPoints::ANTI_ADDICTION_IDENTITY, 'wechat', IdentityService::class);
 * </pre>
 *
 * 消费示例（规则引擎内）：
 * <pre>
 * $concrete = ExtPoint::get(ExtPoints::ANTI_ADDICTION_IDENTITY, 'wechat');
 * $provider = app()->make($concrete); // IdentityProvider
 * $tier = $provider->getAgeTier($openid);
 * </pre>
 *
 * @package wise\support\identity
 */
interface IdentityProvider
{
    // ============================================================
    // 年龄档位常量
    // ============================================================

    /** 成年：18 岁及以上 */
    public const TIER_ADULT = 'adult';

    /** 未成年：8–17 岁 */
    public const TIER_TEEN = 'teen';

    /** 儿童：8 岁以下（禁止付费） */
    public const TIER_CHILD = 'child';

    /** 游客：未实名 */
    public const TIER_GUEST = 'guest';

    /** 未知：无法判定 */
    public const TIER_UNKNOWN = 'unknown';

    // ============================================================
    // 实名状态常量
    // ============================================================

    /** 已实名（已通过实名认证） */
    public const STATUS_VERIFIED = 'verified';

    /** 未实名（游客模式） */
    public const STATUS_GUEST = 'guest';

    /** 未知（尚未查询到 / 平台未返回） */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * 获取玩家身份状态
     *
     * @param string $openid 平台用户唯一标识
     * @return array{
     *     status: string,        // 实名状态，见 STATUS_* 常量
     *     age: int|null,         // 年龄（已知时），未知为 null
     *     real_name: bool,       // 是否完成实名认证
     *     verified_at: string|null, // 实名完成时间（已知时）
     *     raw: array             // 平台原始返回（用于调试/扩展）
     * }
     */
    public function getIdentityState(string $openid): array;

    /**
     * 获取年龄档位（粗粒度分类）
     *
     * @param string $openid 平台用户唯一标识
     * @return string 见 TIER_* 常量
     */
    public function getAgeTier(string $openid): string;

    /**
     * 是否建议休息（对接平台健康系统「该休息了」信号）
     *
     * @param string $openid 平台用户唯一标识
     * @return bool true=建议休息（应弹提示/限制）
     */
    public function isAdvisedToRest(string $openid): bool;
}
