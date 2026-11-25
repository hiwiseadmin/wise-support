# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.5] - 2026-11-25

### Changed
- 异常详情默认不再回显；需白名单或显式授权才输出调试信息，熔断响应使用固定文案。
- 限流在 Redis 不可用且无降级器时改为拒绝；分布式锁 `isAcquired()` 统一表示「该 key 被占用」。
- 安全相关扩展点的切换需宿主授权，未绑定授权器时默认拒绝；常驻运行时请求上下文按世代隔离。
- JWT 引擎支持按守卫隔离；引擎不可用时不再被锁存为未登录。
- 菜单契约 `MenuItemProvider::parent()` 改为 `containers()`，支持多容器挂靠；相关模型需自行实现原 trait 钩子后调用对应同步方法。

### Fixed
- 修复 WebSocket 鉴权绕过、异步作业执行任意类、锁文件删除导致互斥失效、限流计数不过期等问题。
- 修复并发安装可能破坏 `.env`、私钥权限可能过宽，以及 JWT/上下文跨请求串号问题。

### Added
- `WiseException` 新增仅写日志的诊断上下文通道，避免敏感信息进入响应。

## [1.0.2] - 2024-01-01

- Initial release

[1.0.2]: https://github.com/hiwiseadmin/wise-support/releases/tag/v1.0.2

[Unreleased]: https://github.com/hiwiseadmin/wise-support/compare/v1.0.5...HEAD
[1.0.5]: https://github.com/hiwiseadmin/wise-support/releases/tag/v1.0.5
