<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 标准 Composer 包「一次性装/卸副作用」契约（**可选实现**）
 *
 * ## 为什么需要
 *
 * 包只有两种代码执行时机，而现有机制只覆盖了第一种：
 *
 * | 时机 | 机制 | 约束 |
 * |---|---|---|
 * | 每次请求 | `ServiceProvider::register()/boot()` | 必须幂等、必须快 |
 * | **装/卸时一次** | **本接口** | 由 `wise:package:install` / `wise:package:uninstall` 显式调用 |
 *
 * 依赖包 `composer.json` 里的 `scripts` **不会被执行**（Composer 只跑根包 scripts），
 * 因此「安装时注册外部 webhook」「卸载时释放第三方凭据」这类动作没有别的合法落点。
 *
 * ## 典型用途
 *
 * - 安装：向外部系统注册回调地址、申领 / 校验 license、写入外部索引、一次性数据搬迁；
 * - 卸载：注销 webhook、释放凭据、删除外部资源。
 *
 * ## 实现位置与发现方式
 *
 * 由包的 **ServiceProvider**（`extra.think.services` 里声明的类）实现本接口即可，
 * 宿主通过反射发现，**不需要任何新的 composer 字段**：
 *
 * ```php
 * class CmsServiceProvider extends Service implements PackageInstallable
 * {
 *     public function install(): void   { /* 幂等：重复执行不产生副作用 *\/ }
 *     public function uninstall(): void { /* 只清外部痕迹，不删本地数据 *\/ }
 * }
 * ```
 *
 * ## 约定（必须遵守）
 *
 * 1. **幂等**：`install()` 可能被重复调用（`--all` 重跑、部署脚本重试），不得重复申请资源；
 * 2. **只做外部/一次性动作**：建表与初始数据请用 `database/migrations` 与 `database/seeds`；
 * 3. **不抛「环境不可用」之外的无意义异常**：抛出即视为该包安装失败，命令退出码非 0；
 * 4. **不做自动回滚**：迁移已落库，宿主不会撤销；`install()` 自己负责补偿。
 *
 * @see \app\common\service\PackageLifecycle
 * @see \wise\command\package\PackageInstall
 * @see \wise\command\package\PackageUninstall
 */
interface PackageInstallable
{
    /**
     * 安装：在**迁移与 seeds 之后、菜单同步之前**调用
     *
     * @return void
     */
    public function install(): void;

    /**
     * 卸载：在**包文件被删除之前**调用（`composer remove` 的 `pre-package-uninstall` 阶段）
     *
     * 注意：此时框架数据（菜单/权限/配置/表）尚未清理，那是 `wise:package:cleanup` 的职责；
     * 本方法只负责「包外的世界」。
     *
     * @return void
     */
    public function uninstall(): void;
}
