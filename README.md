# Wise-Support — WiseAdmin 基础设施层

> 为 WiseAdmin 生态提供统一的「扩展注册中心 / 统一认证 / 服务总线 / 分布式锁 / 断路器 / 限流 / 配置检查 / 请求上下文 / 加解密 / WebSocket / 错误码体系 / 门面」基础设施
> 是全体 `wise-*` 业务包的公共基座，与具体业务解耦、可独立复用
> 版本：v1.0 | PHP >= 8.1 | ThinkPHP ^6.0 || ^8.0

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--support-orange)](https://packagist.org/packages/wiseadmin/wise-support)

---

## 目录

- [概述](#概述)
- [核心能力 / 特性](#核心能力--特性)
- [设计动机与概念](#设计动机与概念)
- [架构设计](#架构设计)
- [安装](#安装)
- [快速上手](#快速上手)
  - [统一认证（AuthManager + Guard）](#统一认证authmanager--guard)
  - [JWT 统一门面](#jwt-统一门面)
  - [扩展点注册中心（ExtPoint）](#扩展点注册中心extpoint)
  - [服务总线（ServiceBus）](#服务总线servicebus)
  - [分布式锁（DistributedLock）](#分布式锁distributedlock)
  - [断路器（CircuitBreaker）](#断路器circuitbreaker)
  - [限流（RateLimiter / SlidingWindow / TokenBucket）](#限流ratelimiter--slidingwindow--tokenbucket)
  - [重试与退避（retry / BackoffStrategy）](#重试与退避retry--backoffstrategy)
  - [管道（Pipeline）与请求级缓存（Once）](#管道pipeline与请求级缓存once)
  - [请求上下文与链路日志（RequestContext / ContextualLogger）](#请求上下文与链路日志requestcontext--contextuallogger)
  - [WebSocket 基础设施](#websocket-基础设施)
  - [连接器（Connector）](#连接器connector)
  - [配置检查（ConfigCheck）](#配置检查configcheck)
  - [异常与错误码（WiseException）](#异常与错误码wiseexception)
  - [.env 密钥播种（EnvSeedService）](#env-密钥播种envseedservice)
  - [辅助函数](#辅助函数)
- [API / 方法参考](#api--方法参考)
- [事件 / 扩展点](#事件--扩展点)
- [与其他 wise-* 包协作](#与其他-wise--包协作)
- [目录结构](#目录结构)
- [依赖关系](#依赖关系)
- [开发与测试](#开发与测试)
- [常见问题](#常见问题)
- [许可证](#许可证)

---

## 概述

**Wise-Support** 是 WiseAdmin 生态中**不依赖任何业务包**的基础设施库，沉淀了跨业务通用的能力，供全体 `wise-*` 业务包复用。它通过 **ExtPoint 扩展注册中心** 实现「能力注册 — 发现 — 调度」的松耦合机制，业务包只需把自己实现的能力注册进来，`wise-support` 与业务包之间互不直接依赖。

### 设计理念

| 设计原则 | 说明 |
|---------|------|
| **零业务依赖** | 仅依赖 `topthink/framework`，可被任何 wise-* 包或独立项目复用 |
| **可替换实现** | 通过 `ExtPoint` 定义单一契约，业务包注册具体实现（如 JWT 引擎、WebSocket 服务端） |
| **生产就绪** | 提供分布式锁、断路器、限流、重试退避等高可用组件，且均支持 Redis/文件/数据库多驱动与降级 |
| **可观测** | 请求上下文 + 链路日志 + 配置检查，贯穿请求生命周期 |
| **统一错误** | `WiseException` + `GeneralErrorCode` 为全体包提供一致的错误码与 JSON 渲染规范 |

---

## 核心能力 / 特性

- **ExtPoint 扩展注册中心（SPI Registry）**：`ExtPoint::definePoint / register / resolve / active / all`，支持 `POLICY_SINGLE`（独占）、`POLICY_MULTI`（共享）、`POLICY_ROUTED`（按名路由）三种调度策略，是各包解耦的核心机制。
- **统一认证（AuthManager + Guard）**：经 `ExtPoint('auth.guard')` 发现并管理多个 Guard（admin / api / member / 自定义），`user()` / `id()` / `check()` / `guest()` / `authenticate()` 统一入口；内置 `SessionGuard`（后台 Session）与 `TokenGuard`（JWT Bearer 解析）。
- **JWT 引擎代理（JwtManager）**：统一代理 `ExtPoint('jwt.provider')` 发现的当前活跃 JWT 引擎（默认由 `wise-jwt` 包注册），通过 `JWT` 门面一行签发/解析/续签/吊销。
- **ServiceBus 服务总线**：跨插件统一通信，支持请求-响应（`ask`）、发布-订阅（`publish`）、广播（`broadcast`）三种模式，可经 think-queue 异步分发。
- **Connector 连接器**：统一抽象 OAuth / Webhook / API / 消息推送 / 支付 / 云存储 / AI / IoT 等所有外部系统交互，支持按名查找、按类型选活跃、健康检查与配置 Schema。
- **高可用组件**：
  - `DistributedLock` 分布式锁 —— Redis / File / Database 三驱动，Token 所有权 + Lua 原子释放 + TTL 兜底 + 阻塞/非阻塞双模式；
  - `CircuitBreaker` 断路器 —— CLOSED → OPEN → HALF_OPEN 三态，状态存 Redis 支持分布式；
  - `BackoffStrategy` 退避策略 —— 线性 / 指数 / 固定 / 自定义四种延迟计算；
  - `RateLimiter` + `SlidingWindow` + `TokenBucket` —— 通用限流、滑动窗口、令牌桶三种算法，Redis 原子 / 文件降级 / fail-open。
- **重试与缓存原语**：`retry()` 失败重试（指数退避 + 条件过滤）、`once()` 请求级闭包缓存（按 call-site memoize）。
- **可观测与上下文**：`RequestContext` 请求级上下文容器（request_id / user / guard / tenant / locale）、`ContextualLogger` 自动注入链路上下文日志、`ConfigCheckCollector` 配置检查（冷却防刷屏）。
- **安全与通信**：`AesGcm` AES-256-GCM 加解密、WebSocket 基础设施（连接管理 / 消息路由 / 频道 / 心跳）、`Pipeline` 洋葱模型管道。
- **错误码体系**：`WiseException` 统一异常基类（链式构建 + 上下文）、`GeneralErrorCode` 框架级通用错误码、`DefaultExceptionRenderer` 默认渲染器、`JsonResponse` / `ErrorHandler` Trait。
- **配置驱动路由**：`RouteRegistrar` 抽象基类，将包路由前缀、中间件、限流收敛到 `config/<pkg>.php` 的 `web` 节统一管理。
- **.env 密钥播种**：`EnvSeedService` + `EnvValueGenerator` + `EnvSeedProvider` 契约，安装期幂等合并密钥到 `.env`，杜绝洗掉用户已生效配置。
- **通用 Trait**：`ServiceTrait`（配置合并）、`RegistersMigrations`（迁移路径注册）、`EventTrait`（事件调度）、`Conditionable` / `Tappable`（链式编程）。
- **Facade 门面**：`Auth`、`Context`、`JWT` 提供静态快捷访问。

---

## 设计动机与概念

### 为什么要统一到 ExtPoint？

在引入 `ExtPoint` 之前，WiseAdmin 生态中多种扩展机制各自为政（payment driver、oauth driver、notification channel 等散落各处）。`ExtPoint` 将所有**可替换实现**的扩展点收敛到单一注册中心：

| 概念 | 说明 |
|------|------|
| **扩展点（Point）** | 一个能力类别，如 `'jwt.provider'`、`'auth.guard'`、`'connector'` |
| **实现（Implementation）** | 扩展点下的一个具体注册项（类名或工厂闭包 + 优先级） |
| **调度策略（Policy）** | 多个实现时的冲突处理规则：`single` / `multi` / `routed` |
| **点元数据（Meta）** | 展示名 / 描述 / 归属模块等，由点的归属方自声明（`definePoint(..., $meta)` 或 `describePoint()`），供能力市场自动发现，新增包无需改动框架 |

`ExtPoints` 类集中定义了所有框架级扩展点的常量，避免字符串硬编码。

### 认证的多 Guard 模型

`AuthManager` 通过 `ExtPoint('auth.guard', POLICY_ROUTED)` 按 Guard 名称精确发现实现。默认回退逻辑：
- `admin` → `SessionGuard`（从 Session 读取 `admin_id`）
- `api` / `member` → `TokenGuard`（从 `Authorization: Bearer` 解析 JWT）

业务包可注册自定义 Guard 以覆盖默认行为。

### ServiceBus 与 Event 的分工

| 机制 | 用途 |
|------|------|
| `ServiceBus::publish()` | 有名称的分发，处理器通过 `bus.handler.{action}` 明确注册 |
| `ServiceBus::broadcast()` | 无回复的分发 |
| `Event::trigger()` | 框架内部生命周期事件 |

---

## 架构设计

### 分层结构

```
┌───────────────────────────────────────────────────────┐
│                   业务层（wise-* 业务包）               │
│  wise-game-* / wise-api / wise-jwt / wise-auth ...    │
│  通过 ExtPoint 注册 Guard / JWT 引擎 / WS 服务端       │
├───────────────────────────────────────────────────────┤
│              基础设施层（wise-support）                │
│  ExtPoint 注册中心 │ Auth+JWT │ ServiceBus │ Connector│
│  CircuitBreaker │ DistributedLock │ RateLimiter       │
│  ConfigCheck │ RequestContext │ Pipeline │ WebSocket   │
│  WiseException │ 门面 │ traits │ .env 播种            │
└───────────────────────────────────────────────────────┘
```

### 请求生命周期中的上下文流

```
Request
  → 全局中间件 RequestContext::setRequestId()        # 注入 request_id（同时开启新请求世代）
  → AuthManager::user() 解析用户后 setUser()/setGuard()
  → 各 wise-* 包通过 Context 门面 / ContextualLogger 读取
  → 请求结束 RequestContext::clear()                  # 防常驻进程泄漏
```

> `setRequestId()` 同时充当**请求世代**标记：非协程常驻进程共用同一个上下文桶，世代变化时
> 自动丢弃上一个请求写入的 `user` / `guard` / `tenant_id` 等数据；`AuthManager` 的 Guard 实例
> 缓存也按同一世代失效，因此常驻运行时不会复用上一个请求的身份。

### 目录结构

```
vendor/wiseadmin/wise-support/
├── composer.json                           # 包定义（PSR-4: wise\support\ → src/）
├── src/
│   ├── SupportServiceProvider.php          # 服务提供者（容器绑定 + 扩展点声明 + 内置实现注册）
│   ├── ExtPoint.php                        # 扩展点注册中心（SPI Registry）
│   ├── ExtPoints.php                       # 框架标准扩展点常量
│   ├── Pipeline.php                        # 洋葱模型管道
│   ├── Once.php                            # 请求级闭包缓存（call-site memoize）
│   ├── helper.php                          # 全局辅助函数（str_pipeline / rate_limiter / retry 等）
│   ├── auth/
│   │   ├── AuthManager.php                 # 统一认证管理器
│   │   ├── GuardInterface.php              # 认证守卫契约
│   │   ├── SessionGuard.php                # Session 守卫
│   │   └── TokenGuard.php                  # JWT Token 守卫
│   ├── jwt/
│   │   └── JwtManager.php                  # JWT 引擎代理（经 ExtPoint 发现引擎）
│   ├── bus/
│   │   ├── ServiceBus.php                  # 插件服务总线
│   │   ├── Message.php                     # 总线消息（request/event/reply）
│   │   └── BusHandlerJob.php               # think-queue 异步作业
│   ├── connector/
│   │   ├── ConnectorManager.php            # 连接器管理器（含 payment()/sms()/storage() 强类型入口）
│   │   ├── ConnectorInterface.php          # 连接器通用契约
│   │   ├── AbstractConnector.php           # 连接器抽象基类（通用默认实现）
│   │   ├── ConnectorResult.php             # 连接器执行结果
│   │   ├── ConnectorType.php               # 连接器类型枚举
│   │   ├── PaymentConnectorInterface.php   # 支付领域子接口
│   │   ├── SmsConnectorInterface.php       # 短信领域子接口
│   │   ├── StorageConnectorInterface.php   # 存储领域子接口
│   │   └── StorageConnectorTrait.php       # 存储连接器默认实现 Trait
│   ├── circuit/
│   │   ├── CircuitBreaker.php              # 断路器
│   │   └── CircuitBreakerExceptionInterface.php
│   ├── lock/
│   │   ├── DistributedLock.php             # 分布式锁（Redis/File/DB）
│   │   └── LockInterface.php               # 锁契约
│   ├── rate/
│   │   ├── RateLimiter.php                 # 通用限流器
│   │   ├── RateLimitInterface.php          # 限流接口
│   │   ├── SlidingWindow.php               # 滑动窗口算法
│   │   ├── TokenBucket.php                 # 令牌桶算法
│   │   ├── AtomicCounter.php               # 原子递增计数器
│   │   └── traits/RedisHelper.php          # Redis 连接获取 trait
│   ├── backoff/
│   │   └── BackoffStrategy.php             # 退避策略
│   ├── crypto/
│   │   └── AesGcm.php                      # AES-256-GCM 加解密
│   ├── config/
│   │   ├── ConfigCheckCollector.php        # 配置检查收集器
│   │   ├── ConfigCheckInterface.php        # 配置检查契约
│   │   ├── CheckLevel.php                  # 检查严重级别枚举
│   │   └── CheckResult.php                 # 检查结果值对象
│   ├── context/
│   │   ├── RequestContext.php              # 请求级上下文容器
│   │   └── ContextualLogger.php            # 上下文感知日志记录器
│   ├── env/
│   │   ├── EnvSeedService.php              # .env 密钥播种引擎
│   │   └── EnvValueGenerator.php           # .env 值生成器
│   ├── contracts/
│   │   └── EnvSeedProvider.php             # 包级 .env 播种契约
│   ├── event/
│   │   └── WiseEvents.php                  # 事件常量（预留）
│   ├── exception/
│   │   ├── WiseException.php               # 统一异常基类
│   │   ├── GeneralErrorCode.php            # 框架级通用错误码
│   │   ├── ErrorCodeInterface.php          # 错误码枚举契约
│   │   ├── ExceptionRendererInterface.php  # 异常渲染器接口
│   │   └── DefaultExceptionRenderer.php    # 默认 JSON 渲染器
│   ├── router/
│   │   └── RouteRegistrar.php              # 配置驱动路由注册基类
│   ├── ws/
│   │   ├── ConnectionManager.php           # WebSocket 连接管理器
│   │   ├── MessageRouter.php               # WebSocket 消息路由器
│   │   ├── Connection.php                  # 连接实体
│   │   ├── WsServerInterface.php           # WS 服务端接口
│   │   ├── ChannelInterface.php            # 频道接口
│   │   └── handler/PingPongHeartbeat.php   # PING/PONG 心跳
│   ├── facade/
│   │   ├── Auth.php                        # Auth 门面
│   │   ├── Context.php                     # Context 门面
│   │   └── JWT.php                         # JWT 门面
│   └── traits/
│       ├── ServiceTrait.php                # 包 Service 配置合并
│       ├── RegistersMigrations.php         # 迁移路径注册
│       ├── EventTrait.php                  # 事件调度
│       ├── JsonResponse.php                # 统一 JSON 响应
│       ├── ErrorHandler.php                # 错误处理
│       ├── Conditionable.php               # 条件链式调用
│       └── Tappable.php                    # tap() 旁观链式调用
└── tests/                                  # 单元测试（PHPUnit 9）
```

---

## 安装

### 前置条件

- PHP >= 8.1
- ThinkPHP ^6.0 || ^8.0
- `topthink/framework`

### 通过 Composer 安装

```bash
composer require wiseadmin/wise-support
```

`wise-support` 通过 Composer 的 `extra.think.services` 自动注册 `SupportServiceProvider`，无需手动配置。

> 本包**不包含 `config/` 目录、无 `migrate-path`**，因此 **无需发布配置、无需执行迁移**，安装后即开箱即用。`SupportServiceProvider` 自动完成以下绑定：
>
> - `ConfigCheckCollector` 单例
> - `AuthManager` 单例 + `'auth'` 容器别名
> - `'jwt'` → `JwtManager` 代理
> - `ConnectionManager` 单例
> - `MessageRouter` 单例（注入 ConnectionManager）
> - 声明标准扩展点（`auth.guard` / `jwt.provider` / `ws.server` / `exception.renderer` / `log.driver` / `ws.handler` / `ws.heartbeat`）
> - 注册内置实现（`PingPongHeartbeat`、`DefaultExceptionRenderer`）

---

## 快速上手

### 统一认证（AuthManager + Guard）

```php
use wise\support\auth\AuthManager;

$auth = app(AuthManager::class);

$admin  = $auth->user();          // 默认 guard（admin）→ SessionGuard
$api    = $auth->user('api');     // api guard → TokenGuard（JWT）
$userId = $auth->id();
if ($auth->check('admin')) { /* 已登录 */ }
if ($auth->guest('api'))  { /* 未登录 */ }

// 强制认证：未登录抛出 WiseException(401)
$user = $auth->authenticate('admin');

// 用户信息变更后刷新 Guard 用户缓存
$auth->refresh('admin');
```

注册自定义 Guard：

```php
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\auth\GuardInterface;

// 实现 GuardInterface
class WechatGuard implements GuardInterface { /* ... */ }

ExtPoint::register(ExtPoints::AUTH_GUARD, 'wechat', WechatGuard::class);
$wechatUser = $auth->user('wechat');
```

### JWT 统一门面

```php
use wise\support\facade\JWT;

// 签发 Token（透明代理到 wise-jwt 引擎）
$token = JWT::issue(42, ['role' => 'admin']);   // subject + claims
$pair  = JWT::issuePair(42);                    // Access + Refresh 对

// 解析与校验
$payload = JWT::parse($token);                  // 解析失败抛异常
$payload = JWT::tryParse($token);               // 失败返回 null

// 续签 / 吊销
$newToken = JWT::refresh($token);
JWT::revoke($token);
```

> `JWT` 门面并不实现 JWT 逻辑，而是经 `container('jwt') → JwtManager → ExtPoint('jwt.provider')` 发现当前活跃引擎（默认 `wise-jwt` 包注册）。引擎未就绪时 `JwtManager::isReady()` 返回 `false` 并记录告警日志，`TokenGuard` 会宽容地将认证降级为「未登录」。

### 扩展点注册中心（ExtPoint）

```php
use wise\support\ExtPoint;

// 1. 定义扩展点（通常在 ServiceProvider::register() 中）
//    第 4 个参数为「市场展示元数据」：由点的归属方自声明，能力市场自动发现，
//    无需框架层硬编码各业务包的点（新增包零改框架）。
ExtPoint::definePoint('example.single', ExtPoint::POLICY_SINGLE, SomeDriverInterface::class);
ExtPoint::definePoint('example.routed', ExtPoint::POLICY_ROUTED, SomeRoutedInterface::class);
ExtPoint::definePoint('example.multi',  ExtPoint::POLICY_MULTI, null, [
    'name'        => '示例共享扩展点',
    'description' => '……',
    'module'      => 'wise-example',   // 归属包，用于按来源分组
    'category'    => '示例',
]);

// 1b. 点已定义、但描述归其归属包（或运行时动态点）：可独立声明 / 更新元数据
ExtPoint::describePoint('example.multi', ['name' => '示例共享扩展点']);

// 读取（能力市场等消费方使用）
$meta    = ExtPoint::getPointMeta('example.multi');   // 单点元数据
$allMeta = ExtPoint::describedPoints();               // 所有自声明元数据的点（[point => meta]）

// 2. 注册实现
ExtPoint::register('example.single', 'alpha', AlphaDriver::class);
ExtPoint::register('example.single', 'beta', BetaDriver::class, 20);   // priority 越小越优先
ExtPoint::register('example.routed', 'github', GithubDriver::class);

// 3. 消费方使用
$active = ExtPoint::active('example.single');         // 当前活跃实现
$all    = ExtPoint::all('example.multi');             // 所有实现
$github = ExtPoint::get('example.routed', 'github');  // 指定平台（routed）

// 其他
ExtPoint::has('example.single', 'alpha');             // 是否存在
ExtPoint::count('example.multi');                     // 实现数量
ExtPoint::points();                                   // 列出所有已注册扩展点
```

> 动态扩展点（`bus.handler.*` / `ws.handler.*` / `slot.*` 前缀）允许在未调用 `definePoint()` 时直接 `register()`，便于按需注册。

#### 能力市场解耦（两条通道）

消费方（如能力市场）需要感知「实现状态」时**不内联判断业务包**，而由业务包自注册给 `ExtPoint`：

```php
use think\facade\Event;
use wise\support\event\CapabilityToggled;

// ① 能力启停事件（写侧）：宿主绑定派发器（app\AppService 已默认桥接到 think Event）
ExtPoint::bindEventDispatcher(static fn(object $event) => Event::trigger($event));

// 业务包订阅（关注方自行响应，如审计留痕）——事件对象携带强类型字段
Event::listen(CapabilityToggled::class, function (CapabilityToggled $e) {
    // $e->point / $e->name / $e->before / $e->after / $e->source
});

// ② 实现级展示元数据（读侧）：业务包提供单个实现的展示标记
ExtPoint::registerImplementationDescriber(static function (string $point, string $name, mixed $concrete): array {
    return $point === 'member.feature' ? ['pending' => isPending($name)] : [];
});

// 消费方汇总（提供者异常被吞，fail-safe）
$meta = ExtPoint::describeImplementation('member.feature', 'demo:achievements', DemoClass::class);
```

> 设计要点：`ExtPoint` 保持零依赖（派发器 / 提供者均为回调注入，未绑定时静默）；
> 监听方与提供者异常一律被吞掉并记录，绝不影响扩展点自身的注册与调度。
> 事件统一采用**事件对象**范式（与生态一致：`Event::listen(XxxEvent::class, ...)`），
> 不再使用字符串事件名常量。

### 服务总线（ServiceBus）

```php
use wise\support\bus\ServiceBus;

$bus = app(ServiceBus::class);

// 请求-响应：同步调用一个处理器并获取回复
$result = $bus->ask('payment.charge', ['amount' => 100]);
if ($result->isError()) {
    throw new \Exception($result->getError());
}

// 发布-订阅：异步发布给所有注册处理器
$bus->async()->publish('order.created', ['order_id' => 42]);

// 指定队列 + 广播
$bus->async()->onQueue('high_priority')->broadcast('cache.invalidate', ['tag' => 'product_list']);

// 检查是否有处理器
if ($bus->canHandle('order.created')) { /* ... */ }
```

注册处理器（类名或闭包，经 `bus.handler.{action}` 注册）：

```php
use wise\support\ExtPoint;

ExtPoint::register('bus.handler.order.created', 'notifier', OrderNotifierHandler::class);

// 处理器类：优先调用 handle(Message)，否则 __invoke
class OrderNotifierHandler
{
    public function handle(Message $message): void { /* 消费 $message->getPayload() */ }
}
```

> 异步分发依赖 `topthink/think-queue`。闭包处理器无法序列化，异步模式下会被跳过并记录告警，应注入类名。

### 分布式锁（DistributedLock）

```php
use wise\support\lock\DistributedLock;

// 默认非阻塞（Redis 驱动）
$lock = new DistributedLock('redis');
if ($lock->acquire('task:send_sms', 60)) {
    try { doSomething(); }
    finally { $lock->release('task:send_sms'); }
}

// 阻塞模式：等最多 10 秒（指数退避轮询）
$lock = new DistributedLock('redis', ['blocking' => true, 'blocking_timeout' => 10]);

// 文件驱动（单机零依赖）
$lock = new DistributedLock('file', ['lock_dir' => runtime_path() . 'lock']);

// 数据库驱动（多机无 Redis）
$lock = new DistributedLock('database', ['db_table' => 'system_lock']);

// 语法糖：自动 acquire + 执行 + release
$lock->withLock('task:send_sms', 60, function () { doSomething(); });
```

> 锁表结构由 `ensureDbTable()` 自动创建（字段见 [数据库表结构](#数据库表结构)）。Redis 驱动使用 `SET NX EX` + Lua 原子释放，Token 所有权防止误删他进程的锁。

> **语义与文件锁注意事项**：
> - `isAcquired($key)` 是**占用语义**：只要锁被任意进程持有就返回 `true`（redis / file / database 三驱动一致）。
>   需要判断「本实例是否持有」（例如释放前自检）请用 `DistributedLock::isHeld($key)`；接口 `LockInterface`
>   的方法集保持 `acquire / release / isAcquired` 三项不变。
> - **file 驱动不会删除锁文件**：flock 的互斥性绑定在 inode，删除目录项会让阻塞等待者锁住已删除的 inode、
>   而新进程 `fopen('c+')` 锁住新 inode，二者同时视为持有锁（互斥失效）。因此 `lock_dir` 下会保留 `.lock`
>   文件（陈旧文件在 flock 语义下无害），请按需为 `lock_dir` 配置轮转/清理策略。
> - file 驱动仅适用于**单机**多进程互斥；多机共享目录（NFS/SMB）下 flock 语义不可靠，请改用 redis / database 驱动。

### 断路器（CircuitBreaker）

```php
use wise\support\circuit\CircuitBreaker;

$cb = new CircuitBreaker('payment_service', cache('redis'), [
    'failure_threshold' => 5,       // 连续失败 5 次熔断
    'timeout'           => 30,      // 冷却 30 秒
    'key_prefix'        => 'wise_api:default:cb',
]);

if (!$cb->isAvailable()) {
    return ['error' => 'Service unavailable'];   // 熔断中，直接拒绝
}

try {
    $result = callExternalService();
    $cb->reportSuccess();
    return $result;
} catch (\Throwable $e) {
    $cb->reportFailure();
    throw $e;
}

// 查询状态
$state = $cb->getState();          // closed | open | half_open
$fails = $cb->getFailureCount();
$cb->reset();                      // 手动恢复
```

状态机：`CLOSED →（连续失败 N 次）→ OPEN →（冷却 T 秒）→ HALF_OPEN →（探测成功）→ CLOSED`。状态存于 Redis，支持分布式部署。

### 限流（RateLimiter / SlidingWindow / TokenBucket）

```php
use wise\support\rate\RateLimiter;

// 生产环境：使用 Redis 驱动（原子操作，避免 TOCTOU）
$limiter = RateLimiter::perMinute(60)->via('redis')->by("api:user:{$userId}");

if ($limiter->tooManyAttempts()) {
    throw new RuntimeException('请求过于频繁');
}
$remaining = $limiter->hit();            // 记录访问并返回剩余次数
$limiter->hit(10);                       // 批量操作加权计数

$headers = $limiter->toHeaders();        // X-RateLimit-* + Retry-After
$limiter->onLimitReached(function ($key, $max) {
    Log::warning("限流触发: {$key}, 上限: {$max}");
});
```

工厂方法：`perMinute / perMinutes / perHour / perHours / perDay / for(max, seconds) / forRequest`。

滑动窗口 / 令牌桶（Redis Lua 原子 + 降级）：

```php
use wise\support\rate\SlidingWindow;
use wise\support\rate\TokenBucket;

// 滑动窗口：精确时间窗口，支持 member 去重
$sw = new SlidingWindow();
$res = $sw->attempt('api:login', ['limit' => 10, 'window' => 60]);
// ['allowed' => bool, 'current' => int, 'remaining' => int]

// 令牌桶：平滑突发 + 多令牌消费
$tb = new TokenBucket();
$res = $tb->attempt('api:search', ['capacity' => 60, 'rate' => 1.0, 'consumed' => 1]);
// ['allowed' => bool, 'remaining' => float, 'retry_after_ms' => int]
```

> **Redis 不可用时的策略（默认拒绝）**：
> - 优先走 `setFallbackLimiter()` 注册的降级限流器；**没有降级器时默认 fail-closed（拒绝放行）**
>   —— 「判定不了配额」不应被默认解释为「允许」。可用性优先的入口显式选择放行：
>   `(new SlidingWindow())->failOpen()->attempt(...)`、`(new TokenBucket())->failOpen()->attempt(...)`。
> - 兼容旧写法：单次调用仍可传 `['fail_closed' => true]` 强制拒绝。
> - `RateLimiter::hit()` 的窗口语义：原子 `inc()` 分支只在首次创建计数键时写入窗口 TTL（固定窗口）；
>   当前缓存驱动**没有** `inc()` 时走非原子回退分支，该分支每次写入都携带窗口 TTL（否则窗口永不重置），
>   因此该分支表现为滑动窗口，且并发下仍可能丢计数 —— 生产请 `via('redis')`。

### 重试与退避（retry / BackoffStrategy）

```php
use wise\support\backoff\BackoffStrategy;

// 失败重试，指数退避（500ms 起步，封顶 30s），仅重试可恢复的异常
$result = retry(5, fn() => callApi(), BackoffStrategy::exponential(500, 30000));

// 动态退避闭包 + 条件过滤 + 失败回调
$result = retry(
    3,
    fn($attempt) => callApi($attempt),
    fn($attempt) => $attempt * 1000,                       // 动态 sleep 毫秒数
    fn(\Throwable $e) => $e instanceof TransientException,  // 仅重试瞬时异常
    fn(\Throwable $e, $attempt) => Log::warning("第{$attempt}次失败: {$e->getMessage()}"),
);

// 队列 Job 的 backoff() 方法
class MyJob
{
    public function backoff(): array
    {
        return BackoffStrategy::linear(1000)->toArray(5);  // [1000,2000,3000,4000,5000]
    }
}
```

### 管道（Pipeline）与请求级缓存（Once）

```php
use wise\support\Pipeline;

$result = (new Pipeline())
    ->send($data)
    ->through([
        TrimStringsPipe::class,                       // 类名 → 调用 handle()
        fn($data, $next) => $next(array_map('strtolower', $data)),  // 闭包
    ])
    ->then(fn($data) => DataModel::create($data));    // 最终回调

// 辅助函数
$result = str_pipeline($data)->through([...])->then(fn($d) => $d);
```

```php
use wise\support\Once;

// 同一 call-site 的同一闭包在同一请求内只执行一次
function getConfig(): array {
    return Once::memoize(fn() => include config_path('complex.php'));
}
// 或使用 once() 辅助函数
$cfg = once(fn() => include config_path('complex.php'));

Once::forget($callback);   // 强制重算
Once::flush();             // 清空所有缓存
```

### 请求上下文与链路日志（RequestContext / ContextualLogger）

```php
use wise\support\context\RequestContext;
use wise\support\context\ContextualLogger;
use wise\support\facade\Context;

// 全局中间件入口
RequestContext::setRequestId(bin2hex(random_bytes(8)));
RequestContext::set('locale', 'zh-CN');

// 认证后自动注入 user/guard（AuthManager::user() 已完成）
$userId = RequestContext::getUserId();
$requestId = RequestContext::getRequestId();
$tenantId  = RequestContext::getTenantId();

// 请求结束清理（防 Swoole/Workerman 常驻进程泄漏）
RequestContext::clear();

// 上下文感知日志：自动注入 request_id / user_id / guard
ContextualLogger::info('支付成功', ['order_id' => 42]);
ContextualLogger::channel('backup')->error('备份失败', ['task_id' => 1]);
```

### WebSocket 基础设施

`wise-support` 不负责建立底层 Socket 连接（那是 Swoole/Workerman 的事），而是提供连接元数据管理与消息路由：

```php
use wise\support\ws\ConnectionManager;
use wise\support\ws\MessageRouter;

$manager = app(ConnectionManager::class);
$router  = app(MessageRouter::class);

// 连接建立 / 关闭
$manager->onOpen($fd, ['user_id' => 42, 'ip' => '127.0.0.1']);
$manager->onClose($fd);

// 消息发送
$manager->sendToUser(42, json_encode(['action' => 'notification', 'msg' => 'hi']));
$manager->sendToRoom('game_room_123', json_encode(['action' => 'player_joined']), $excludeFd);
$manager->broadcast($message);

// 房间管理
$manager->joinRoom($fd, 'game_room_123');
$manager->leaveRoom($fd, 'game_room_123');

// 心跳
$manager->heartbeat($fd);
$timeoutFds = $manager->checkHeartbeat(60);   // 60 秒无心跳的连接

// 统计
$stats = $manager->stats();                   // connections / users / rooms / channels
```

消息路由（JSON 格式 `{"action": "...", "room": "...", "data": {...}}`）：

```php
$router->dispatch($fd, $rawJsonMessage);

// 注册自定义处理器
ExtPoint::register('ws.handler.game.frame_sync', 'frame_sync', FrameSyncHandler::class);
```

频道接口（`ChannelInterface`）实现：

```php
use wise\support\ws\ChannelInterface;

class FrameSyncChannel implements ChannelInterface
{
    public function getName(): string { return 'game.frame_sync'; }
    public function onMessage(int $fd, array $data): void { /* 业务处理 */ }
    public function onJoin(int $fd): void {}
    public function onLeave(int $fd): void {}
}
```

> 通过 `ExtPoint('ws.server', SINGLE)` 注册 `WsServerInterface` 实现（Swoole/Workerman），`PingPongHeartbeat` 内置处理器处理 `{"type":"ping"}` → 回复 `{"type":"pong","time":...}`。

> **鉴权预检与心跳的执行顺序**：
> - 所有最终进入业务分发的帧都会经过 `MessageRouter::authorize()` 注册的预检回调 —— 包括 `action`
>   为 `ping`/`pong`、但心跳层未接管的帧（它们实际会调用 `ws.handler.ping|pong` 或
>   `bus.handler.ping|pong`，属业务分发）。回调入参含权威 `action` 键，判断依据不再是客户端自选的 body。
> - 心跳在预检**之前**执行（控制消息不能被业务鉴权拦住）：未绑定用户（未登录）的连接只放行内置
>   `PingPongHeartbeat`，其余 `ws.heartbeat` 实现（含闭包）会被跳过并记一条 debug 日志。
> - 仅做心跳、不把 `ping`/`pong` 当业务 action 的部署无需改动；若确实暴露了 `ping`/`pong` 业务处理器，
>   需在预检回调里显式放行。
> - 业务 `data` 只取帧内 `data` 字段：缺省即为空数组，帧顶层字段（`action` / `room` / 客户端自选的
>   `user_id`）不会并入业务 body；权威 `user_id` 始终由服务端连接状态推导。

### 连接器（Connector）

```php
use wise\support\connector\ConnectorManager;
use wise\support\connector\ConnectorType;

$manager = app(ConnectorManager::class);

// 按名称获取
$wechat = $manager->driver('wechat_pay');
$result = $wechat->execute('charge', ['amount' => 100, 'currency' => 'CNY']);
if ($result->isSuccess()) { $data = $result->getData(); }

// 获取活跃的支付连接器（按类型）
$payment = $manager->activeFor(ConnectorType::PAYMENT);

// 健康检查
$health  = $manager->healthCheck('wechat_pay');
$allHealth = $manager->healthCheckAll();

// 配置管理
$schema = $manager->getConfigSchema('wechat_pay');   // 管理后台渲染配置表单
$manager->setConfig('wechat_pay', ['api_key' => 'xxx']);
```

注册连接器：

```php
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\connector\ConnectorInterface;
use wise\support\connector\ConnectorType;

class WechatPayConnector implements ConnectorInterface
{
    public function getId(): string { return 'wechat_pay'; }
    public function getName(): string { return '微信支付'; }
    public function getType(): ConnectorType { return ConnectorType::PAYMENT; }
    public function execute(string $action, array $input, array $context = []): \wise\support\connector\ConnectorResult { /* ... */ }
    // ... healthCheck / getConfigSchema / setConfig / getConfig
}

ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', WechatPayConnector::class);
```

**领域子接口（推荐）**：外部系统能力建议实现领域子接口并继承 `AbstractConnector`，
业务代码经 `ConnectorManager` 的强类型入口消费（`instanceof` 断言收口在 Manager 内）：

| 领域 | 子接口 | 强类型入口 |
|------|--------|-----------|
| 支付 | `PaymentConnectorInterface`（`charge` / `queryOrder` / `refund`） | `$manager->payment()` |
| 短信 | `SmsConnectorInterface`（`send` / `sendBatch`） | `$manager->sms()` |
| 存储 | `StorageConnectorInterface`（组合 `ConnectorInterface` + `StorageDriverInterface`） | `$manager->storage($name = null)` |

```php
// 业务侧：强类型调用（推荐）
app(ConnectorManager::class)->payment()->charge(['out_trade_no' => 'T1', 'amount' => 100]);

// 管理后台 / AI：只依赖通用契约，用 getActions()/getConfigSchema() 自动渲染
foreach (app(ConnectorManager::class)->all() as $type => $connectors) { /* ... */ }
```

> 存储驱动可 `use StorageConnectorTrait` 补齐通用方法，仅需继续实现存储业务方法。
> `payment.driver` / `sms.provider` / `storage.driver` / `oauth.driver` /
> `notification.channel` / `anti_addiction.identity` 等**外部系统扩展点已废除**，
> 能力统一由连接器体系承接；`captcha.driver` / `exception.renderer` / `workflow.pipe`
> 及框架基础设施驱动点（`cache` / `queue` / `log` / `ws.server`）等**空转扩展点亦已清理**。

### 配置检查（ConfigCheck）

```php
use wise\support\config\ConfigCheckInterface;
use wise\support\config\ConfigCheckCollector;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;

class JwtSecretCheck implements ConfigCheckInterface
{
    public function id(): string { return 'wise-jwt:secret'; }
    public function package(): string { return 'wise-jwt'; }
    public function level(): CheckLevel { return CheckLevel::ERROR; }

    public function check(): CheckResult
    {
        return env('JWT_SECRET')
            ? CheckResult::ok('JWT 密钥已配置')
            : CheckResult::fail('缺少 JWT_SECRET', '请在 .env 中设置 JWT_SECRET');
    }
}

// 在 ServiceProvider 中注册
app(ConfigCheckCollector::class)->register(new JwtSecretCheck());

// 应用启动时执行检查（失败日志按 5 分钟冷却防刷屏）
$failed = app(ConfigCheckCollector::class)->report();
```

### 异常与错误码（WiseException）

```php
use wise\support\exception\WiseException;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\ErrorCodeInterface;

// 直接 new
throw new WiseException(1001, '参数错误', 400);

// 静态工厂（推荐）：自动从枚举取码与默认说明
throw WiseException::of(GeneralErrorCode::NOT_FOUND)
    ->withContext('resource', 'order');

// 链式构建
throw WiseException::of(GeneralErrorCode::BAD_REQUEST)
    ->withContextMany(['field' => 'amount', 'raw' => $raw]);

// 公开数据 vs 诊断数据：withContext() 会进响应体，withDiagnosticContext() 只进日志
throw WiseException::of(GeneralErrorCode::SERVICE_UNAVAILABLE)
    ->withContext('service', 'pay')                      // 客户端可见
    ->withDiagnosticContext('upstream_body', $raw)       // 仅日志（SQL/路径/上游报文放这里）
    ->withDiagnosticContextMany(['trace_id' => $traceId]);

// 各包定义自有错误码枚举（号段互不重叠）
enum ErrorCode: int implements ErrorCodeInterface
{
    case PAY_SIGN_INVALID = 2201;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::PAY_SIGN_INVALID => '支付签名验证失败',
        };
    }
}
throw WiseException::of(ErrorCode::PAY_SIGN_INVALID, '自定义提示', 400);
```

`GeneralErrorCode` 框架级通用码（100~999）：

| 分类 | 号段 | 枚举 |
|------|------|------|
| HTTP 语义 | 100~199 | `BAD_REQUEST=100` `UNAUTHORIZED=101` `FORBIDDEN=102` `NOT_FOUND=103` `METHOD_NOT_ALLOWED=104` `TOO_MANY_REQUESTS=105` `SERVER_ERROR=150` `SERVICE_UNAVAILABLE=151` |
| 校验错误 | 200~299 | `VALIDATION_FAILED=200` `REQUIRED_FIELD=201` `INVALID_FORMAT=202` |
| 基础设施 | 300~399 | `CONFIG_ERROR=300` `DB_ERROR=301` `CACHE_ERROR=302` `IO_ERROR=303` `NETWORK_ERROR=304` `THIRD_PARTY_ERROR=305` `EXT_POINT_NOT_DEFINED=306` |

控制器统一响应（Trait）：

```php
use wise\support\traits\JsonResponse;

class MyController
{
    use JsonResponse;

    public function index()
    {
        return $this->success('操作成功', $data);
        return $this->error('参数错误', $errors, 1001);
        return $this->exceptionResponse($e);      // WiseException → 统一 JSON
        return $this->circuitResponse($e);        // 熔断 → HTTP 503 + Retry-After
    }
}
```

### .env 密钥播种（EnvSeedService）

```php
use wise\support\env\EnvSeedService;

// 一键播种：发现 → 收集 → 幂等合并进 .env
$service = new EnvSeedService();
$report = $service->seed($envPath, $vendorDir);
// ['written' => [...], 'skipped' => [...], 'warnings' => [...]]
```

> **并发安全与落盘方式**：
> - 整段「读 → 改 → 写」在**独占锁**内完成；锁文件位于系统临时目录
>   （`wise-env-seed-<md5(.env 绝对路径)>.lock`），不锁 `.env` 本身。
> - 提交使用**同目录临时文件 + 原子 rename**，不再就地 `ftruncate`：
>   写入失败不会把既有 `.env` 截断，只会留下一条 warning（原文件保持不动）。
> - 拿不到锁时**跳过播种**并写入 warning（绝不在无锁状态下改写 `.env`）。
>   并发安装请让两个入口（Web 安装器 / `env:seed` 命令）依赖该锁串行执行。
> - `EnvValueGenerator` 生成 RSA 私钥时，**在写入内容前**就把文件设为 0600
>   （独占创建 → 设权限 → 写内容 → 原子改名），并在 POSIX 上校验实际模式；
>   无法限制为 0600 时删除该文件并抛 `RuntimeException`（不再「静默 0644」）。
>   Windows 的 `chmod` 无法限制读权限，请用 ACL 保证密钥目录仅服务账户可读。

各包声明播种契约（实现 `EnvSeedProvider`）：

```php
use wise\support\contracts\EnvSeedProvider;

class WiseJwtServiceProvider implements EnvSeedProvider
{
    public static function envSeeds(): array
    {
        return [
            'JWT_SECRET' => [
                'type'      => 'secret',
                'generator' => 'random_bytes_32',
                'comment'   => 'JWT 签名密钥（64 位十六进制随机串）',
            ],
            'OPENAI_API_KEY' => [
                'type'    => 'external',       // 绝不写假值，仅留空占位
                'comment' => 'OpenAI 密钥，请在下方手动填写',
            ],
        ];
    }
}
```

> 幂等约束：任意键在 `.env` 中已存在时**绝不覆盖**，保证重装/二次安装不会洗掉用户已生效的密钥。生成策略为封闭集合：`random_bytes_32` / `hmac_salt` / `uuid` / `rsa_2048_pem`。

### 辅助函数

`src/helper.php` 提供的全局函数：

| 函数 | 说明 |
|------|------|
| `str_pipeline($passable)` | 创建 `Pipeline` 实例 |
| `rate_limiter($max, $decaySeconds)` | 创建 `RateLimiter` 实例 |
| `array_only_dot($array, $keys)` | 按 `.` 多级路径提取数组键 |
| `retry($times, $callback, $sleepMs, $when, $onFailure)` | 失败重试原语 |
| `once($callback)` | 请求级闭包缓存 |
| `request_id()` | 获取当前请求 ID |
| `context($key, $default)` | 获取/设置请求上下文 |
| `wex($errorCode, $message, $httpCode, $context)` | 创建 `WiseException` 快捷方式 |
| `auth_user($guard)` | 获取当前登录用户 |
| `auth_id($guard)` | 获取当前登录用户 ID |

---

## API / 方法参考

### ExtPoint（静态注册中心）

| 方法 | 返回 | 说明 |
|------|------|------|
| `definePoint(string $point, string $policy = POLICY_SINGLE, ?string $contract = null)` | `void` | 定义扩展点元信息（策略 + 契约） |
| `defined(string $point)` | `bool` | 扩展点是否已定义 |
| `getDefinition(string $point)` | `?array` | 获取扩展点定义信息 |
| `getPolicy(string $point)` | `string` | 获取扩展点调度策略 |
| `register(string $point, string $name, string\|callable $concrete, int $priority = 100)` | `void` | 注册一个实现（契约校验 + 策略激活） |
| `all(string $point)` | `array` | 获取所有实现（按优先级排序） |
| `get(string $point, string $name)` | `string\|callable\|null` | 获取指定名称的实现 |
| `active(string $point)` | `?array` | 获取 SINGLE 策略下的活跃实现 |
| `setActive(string $point, string $name, bool $active)` | `bool` | 设置实现活跃状态；**安全相关扩展点**（`auth.guard` / `jwt.provider` / `connector` / `exception.renderer`）需经 `bindOverrideAuthorizer()` 授权，未授权返回 `false` 且不改动状态 |
| `bindOverrideStore(?callable $loader, ?callable $saver = null)` | `void` | 注入活跃覆盖表的持久化存储（能力市场） |
| `bindOverrideAuthorizer(?callable $authorizer)` | `void` | 注入活跃变更授权回调 `fn(string $point, string $name, bool $active): bool`；未绑定时安全相关扩展点的活跃变更一律拒绝（fail-closed） |
| `SECURITY_RELEVANT_POINTS` | `string[]` | 安全相关扩展点清单 |
| `resolve(string $point, ?string $routeKey = null, ?string $strategy = null)` | `array` | 按策略解析扩展点 |
| `unregister(string $point, string $name)` | `void` | 注销实现 |
| `has(string $point, string $name)` | `bool` | 是否存在指定实现 |
| `isEmpty(string $point)` | `bool` | 扩展点下是否有实现 |
| `count(string $point)` | `int` | 实现数量 |
| `points()` | `array` | 列出所有已注册扩展点名称 |
| `getUndefinedRegistrations()` | `array` | 未定义即注册的诊断记录 |
| `reset()` | `void` | 重置所有注册数据（测试用） |

### AuthManager / GuardInterface

`AuthManager`：

| 方法 | 返回 | 说明 |
|------|------|------|
| `user(?string $guard = null)` | `?object` | 获取当前登录用户（同步注入 Context） |
| `id(?string $guard = null)` | `string\|int\|null` | 获取用户 ID |
| `check(?string $guard = null)` | `bool` | 是否已登录 |
| `guest(?string $guard = null)` | `bool` | 是否未登录 |
| `authenticate(?string $guard = null)` | `object` | 强制认证，未登录抛 `WiseException(401)` |
| `guard(string $name)` | `GuardInterface` | 获取 Guard 实例（ExtPoint 发现 / 回退内置） |
| `registerGuard(string $name, GuardInterface $guard)` | `self` | 手动注册 Guard |
| `setDefaultGuard(string $guard)` / `getDefaultGuard()` | — | 设置 / 获取默认 Guard |
| `getRegisteredGuards()` | `array` | 列出所有已注册 Guard |
| `refresh(?string $guard = null)` | `self` | 刷新 Guard 用户缓存 |
| `clearCache()` | `void` | 清除 Guard 实例缓存 |

`GuardInterface`：`user(): ?object`、`id(): string|int|null`、`check(): bool`、`guest(): bool`、`setUser(object $user): void`、`getName(): string`、`resetUser(): void`。

`SessionGuard` 额外：`setSessionKey(string)`、`getSessionKey(): string`、`setUserResolver(callable)`。

`AuthManager` 的 Guard 实例缓存按**请求世代**失效（以 `RequestContext` 的 `request_id` 为标记）：同一请求内保留记忆化，世代变化时丢弃，因此常驻运行时不会复用上一个请求的 Guard 及其 principal 缓存。

`TokenGuard` 额外：`setUserResolver(callable)`、`allowParamToken(bool)`（默认关闭 query/cookie 通道）、`failClosedOnEngineUnavailable(bool)`（引擎不可用时抛 503 而不是降级为访客，默认关闭）。引擎不可用（未注册 / 初始化失败）与凭据非法被区分处理：前者不锁存为「确定未登录」，同一请求内可重试。
`TokenGuard` 额外：`setUserResolver(callable)`、`autoSetContext(bool)`、`getPayload(): ?array`。

### JwtManager（代理）

| 方法 | 返回 | 说明 |
|------|------|------|
| `getEngine(?string $guard = null)` | `object` | 获取 JWT 引擎（经 `ExtPoint('jwt.provider')`）；传守卫名时按 guard 解析并缓存，provider 可实现为 `fn(?string $guard = null)` 以返回守卫专属引擎 |
| `isReady(?string $guard = null)` | `bool` | 引擎是否就绪 |
| `clearCache()` | `void` | 清除引擎缓存 |
| `__call($method, $args)` | `mixed` | 透明转发到真实引擎 |

`JWT` 门面（由 wise-jwt 引擎提供，示意）：`issue($subject, $claims, $ttl)`、`issuePair($subject)`、`parse($token)`、`tryParse($token)`、`refresh($token)`、`revoke($token)`、`shouldRefresh($payload)`、`ttlOf($payload)`。

### ServiceBus / Message

`ServiceBus`：

| 方法 | 返回 | 说明 |
|------|------|------|
| `ask(string $action, array $payload, array $context)` | `Message` | 请求-响应（始终同步） |
| `publish(string $action, array $payload, ?callable $onReply)` | `void` | 发布-订阅 |
| `broadcast(string $action, array $payload)` | `void` | 广播（单处理器异常不中断） |
| `canHandle(string $action)` | `bool` | 是否有处理器 |
| `listHandlers(string $action)` | `array` | 列出处理器 |
| `async()` / `sync()` | `self` | 切换异步/同步分发 |
| `onQueue(string $queueName)` | `self` | 指定异步队列 |
| `setLogger(LoggerInterface)` | `self` | 设置日志记录器 |

`Message`（静态工厂）：`request($action, $payload, $context)`、`event($action, $payload, $context)`、`reply($original, $payload, $context)`、`error($original, $error, $code)`；读取：`getId()/getType()/getAction()/getPayload()/get($key, $default)/getContext()/getCorrelationId()/isError()/getError()/getErrorCode()/toArray()`、`Message::fromArray()`。

### ConnectorManager / ConnectorInterface

`ConnectorManager`：`driver(string $name)`、`activeFor(ConnectorType $type)`、`all()`、`healthCheck(string $name)`、`healthCheckAll()`、`getConfigSchema(string $name)`、`setConfig(string $name, array)`、`clearCache()`；领域便捷入口：`payment(): PaymentConnectorInterface`、`sms(): SmsConnectorInterface`、`storage(?string $name = null): StorageConnectorInterface`（内部完成契约断言收口）。

`AbstractConnector`：连接器抽象基类，为 `ConnectorInterface` 提供通用默认实现（`getId/getName/getDescription/getActions/execute/healthCheck/getConfigSchema/setConfig/getConfig`），子类只需实现 `getType()` 与领域方法。

`ConnectorInterface`：`getId(): string`、`getName(): string`、`getType(): ConnectorType`、`getDescription(): string`、`getActions(): array`、`execute(string $action, array $input, array $context): ConnectorResult`、`healthCheck(): bool`、`getConfigSchema(): array`、`setConfig(array)`、`getConfig(): array`。

`ConnectorType` 枚举：`OAUTH` / `WEBHOOK` / `API` / `MESSAGE` / `PAYMENT` / `STORAGE` / `AI` / `IOT`（含 `label()` 中文标签）。

### CircuitBreaker

| 方法 | 返回 | 说明 |
|------|------|------|
| `isAvailable()` | `bool` | 服务是否可用（含冷却→半开自动迁移） |
| `reportSuccess()` | `void` | 上报成功 |
| `reportFailure()` | `void` | 上报失败（达阈值熔断 / 半开失败重新打开） |
| `getState()` | `string` | 当前状态（closed/open/half_open） |
| `getFailureCount()` | `int` | 失败次数 |
| `reset()` | `void` | 手动恢复 |
| `setKeyPrefix(string $prefix)` | `void` | 设置 Redis key 前缀 |

构造参数：`$serviceName, $cache, $options = ['failure_threshold'=>5, 'timeout'=>30, 'half_open_max_requests'=>3, 'success_threshold'=>2, 'key_prefix'=>'cb']`。

### DistributedLock / LockInterface

`LockInterface`：`acquire(string $key, int $ttl): bool`、`release(string $key): bool`、`isAcquired(string $key): bool`。

`DistributedLock` 额外：`isHeld(string $key): bool`（本实例是否持有，与 `isAcquired` 的占用语义区分）、`withLock(string $key, int $ttl, callable $callback, int $waitMs = 0): array`（返回 `['acquired'=>bool, 'result'=>mixed]`）。构造：`new DistributedLock('redis'|'file'|'database', $options)`。

### RateLimiter / RateLimitInterface

`RateLimiter` 静态工厂：`for($max, $decay)`、`perMinute($max)`、`perMinutes($max, $minutes)`、`perHour($max)`、`perHours($max, $hours)`、`perDay($max)`、`forRequest($max, $decay, ?$key)`。

流式配置：`by($key)`、`withPrefix($prefix)`、`via($store)`、`setCache($cache)`、`onLimitReached($closure)`。

核心操作：`tooManyAttempts()`、`hit($weight=1, $limit=null, $window=null): int`、`attempts()`、`remaining()`、`getLimit()`、`cooldown()`、`availableAt()`、`toHeaders()`、`clear()`，以及 `RateLimitInterface` 的 `attempt($key, $options): array`、`status($key, $options): array`、`reset($key)`。

`SlidingWindow` / `TokenBucket`（实现 `RateLimitInterface`）：`attempt($key, $options)`、`status($key, $options)`、`reset($key)`、`setFallbackLimiter(RateLimiter $limiter)`。

`AtomicCounter::increment($key, $expire, $context): int` — 三层降级（Redis Lua → Cache::inc → 非原子兜底）。

### BackoffStrategy

| 方法 | 返回 | 说明 |
|------|------|------|
| `linear(int $stepMs = 1000)` | `self` | 线性退避：step, step*2, ... |
| `exponential(int $baseMs = 1000, ?int $maxMs = 60000)` | `self` | 指数退避（封顶） |
| `fixed(int $delayMs = 5000)` | `self` | 固定延迟 |
| `custom(array $delaysMs)` | `self` | 自定义延迟数组 |
| `delayForAttempt(int $attempt)` | `int` | 计算第 N 次延迟 |
| `toArray(?int $maxAttempts)` | `array` | 序列化策略 / 生成延迟数组 |
| `__invoke(int $attempt)` | `int` | 可直接作为 retry() 的 $sleepMs |
| `fromArray(array $data)` | `self` | 队列反序列化 |
| `serialize()` | `array` | 队列传输序列化 |

### RequestContext / ContextualLogger

`RequestContext`（静态）：`set/get/has/remove/setMany/all/clear`、`setRequestId/getRequestId`、`setUser/getUser/getUserId`、`setTenantId/getTenantId`、`setGuard/getGuard`、`setLocale/getLocale`、`logContext()`。

`ContextualLogger`：`channel(string $name): self`、静态或实例调用 `info/error/warning/debug/notice/alert/critical/emergency/sql($message, $context)`。

### RouteRegistrar

| 方法 | 说明 |
|------|------|
| `register()` | 静态入口（由 ServiceProvider::boot() 调用） |
| `group(string $groupKey, callable $definer)` | 创建配置驱动的路由分组（enable/prefix/middleware 合并） |
| `define(array $config)` | 抽象方法，子类定义具体路由 |

子类需定义 `$configKey`（如 `'wise-wx'`），路由配置统一放在 `config/<pkg>.php` 的 `web` 节。

### EnvSeedService

`discoverProviderClasses(string $vendorDir, &$warnings): array`、`collectSpecs(array $providerClasses): array`、`seed(string $envPath, ?string $vendorDir): array`、`mergeIntoEnv(array $specs, string $envPath): array`。

---

## 事件 / 扩展点

### 标准扩展点（`ExtPoints`）

| 扩展点 | 策略 | 契约 | 说明 |
|--------|------|------|------|
| `auth.guard` | routed | `GuardInterface` | 认证守卫（admin/api/member） |
| `jwt.provider` | single | — | JWT 引擎（wise-jwt 注册） |
| `image.driver` | single | — | 图片处理驱动 |
| `image.processor` | multi | — | 图片处理器 |
| `connector` | routed | `ConnectorInterface` | 外部系统连接器（支付/短信/存储/OAuth/通知/身份等统一入口） |
| `login.user_resolver` | routed | — | 登录安全用户解析器 |
| `dashboard.widget` | multi | — | 仪表盘挂件 |
| `menu.item` | multi | — | 菜单项 |
| `search.provider` | multi | — | 全局搜索后端（第三方 ES/Meilisearch，内置 provider 兜底） |
| `bus.handler` | multi | — | 服务总线消息处理器（动态前缀） |
| `ws.handler` / `ws.heartbeat` | multi | — | WebSocket 消息/心跳处理器 |
| `slot.content` | multi | — | 前端插槽内容 |
| `route.api` / `route.admin` / `route.member` / `route.plugin` | multi | — | 应用路由 |
| `permission.sync_provider` / `ai.tool.permission_provider` | multi | — | 权限同步 / AI 工具权限 |

> 所有标准扩展点元数据可经 `ExtPoints::getPointMeta()` 获取（供 Capability 等管理界面展示）。

### 事件

本包主要为其他包提供「扩展点」而非直接触发业务事件。`src/event/WiseEvents.php` 为事件常量预留。内置实现：

- `ws.heartbeat` → `PingPongHeartbeat`（`{"type":"ping"}` → `{"type":"pong","time":...}`）
- `exception.renderer` → `DefaultExceptionRenderer`（`WiseException → toArray()`；其他 Throwable 仅在 `app.debug` **且**请求 IP 命中 `app.debug_allow_ips`（或子类覆写 `isDebugAuthorized()`）时输出异常 message/file/line，默认隐藏）

> `ServiceBus::publish/broadcast` 与本包的事件触发接口（`EventTrait::dispatch/dispatchMany/dispatchUntil`）共同构成消息/事件分发能力，但 `ServiceBus` 是「有名称、显式注册处理器」的机制，与 ThinkPHP `Event` 生命周期事件互补。

---

## 与其他 wise-* 包协作

`wise-support` 是整个 WiseAdmin 生态的**基座**，几乎被所有业务包依赖。典型协作关系：

| 场景 | 协作方式 |
|------|----------|
| **JWT 认证** | `wise-jwt` 包在 `ServiceProvider::boot()` 调用 `ExtPoint::register(ExtPoints::JWT_PROVIDER, 'wise-jwt', fn() => new Jwt(...))`；`wise-support` 的 `JWT` 门面 / `TokenGuard` 透明代理到该引擎 |
| **RBAC 鉴权** | `wise-auth` 包实现 `GuardInterface` 并通过 `ExtPoint('auth.guard')` 注册；`AuthManager` 统一读取用户 |
| **外部服务调用** | 业务包（如支付、短信）实现 `ConnectorInterface` 并注册到 `ExtPoint('connector')`，经 `ConnectorManager` 统一调度、熔断、健康检查 |
| **跨插件通信** | 插件经 `ExtPoint::register('bus.handler.{action}', ...)` 注册处理器，通过 `ServiceBus` 的 `ask/publish/broadcast` 交互（可异步进 think-queue） |
| **WebSocket 推送** | 游戏/IM 类包实现 `WsServerInterface`（Swoole/Workerman）与 `ChannelInterface`，经 `ConnectionManager` 做按用户/房间推送 |
| **配置检查** | 各包实现 `ConfigCheckInterface` 注册到 `ConfigCheckCollector`，`report()` 统一汇总失败项 |
| **.env 播种** | 各包在 `ServiceProvider` 上实现 `EnvSeedProvider::envSeeds()`，安装命令经 `EnvSeedService` 幂等合并密钥 |
| **统一路由** | 各包继承 `RouteRegistrar` 定义路由，前缀/中间件/限流收敛到各自 `config/<pkg>.php` 的 `web` 节 |
| **统一响应与异常** | 各包控制器 `use JsonResponse / ErrorHandler`，抛 `WiseException`（ErrorCode 枚举实现 `ErrorCodeInterface`），经 `DefaultExceptionRenderer` 输出统一 JSON |
| **配置与静态资源发布** | 包配置由 ThinkPHP `vendor:publish`（`extra.think.config`）发布；包内 `public/` 静态资源由 `wise:vendor:publish`（`extra.think.publish`）发布 |

---

## 数据库表结构

本包**不包含任何迁移文件**（`composer.json` 无 `migrate-path`，无 `database/` 目录），开箱即用。

但 `DistributedLock` 的 **database 驱动**会自动创建锁表（`ensureDbTable()`，默认逻辑表名 `system_lock`）。
`db_table` 传**不含前缀**的逻辑表名，物理表名由连接配置的 `database.prefix` 拼出（当前为 `ws_system_lock`）：

```sql
CREATE TABLE `{prefix}system_lock` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lock_key` VARCHAR(64) NOT NULL COMMENT '锁键（MD5）',
    `task_name` VARCHAR(255) NOT NULL COMMENT '任务名称',
    `create_time` DATETIME NOT NULL COMMENT '创建时间',
    `expires_at` INT UNSIGNED NOT NULL COMMENT '过期时间戳（Unix 秒）',
    UNIQUE KEY `uk_lock_key` (`lock_key`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分布式锁表';
```

> 若使用 `DistributedLock('database')` 且表不存在，会在首次 `acquire()` 时自动创建。Redis / File 驱动不依赖此表。
> 时间列约定：`expires_at` 保持无符号 `INT`（Unix 秒，参与 `expires_at < time()` 比较，无符号可避免 2038 溢出）；
> `create_time` 为 `datetime`，与框架时间字段约定一致。`wise-crontab` 的 `crontab_lock` 迁移与此结构保持同步。

---

## 依赖关系

| 依赖 | 版本 | 说明 |
|------|------|------|
| PHP | >= 8.1 | 核心语言 |
| topthink/framework | ^6.0 \|\| ^8.0 | ThinkPHP 框架（唯一必需依赖） |

### 开发依赖

| 依赖 | 版本 | 说明 |
|------|------|------|
| phpunit/phpunit | >= 9.0 | 单元测试 |
| mockery/mockery | >= 1.0 | Mock 框架 |

### 可选协作包（被依赖侧）

- **被依赖**：几乎所有 `wise-*` 业务包（`wise-jwt`、`wise-auth`、`wise-api` 等）均依赖本包。
- **可选**：`topthink/think-queue`（ServiceBus 异步分发、BusHandlerJob）、`Redis` 扩展（分布式锁/限流/断路器生产环境）、Swoole/Workerman（WebSocket 服务端）。

---

## 开发与测试

```bash
cd vendor/wiseadmin/wise-support
composer install --dev
./vendor/bin/phpunit
```

---

## 常见问题

### Q: JWT 门面报「JWT 引擎未注册」怎么办？

`JWT` 门面是代理，必须由 `wise-jwt` 包（或其他实现）注册 `ExtPoint('jwt.provider', 'wise-jwt', ...)`。安装 `wise-jwt` 后即可。也可在任意 ServiceProvider 中注册自定义引擎。

### Q: 为什么 `Auth::user('api')` 返回 null？

可能原因：
1. 未安装 `wise-jwt`（引擎未就绪，`TokenGuard` 宽容降级为未登录）；
2. 请求头缺少 `Authorization: Bearer <token>`；
3. `TokenGuard` 未设置 `userResolver`（默认只返回 `{id, jti}` 基础对象）。

### Q: RateLimiter 生产环境用哪个驱动？

**必须使用 Redis**（`->via('redis')`），`file` 驱动存在 TOCTOU 竞态条件（高并发计数丢失），代码中会在降级路径触发 `E_USER_WARNING` 告警。

### Q: 分布式锁的 blocking 模式与阻塞获取有什么区别？

`blocking => true` 时 `acquire()` 会按指数退避轮询直到成功或 `blocking_timeout` 超时；`blocking_timeout = 0` 时仅 file 驱动支持内核级 `flock(LOCK_EX)` 原生挂起（需调用方保证锁必然释放）。

### Q: 常驻进程（Swoole/Workerman）下上下文为什么必须 clear？

`RequestContext` 使用静态属性存储。非协程常驻进程（Swoole 常驻 / Workerman / 队列 worker）里所有请求共用一个桶：`setRequestId()` 检测到 request_id 变化时会先丢弃上一世代的数据，因此身份不会跨请求泄漏；仍建议在请求结束时调用 `RequestContext::clear()` 显式兜底（协程环境按协程分桶，且协程结束有 defer 回收）。

### Q: 如何让配置检查日志不刷屏？

`ConfigCheckCollector` 默认按检查项 id 使用 `shared_file` 缓存做 5 分钟冷却，同一失败项在冷却期内只打印一次。可调整 `$cooldownSeconds`。

---

## 许可证

Apache-2.0 License

---

> 更多信息请参考 WiseAdmin 框架文档及各 wise-* 业务包 README。
