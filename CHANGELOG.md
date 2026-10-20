# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Security

> 以下五项均来自 source-only 安全审计（`docs/wise-support-security-audit-run-1/`），其中四项是**行为收紧**，
> 发布前请对照 CHANGELOG 条目确认宿主行为是否需要同步调整。

- **WS：控制帧不再绕过鉴权预检**（行为收紧）
  - `MessageRouter::dispatch()` 对**所有**最终进入 `route()` 的帧执行 `authorizePayload()`，
    不再按客户端自选的 `action`（`ping`/`pong`）隐式豁免——此类帧若心跳层不接管，实际会调用
    `ws.handler.ping|pong` / `bus.handler.ping|pong`，属业务分发
  - 鉴权回调入参新增权威 `action` 键，可把决策绑定到实际被路由的操作
  - 宿主若把 `ping`/`pong` 当业务 action 暴露，需在预检回调内显式放行
- **WS：未登录连接只放行内置心跳处理器**（行为收紧）
  - `dispatchHeartbeat()` 在连接未绑定用户时跳过非内置的 `ws.heartbeat` 实现（含闭包），
    仅放行 `PingPongHeartbeat`，并记一条 debug 日志
  - 需要在未认证阶段运行自定义心跳的宿主：改用内置心跳，或把该逻辑移到登录后
- **服务总线：异步作业的执行类必须是该 action 的注册处理器**（行为收紧）
  - `BusHandlerJob::fire()` 在实例化前校验 `handler_class` 属于 `bus.handler.{action}` 的注册集，
    否则 `delete()` 并抛 `RuntimeException`，不再执行队列载荷指定的任意类
  - `ServiceBus::dispatchAsync()` 写入的本就是注册 concrete，包内生产者不受影响；
    直接推 `BusHandlerJob` 且类名未注册的外部生产者需先注册或改造
- **异常披露判定收敛为唯一入口**（行为收紧）
  - 新增 `DefaultExceptionRenderer::isDebugDisclosureAllowed(bool $authorized = false)`：
    输出内部细节必须同时满足 `app.debug` **且**（请求 IP 命中 `app.debug_allow_ips` 或调用方授权），默认拒绝
  - `ErrorHandler::handleException()`、`DefaultExceptionRenderer::renderGeneric()`、
    `JsonResponse::isDebugAllowed()/success()/error()` 全部改走该判定
  - 影响：`app.debug = true` 但未配置 `app.debug_allow_ips`（且未覆写 `isDebugAuthorized()`）的部署
    不再收到内联的异常 message/file/line/trace；需要的人请配置白名单或覆写授权
  - `JsonResponse::isDebugAllowed()` 在 `app.debug = false` 时恒为 `false`（不再只由子类授权决定）
- **熔断响应不再默认回显异常文本**
  - `JsonResponse::circuitResponse()` 仅在披露判定通过时回显 `CircuitBreakerExceptionInterface::getMessage()`，
    否则返回固定文案「服务暂不可用，请稍后重试」；HTTP 503 与 `Retry-After` 保持不变
- **限流：Redis 不可用且无降级器时默认拒绝**（行为收紧）
  - `SlidingWindow` / `TokenBucket` 新增实例级策略，默认 `fail-closed`；可用性优先的入口显式
    `->failOpen()`。原先默认放行，且 `fail_closed` 只能在每次调用的 `$options` 里传（逐调用，易漏）
  - 兼容：单次调用仍可传 `['fail_closed' => true]`；已配置 `setFallbackLimiter()` 的路径（如 `wise-api`）
    行为不变
  - 影响：Redis 故障窗口内，未配降级器的入口会开始返回「拒绝」而不是静默放行
- **分布式锁：`isAcquired()` 统一为「占用」语义**（行为收紧）
  - redis / database 驱动不再回答「本实例是否持有」（原先与 file 驱动语义相反），统一为
    「该 key 是否被任意进程持有」；需要本实例视角请用新增的 `DistributedLock::isHeld()`
  - `LockInterface` 方法集保持三项不变，仅补充语义注释
  - 影响：`wise-crontab` 的 `SemaphoreLock::availableSlots()` / `TaskLock::isLocked()` 在 redis/database
    驱动下由错误结论变正确；依赖「本实例是否持有」的宿主请改用 `isHeld()`
- **RSA 私钥权限在写入内容前即强制为 0600**（行为收紧）
  - `EnvValueGenerator::rsaKeypair()` 改为「独占创建临时文件 → `chmod 0600` → 写内容 → 原子 rename」，
    并在 POSIX 上校验实际模式；**无法限制为 0600 时删除该文件并抛 `RuntimeException`**
  - 原先先 `file_put_contents`（0666 & ~umask）再 `@chmod`，且 chmod 结果被丢弃：默认 umask 下私钥
    短暂/长期为 0644 而安装流程仍报成功
  - 影响：Windows 的 `chmod` 无法限制读权限，安装侧需用 ACL 保证密钥目录仅服务账户可读
- **扩展点：安全相关扩展点的活跃变更需要宿主授权**（行为收紧 + 签名变更）
  - `ExtPoint::setActive()` 返回类型 `void` → `bool`；`auth.guard` / `jwt.provider` / `connector` /
    `exception.renderer`（`ExtPoint::SECURITY_RELEVANT_POINTS`）未获授权时返回 `false` 且不改动状态
  - 新增 `bindOverrideAuthorizer(callable $authorizer)`；**未绑定授权器时这些点默认拒绝**（fail-closed），
    授权器抛异常同样按拒绝处理
  - `persistOverrides()` 落盘前会校验「与已落盘状态不同」的安全点，避免「先改内存再持久化」绕过授权门
  - 宿主侧 `AppService` 已绑定授权器（仅已登录的后台管理员）；`Capability` 控制器现在会处理拒绝结果
- **上下文：请求世代变化时丢弃上一请求的上下文**（行为收紧）
  - 非协程常驻进程（Swoole 常驻 / Workerman / 队列 worker）所有请求共用一个桶，
    `RequestContext::setRequestId()` 检测到 request_id 变化时先清空该桶（`user`/`guard`/`tenant_id` 等）
  - `AuthManager` 的 Guard 实例缓存按同一世代失效，不再复用上一个请求的 Guard 与其 principal 缓存
  - 兼容：同一请求内记忆化不变；宿主每请求设置唯一 request_id（`RequestId` 中间件已满足）
- **JWT：凭证按 guard 分离 + 引擎故障不再锁存为访客**
  - `JwtManager::getEngine()` / `isReady()` 支持可选守卫名，`TokenGuard` 会传入自己的守卫名；
    `ExtPoint('jwt.provider')` 的实现可实现为 `fn(?string $guard = null)` 返回守卫专属引擎
    （wise-jwt 已支持：仅当该 guard 在 `wise-jwt.guards` 中配置过才切换，否则回退默认引擎）
  - `TokenGuard` 区分「引擎不可用」与「凭据非法」：前者不再锁存为「确定未登录」，同一请求内可重试；
    新增 opt-in `failClosedOnEngineUnavailable(true)` 让引擎故障返回 503（默认关闭，保持原行为）
  - 生效前提：需在 `wise-jwt.guards` 中为相应守卫配置独立密钥/issuer/audience
- **异常：诊断数据与公开数据分流**（新增 API）
  - 新增 `WiseException::withDiagnosticContext()/withDiagnosticContextMany()/getDiagnosticContext()`：
    诊断数据只进日志（`ErrorHandler::logException()` 的 `diagnostic` 字段），绝不进入响应体
  - `withContext()` 语义明确为「会出现在响应体」；SQL、路径、上游报文等请改用诊断通道
- **WS：缺省 `data` 时不再把整帧信封当业务 body**（行为收紧）
  - `MessageRouter` 交付处理器的业务 `data` 只取帧内 `data` 字段，缺省即空数组；帧顶层字段
    （`action`/`room`/客户端自选的 `user_id`）不再并入 body，权威字段始终由服务端推导

### Breaking

- **菜单契约：`MenuItemProvider::parent()` 被 `containers()` 取代**（不留兼容层，应用未发布）
  - `containers(): array` 支持**多个**分组容器，每项字段：`key` / `name` / `anchor` / `icon` / `sort` / `mount` / `description`
  - `anchor = 'console'`（默认）：在系统顶级「控制台」下创建**自持**容器（`plugin = ident`），随包卸载被连带清理
  - `anchor = '<已存在目录名>'`：挂靠既有目录，不创建、不拥有、不改其排序
  - `items()` 节点新增 `container` 键（归属容器，缺省归第一个；仅落库分组用，不入库）
  - 同步影响：`wise-member`（5 分组）、`wise-agent`（挂靠「插件管理」）已同步改造

### Added

- `MenuSchema::CONTAINER_KEY`：节点归属容器的键常量
- `Menu::resolveConsoleRoot()` / `Menu::resolveCatalogByName()` / `Menu::ensureContainer()`：容器锚点解析与幂等创建
- `MenuItemSync::prepareContainers()` / `groupByContainer()` / `resolveContainer()` / `skipResult()`（替换 `resolveParent()`）
- `ExtPoints::MENU_ITEM` 文档补充多容器语义

### Changed

- `MenuItemSync::sync()` 支持多容器：容器声明校验 → 菜单树归一 → 按 `container` 分组 → 逐容器落库；返回值新增 `containers`（容器数）
- `MenuItemSync::fingerprint()` 纳入 `containers()`（只改容器也会触发懒同步）
- 菜单读取排序统一为 `ORDER BY sort ASC, id ASC`（`children()` / `getTreeArray()` / `getTreeList()` / `buildTreeByMenuIds()` / `getPluginRoots()`）——并列时的顺序不再依赖数据库自然序

### Fixed

- 侧边栏菜单在 `sort` 并列时顺序不确定（原先仅按 `sort` 排序，无二级键；管理页 `getList()` 早有 `id` 兜底，前台缺失）
- `MessageRouter`：`action` 为 `ping`/`pong` 的帧可在未过鉴权预检的情况下进入业务路由
- `MessageRouter::dispatchHeartbeat()`：未认证连接可执行任意已注册 `ws.heartbeat` 实现（含闭包）
- `BusHandlerJob`：`handler_class` 完全由队列载荷决定，可实例化并调用任意进程内类
- `ErrorHandler::handleException()` / `DefaultExceptionRenderer::renderGeneric()`：仅凭 `app.debug`
  即向白名单外客户端回显异常 message/file/line/trace（与 `JsonResponse` 的白名单门槛不一致）
- `JsonResponse::circuitResponse()`：无条件把熔断异常 message（可能含上游/服务名等内部标识）写入 503 响应体
- `DistributedLock`（file 驱动）：`release()` / `__destruct()` 删除锁文件，使 flock 互斥失效
  （阻塞等待者锁住已删除 inode、新进程 `fopen('c+')` 锁住新 inode，同一 key 可被同时持有）；
  现在保留锁文件并保持 inode 稳定
- `SlidingWindow` / `TokenBucket`：Redis 不可用且无降级限流器时默认放行，限流静默失效
- `RateLimiter::hit()`（无 `inc()` 的非原子回退分支）：更新计数时用不带 TTL 的 `set()`
  抹掉首次写入的窗口 TTL，导致计数键永不过期、窗口永不重置
- `BusHandlerJob`：at-least-once 重投递会重复执行同一 `Message::id` 的非幂等副作用
  （新增以 id 为键的幂等守卫，`dedup_ttl` 默认 86400 秒，缓存通道不可用时退化为原语义）
- `EnvSeedService::mergeIntoEnv()`：先快照后加锁，且就地 `ftruncate` 提交，
  并发安装会互相覆盖、写入失败会毁掉既有 `.env`（改为锁内读快照 + 同目录临时文件原子 rename）
- `EnvValueGenerator::rsaKeypair()`：私钥权限依赖被丢弃返回值的 `@chmod`，默认 umask 下可为 0644
- `ExtPoint::setActive()` / `persistOverrides()`：安全相关扩展点（认证守卫、JWT 引擎、连接器、异常渲染器）
  的活跃实现可被无授权的调用方变更（现由 `bindOverrideAuthorizer()` 把关，未绑定时拒绝）
- `AuthManager`：Guard 实例与 principal 缓存跨请求复用，常驻运行时下一个请求可读到上一个请求的身份
- `TokenGuard`：JWT 引擎不可用被锁存为「确定未登录」；且不区分守卫，所有 guard 共用同一个引擎实例
- `JwtManager`：`getEngine()` 只能取默认引擎，多守卫场景无法按 guard 选择密钥 / issuer / audience
- `RequestContext`：常驻进程非协程路径下，上一个请求的 `user`/`guard`/`tenant_id` 会泄漏到下一个请求
- `WiseException`：唯一的 `context` 通道既进日志又经 `toArray()` 进入响应体，诊断信息无处安放
- `MessageRouter`：缺省 `data` 时整帧信封（含客户端自选 `user_id`）被当作业务 body 交给处理器

## [1.0.0] - 2024-01-01

### Added

- Initial release
