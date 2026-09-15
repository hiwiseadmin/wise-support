<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 对象存储驱动契约（框架级）
 *
 * 设计动机：
 *   本契约描述的是「通用对象存储能力」（上传/下载/列举/可见性等），本身不含任何
 *   附件业务概念（不引用 AttachmentModel、分类、版本、关系表等）。
 *   但过去它被放在 wise-attachment 包内，导致任何想提供存储驱动的包/插件都必须
 *   依赖 wise-attachment 这个带迁移、模型、控制器、命令的重型业务包，形成
 *   「抽象依赖了实现细节」的倒置依赖。
 *
 *   现将其下沉到框架层，与本目录的 EnvSeedProvider、以及 ConnectorInterface /
 *   GuardInterface / ExceptionRendererInterface 保持同一惯例：
 *   **扩展点由 wise-support 发布，契约也由 wise-support 拥有，实现方只依赖契约。**
 *
 * 对应扩展点：ExtPoints::STORAGE_DRIVER（策略 single）
 *
 * 注册方式：
 * <pre>
 *   ExtPoint::register(ExtPoints::STORAGE_DRIVER, 'my_driver', MyDriver::class);
 *   // 或工厂闭包
 *   ExtPoint::register(ExtPoints::STORAGE_DRIVER, 'my_driver', fn(array $config) => new MyDriver($config));
 * </pre>
 *
 * 约定：
 *   - 实现类的构造函数接收驱动配置数组（来自 wise-attachment.drivers.{name}）
 *   - 所有路径参数均为「对象名」（相对路径），不含域名/桶名前缀
 *   - upload() 返回结构必须包含 url / path / size / mime_type / storage 五个键
 *
 * @package wise\support\contracts
 */
interface StorageDriverInterface
{
    /**
     * 上传文件到存储服务
     *
     * @param string $localPath  本地文件完整路径
     * @param string $objectName 存储对象名（相对路径）
     * @return array 标准化返回数据:
     *   - url:        string 访问 URL
     *   - path:       string 存储路径（即 objectName）
     *   - size:       int    文件大小（字节）
     *   - mime_type:  string MIME 类型
     *   - storage:    string 存储驱动标识
     *   - width:      int    图片宽度（非图片为 0）
     *   - height:     int    图片高度（非图片为 0）
     */
    public function upload(string $localPath, string $objectName): array;

    /**
     * 删除存储中的文件
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return bool 文件不存在时也应返回 true
     */
    public function delete(string $objectName): bool;

    /**
     * 获取文件访问 URL（支持图片处理参数）
     *
     * @param string $objectName 存储对象名（相对路径）
     * @param array  $options    可选参数:
     *   - width:   int    图片宽度
     *   - height:  int    图片高度
     *   - size:    string 预设尺寸 key（使用白名单配置）
     *   - quality: int    图片质量（0-100）
     * @return string
     */
    public function url(string $objectName, array $options = []): string;

    /**
     * 检查文件是否存在
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return bool
     */
    public function exists(string $objectName): bool;

    /**
     * 获取文件大小
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return int 文件大小（字节），不存在返回 0
     */
    public function size(string $objectName): int;

    /**
     * 获取文件 MIME 类型
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return string
     */
    public function mimeType(string $objectName): string;

    /**
     * 获取驱动标识名称
     *
     * @return string 如 'local', 'qiniu', 'alioss', 'cos'
     */
    public function getDriverName(): string;

    /**
     * 复制文件（同驱动内）
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function copy(string $source, string $destination): bool;

    /**
     * 移动/重命名文件（同驱动内）
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function move(string $source, string $destination): bool;

    /**
     * 列出目录下的文件（最多返回 1000 条）
     *
     * @param string $directory 目录路径，空字符串表示根目录
     * @param bool   $recursive 是否递归列出子目录
     * @return array 文件信息数组，每个元素包含 [key, size, last_modified, ...]
     */
    public function listFiles(string $directory = '', bool $recursive = false): array;

    /**
     * 创建目录
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function makeDirectory(string $directory): bool;

    /**
     * 删除目录（含子文件，需谨慎）
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function deleteDirectory(string $directory): bool;

    /**
     * 获取临时签名 URL（私有文件访问）
     *
     * @param string $objectName 存储对象名
     * @param int    $expires    过期秒数，默认 3600
     * @param array  $options    额外选项
     * @return string
     */
    public function temporaryUrl(string $objectName, int $expires = 3600, array $options = []): string;

    /**
     * 批量删除文件
     *
     * @param array $objectNames 对象名数组
     * @return array ['success' => int, 'failed' => int]
     */
    public function deleteMultiple(array $objectNames): array;

    /**
     * 获取文件最后修改时间（Unix 时间戳）
     *
     * @param string $objectName 存储对象名
     * @return int Unix 时间戳，不存在返回 0
     */
    public function lastModified(string $objectName): int;

    /**
     * 获取文件元数据（key-value 数组）
     *
     * @param string $objectName 存储对象名
     * @return array 如 ['size' => 1024, 'mime' => 'image/png', 'last_modified' => 1234567890, 'visibility' => 'public']
     */
    public function getMetadata(string $objectName): array;

    /**
     * 设置文件可见性
     *
     * @param string $objectName 存储对象名
     * @param string $visibility 'public' 或 'private'
     * @return bool
     */
    public function setVisibility(string $objectName, string $visibility): bool;

    /**
     * 获取文件可见性
     *
     * @param string $objectName 存储对象名
     * @return string 'public' 或 'private'
     */
    public function getVisibility(string $objectName): string;
}
