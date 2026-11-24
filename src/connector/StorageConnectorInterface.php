<?php
declare(strict_types=1);

namespace wise\support\connector;

use wise\support\contracts\StorageDriverInterface;

/**
 * 存储连接器契约（领域子接口）
 *
 * 将既有的「通用对象存储能力」契约 {@see StorageDriverInterface}（上传/下载/列举/可见性等
 * 18 个强类型方法）与连接器通用契约 {@see ConnectorInterface}（发现/健康/配置/自描述/动态兜底）
 * 组合到一起，使存储驱动既能被 {@see ConnectorManager} 统一发现、健康检查、配置，
 * 又能保留强类型的存储业务方法。
 *
 * 为什么不是「只有 execute 弱类型」：
 *   存储能力天然是强类型的（18 个方法），若退化为 execute('upload', [...])，
 *   业务代码将失去 IDE 补全与静态检查，故本接口保留全部强类型方法；
 *   execute() 只作为「动态分发兜底」，其 action 路由到对应强类型方法（见 StorageConnectorTrait）。
 *
 * 实现方：
 *   - 可 extends {@see \wise\support\connector\StorageConnectorTrait} 复用 ConnectorInterface 默认实现；
 *   - 需实现 StorageDriverInterface 的 18 个存储方法。
 *
 * 兼容性：
 *   本接口 extends StorageDriverInterface，故既有 `instanceof StorageDriverInterface` 断言
 *   仍然成立，老代码无需改动。
 *
 * @package wise\support\connector
 */
interface StorageConnectorInterface extends ConnectorInterface, StorageDriverInterface
{
}
