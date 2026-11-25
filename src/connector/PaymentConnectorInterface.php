<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 支付连接器契约（领域子接口）
 *
 * 在通用 {@see ConnectorInterface}（发现 / 健康 / 配置 / 自描述 / 动态兜底）之上，
 * 为「支付网关」这一领域定义强类型业务方法，供业务代码 type-hint 使用。
 *
 * 分层约定：
 *   - 业务代码：经 {@see ConnectorManager::payment()} 拿到本接口，强类型调用；
 *   - 管理后台 / AI：只依赖 {@see ConnectorInterface} 的 getActions()/getConfigSchema()，
 *     不认识本接口。
 *
 * 注意：异步回调（notify）与验签涉及 HTTP 入口，不属于「主动调用」，应由插件路由
 * 单独处理；本契约仅覆盖下单 / 查询 / 退款等主动调用。
 *
 * 注册方式：
 * <pre>
 * ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', WechatPayConnector::class);
 * // 业务侧：app(ConnectorManager::class)->payment()->charge([...]);
 * </pre>
 *
 * @package wise\support\connector
 */
interface PaymentConnectorInterface extends ConnectorInterface
{
    /**
     * 发起支付（统一下单）
     *
     * @param array $order 订单参数，实现方约定键，建议包含：
     *   - out_trade_no: string 商户订单号（唯一）
     *   - amount:       int    金额（单位：分）
     *   - description:  string 商品描述
     *   - 其它渠道特有参数（openid / notify_url / attach / time_expire 等）
     * @return array 前端发起支付所需参数（如 prepay_id / pay_sign 等，渠道相关）
     * @throws \RuntimeException 下单失败时抛出
     */
    public function charge(array $order): array;

    /**
     * 查询订单
     *
     * @param string $outTradeNo 商户订单号
     * @return array 订单状态信息（渠道相关，至少应包含支付状态字段）
     * @throws \RuntimeException 查询失败时抛出
     */
    public function queryOrder(string $outTradeNo): array;

    /**
     * 申请退款
     *
     * @param string $outTradeNo 原商户订单号
     * @param int    $amount     退款金额（单位：分）
     * @param array  $options    可选参数（如 out_refund_no 商户退款单号、reason 退款原因、回调地址等）
     * @return array 退款结果（渠道相关）
     * @throws \RuntimeException 退款失败时抛出
     */
    public function refund(string $outTradeNo, int $amount, array $options = []): array;
}
