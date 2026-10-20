<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 包级 .env 密钥/配置播种契约
 *
 * 设计动机：
 *   wise-* 各包的配置大量依赖 env('XXX')，其中既有需要在安装时随机生成的内部密钥
 *   （如 JWT_SECRET、API_ENC_* 密钥对、设备指纹盐），也有需要用户手动填写的外部三方
 *   凭证（如 OPENAI_API_KEY、SMTP_PASS），还有静态默认值。过去这些键散落在各包的
 *   Publish 命令或安装命令的硬编码清单里，易漂移、无法表达语义。
 *
 *   本接口让每个包在其 ServiceProvider 上「声明」自己需要播种到 .env 的键值契约，
 *   安装命令（app/install）统一扫描并幂等合并，新增包/改键零改动核心安装代码。
 *
 * 为何是静态方法：
 *   安装早期框架尚未完全引导，ServiceProvider 继承自 think\Service，其构造函数要求
 *   App 实例，无法用 new $class() 实例化。因此约定为静态方法，安装侧通过
 *   is_subclass_of($class, EnvSeedProvider::class) 检测、再以 $class::envSeeds() 静态
 *   调用读取声明——全程不触发任何需要容器的逻辑。
 *
 * 契约项格式：
 *   'ENV_KEY' => [
 *       'type'      => 'secret' | 'external' | 'static',
 *       'generator' => 'random_bytes_32' | 'hmac_salt' | 'uuid' | 'rsa_2048_pem' | null,
 *       'keys'      => ['private' => 'API_ENC_PRIVATE_KEY', 'public' => 'API_ENC_PUBLIC_KEY'], // 仅 generator 返回数组时需要
 *       'default'   => string,   // 仅 static / external 使用
 *       'comment'   => string,   // 写入 .env 的注释说明
 *   ]
 *
 * 语义：
 *   - secret   ：安装时按 generator 生成真实随机值写入（仅当该键尚不存在——永不复写）。
 *                generator 返回数组（如 rsa_2048_pem 返回 ['private'=>..,'public'=>..]）时，
 *                经 'keys' 映射为真实 env 键名，逻辑键名（本项键）本身不写入。
 *   - external ：外部三方凭证（OpenAI、SMTP、微信等）。绝不自动生成假值，
 *                仅在缺失时写入一行带注释的空占位，提示用户手动填写。
 *   - static   ：静态默认值，缺失时写入 default。
 */
interface EnvSeedProvider
{
    /**
     * 返回本包需要播种到 .env 的键值契约。
     *
     * @return array<string,array{type:string,generator?:?string,keys?:array<string,string>,default?:string,comment?:string}>
     */
    public static function envSeeds(): array;
}
