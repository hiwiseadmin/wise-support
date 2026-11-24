<?php
namespace wise\support\tools;

/**
 * 压缩工具类 —— **已从宿主 `app\common\library` 迁入 wise-support**＼�
 *
 * 迁移动机：wise-market 的包校验（`PluginValidateService`）需要用归档校验能力，
 * 而「包 in 宿主 app 命名空间」属于跨层依赖 —— 包单独发布/安装时会 fatal。
 *
 * 收口方式（2026-10-13 定案，2026-10-15 并入 tools 命名空间）：**宿主原实现已删除，不留薄封装**——
 * 全部调用方（宿主插件管理页 `app\admin\controller\Plugin`、`DownloadService`、
 * wise-market 的 `PluginValidateService`）统一 `use wise\support\tools\ArchiveTool`。
 * 因此本类是归档能力的**唯一实现**，宿主侧不再存在 `app\common\library\ArchiveTool`。
 *
 * @see \app\admin\controller\Plugin
 * @see \app\common\service\DownloadService
 */

/**
 * 压缩工具类
 * 支持：ZIP, GZ, TAR, TAR.GZ, TAR.BZ2, BZ2, RAR
 * 方法：
 * - compress($source, $destination, $format = 'zip'): 压缩文件/目录（通用入口）
 * - compressDir($directory, $destination, $format = 'zip', $options = []): 压缩目录（推荐；
 *   可保留顶层目录、跳过 .git/node_modules、保留空目录、跳过符号链接）
 * - compressFiles($files, $destination, $format = 'zip'): 多文件直接压缩
 * - addFileToArchive($archive, $files, $format = 'zip'): 向已有压缩包追加文件
 * - extract($archive, $destination = null): 解压文件/目录
 * - extractSingleFile($archive, $innerPath, $destination = null): 解压单个指定文件
 * - listContents($archive): 列出压缩文件内容
 * - isSupportedFormat($format): 检查是否支持指定格式
 * - getSupportedFormats(): 获取支持的所有格式
 * - getErrors(): 获取错误信息
 * - getLastError(): 获取最后一条错误信息
 * - clearErrors(): 清空错误信息
 * - setPassword($password): 设置ZIP加密密码
 */

use ZipArchive;
use Phar;
use PharData;
use RarArchive;
use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

class ArchiveTool
{
    private array $supportedFormats = [];
    private array $errors = [];
    private array $extensions = [
        'zip' => ['zip'],
        'phar' => ['tar', 'tar.gz', 'tar.bz2', 'tar.xz', 'tar.Z'],
        'rar' => ['rar'],
        'gz'  => ['gz', 'tgz'],
        'bz2' => ['bz2', 'tbz2'],
    ];
    private $progressCallback = null;

    private ?string $password = null;

    private string $tempDir = '';
    
    /**
     * 配置项
     */
    private array $config = [
        'memory_limit' => '256M',
        'timeout' => 300,
        'max_extract_size' => 1024 * 1024 * 1024,
        'max_file_size' => 100 * 1024 * 1024,
        'max_file_count' => 10000,
        'allowed_paths' => [],
        'temp_dir' => null,
        'overwrite' => true,
        'block_symlinks' => true,
        'block_absolute_paths' => true,
        'block_traversal' => true,
        'max_filename_length' => 255,
    ];
    
    /**
     * 构造函数 - 检测支持的扩展
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);

        if (empty($this->config['allowed_paths'])) {
            $this->config['allowed_paths'] = [
                __DIR__ . '/data',
                __DIR__ . '/uploads',
                '/tmp',
                $this->getPlatformTempDir(),
            ];
        }
        if (!empty($this->config['temp_dir']) && is_dir($this->config['temp_dir'])) {
            $this->tempDir = $this->config['temp_dir'];
        } else {
            $this->tempDir = $this->getPlatformTempDir();
        }
        $this->config['temp_dir'] = $this->tempDir;

        $this->detectSupportedExtensions();
        
        $this->setMemoryLimit($this->config['memory_limit']);

        $this->setTimeLimit($this->config['timeout']);
    }
    
    /**
     * 快速验证（仅检查文件头）
     */
    public function quickValidate(string $archive): bool
    {
        return $this->validateOnly($archive, false);
    }

    /**
     * 验证压缩文件是否有效
     * @param string $archive 压缩文件路径
     * @param bool $checkContents 是否检查内容（深度验证）
     * @return bool 是否有效
     */
    public function validateOnly(string $archive, bool $checkContents = true): bool
    {
        $this->errors = [];

        if (!file_exists($archive)) {
            $this->errors[] = "压缩文件不存在: {$archive}";
            return false;
        }

        if (!is_file($archive)) {
            $this->errors[] = "路径不是文件: {$archive}";
            return false;
        }

        if (!is_readable($archive)) {
            $this->errors[] = "文件不可读: {$archive}";
            return false;
        }

        $fileSize = filesize($archive);
        if ($fileSize === false) {
            $this->errors[] = "无法获取文件大小: {$archive}";
            return false;
        }

        if ($fileSize > $this->config['max_extract_size']) {
            $this->errors[] = sprintf(
                "压缩文件过大: %s (限制: %s)", 
                $this->formatBytes($fileSize),
                $this->formatBytes($this->config['max_extract_size'])
            );
            return false;
        }

        $format = $this->detectArchiveFormat($archive);
        if (!$format) {
            $this->errors[] = '不支持的压缩格式';
            return false;
        }

        $formatType = $this->getFormatType($format);
        
        if (!$this->isFormatSupported($formatType)) {
            $this->errors[] = "PHP环境不支持 {$format} 格式";
            return false;
        }

        try {
            switch ($formatType) {
                case 'zip':
                    return $this->validateZip($archive, $checkContents);
                case 'rar':
                    return $this->validateRar($archive, $checkContents);
                case 'gz':
                    return $this->validateGz($archive, $checkContents);
                case 'bz2':
                    return $this->validateBz2($archive, $checkContents);
                case 'phar':
                    return $this->validatePhar($archive, $checkContents);
                default:
                    $this->errors[] = "未知的压缩格式: {$format}";
                    return false;
            }
        } catch (Exception $e) {
            $this->errors[] = '验证失败: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 设置配置项
     * @param string $key 配置项键名
     * @param mixed $value 配置项值
     * @return bool 是否成功
     */
    public function setConfig($key, $value)
    {
        if (array_key_exists($key, $this->config)) {
            $this->config[$key] = $value;
            return true;
        }
        return false;
    }
    
    /**
     * 获取配置项
     * @param string $key 配置项键名（可选）
     * @return mixed 配置项值或所有配置项数组
     */
    public function getConfig(string $key = '')
    {
        if ($key === '') {
            return $this->config;
        }
        return $this->config[$key] ?? null;
    }

    /**
     * 压缩文件/目录（通用入口）
     *
     * 目录请优先使用 {@see compressDir()}：本方法对目录的语义是
     * "把目录内容打到包根"（不含顶层目录名）；需要解压后得到 `demo/...`
     * 这样的顶层目录时必须走 compressDir(..., ['include_root' => true])。
     *
     * @param string $source 源文件/目录路径
     * @param string $destination 目标压缩文件路径
     * @param string $format 压缩格式 (zip, tar.gz, tar.bz2 等)
     * @return bool 是否成功
     */
    public function compress($source, $destination, $format = 'zip')
    {
        $this->errors = [];

        $formatType = $this->getFormatType($format);
        if (!$this->isFormatSupported($formatType)) {
            $this->errors[] = "不支持 {$format} 格式的压缩";
            return false;
        }

        if (!file_exists($source) || !is_readable($source)) {
            $this->errors[] = "源路径不存在或不可读: {$source}";
            return false;
        }

        try {
            if (is_dir($source)) {
                return $this->compressDir($source, $destination, $format, ['include_root' => false]);
            }

            switch ($formatType) {
                case 'zip':
                    return $this->compressZip($source, $destination);
                case 'phar':
                    return $this->compressTar($source, $destination, $format);
                case 'rar':
                    $this->errors[] = 'RAR 格式仅支持解压和读取，不支持创建压缩包。请使用 ZIP 或 TAR 格式替代。';
                    return false;
                case 'gz':
                case 'bz2':
                    $this->errors[] = "{$format} 格式不支持独立压缩，请改用 zip 或 tar.gz / tar.bz2";
                    return false;
                default:
                    $this->errors[] = "不支持的压缩格式: {$format}";
                    return false;
            }
        } catch (\Throwable $e) {
            if (is_file($destination)) {
                @unlink($destination);
            }

            $this->errors[] = "压缩失败: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * 解压文件
     * @param string $archive 压缩文件路径
     * @param string $destination 解压目标目录
     * @return bool 是否成功
     */
    public function extract($archive, $destination)
    {
        $this->errors = [];

        if (!file_exists($archive)) {
            $this->errors[] = "压缩文件不存在: {$archive}";
            return false;
        }
        
        $format = $this->detectArchiveFormat($archive);
        if (!$format) {
            $this->errors[] = "无法识别的压缩文件格式";
            return false;
        }
        
        $formatType = $this->getFormatType($format);
        if (!$this->isFormatSupported($formatType)) {
            $this->errors[] = "不支持 {$format} 格式的解压";
            return false;
        }
        
        try {
            if (!is_dir($destination)) {
                if (!mkdir($destination, 0755, true)) {
                    $this->errors[] = "无法创建目标目录";
                    return false;
                }
            }
            
            switch ($formatType) {
                case 'zip':
                    return $this->extractZip($archive, $destination);
                case 'phar':
                    return $this->extractPhar($archive, $destination);
                case 'gz':
                    return $this->extractGz($archive, $destination);
                case 'bz2':
                    return $this->extractBz2($archive, $destination);
                case 'rar':
                    return $this->extractRar($archive, $destination);
                default:
                    $this->errors[] = "不支持的压缩格式: {$format}";
                    return false;
            }
        } catch (Exception $e) {
            $this->errors[] = "解压失败: " . $e->getMessage();
            return false;
        }
    }

    /**
     * 从压缩包中解压单个指定文件
     *
     * @param string      $archive     压缩包路径
     * @param string      $innerPath   压缩包内的文件路径（如 'dir/file.txt'）
     * @param string|null $destination 解压到目标路径（null 则返回文件内容字符串）
     * @return string|bool 成功返回内容字符串（未指定 destination）或 true（指定了路径），失败返回 false
     */
    public function extractSingleFile(string $archive, string $innerPath, ?string $destination = null)
    {
        $this->errors = [];

        if (!file_exists($archive)) {
            $this->errors[] = "压缩包不存在: {$archive}";
            return false;
        }

        $format = $this->detectArchiveFormat($archive);
        $formatType = $this->getFormatType($format);

        try {
            switch ($formatType) {
                case 'zip':
                    return $this->extractZipSingle($archive, $innerPath, $destination);
                case 'phar':
                    return $this->extractPharSingle($archive, $innerPath, $destination);
                default:
                    $this->errors[] = "{$format} 格式不支持单文件解压";
                    return false;
            }
        } catch (Exception $e) {
            $this->errors[] = "单文件解压失败: " . $e->getMessage();
            return false;
        }
    }

    /**
     * 设置进度回调函数
     * @param callable $callback 进度回调函数
     * @return self
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * 检查是否支持指定格式
     * @param string $format 压缩格式
     * @return bool 是否支持
     */
    public function isFormatSupported($format)
    {
        $formatType = $this->getFormatType($format);
        return isset($this->supportedFormats[$formatType]) && 
               $this->supportedFormats[$formatType] === true;
    }
    
    /**
     * 获取支持的所有格式
     * @return array 支持的格式列表
     */
    public function getSupportedFormats()
    {
        $formats = [];
        foreach ($this->supportedFormats as $type => $supported) {
            if ($supported && isset($this->extensions[$type])) {
                $formats = array_merge($formats, $this->extensions[$type]);
            }
        }
        return array_unique($formats);
    }
    
    /**
     * 获取错误信息
     * @return array 错误信息列表
     */
    public function getErrors()
    {
        return $this->errors;
    }
    
    /**
     * 获取最后一条错误信息
     * @return string 最后一条错误信息
     */
    public function getLastError()
    {
        return end($this->errors) ?: '';
    }
    
    /**
     * 清空错误信息
     */
    public function clearErrors()
    {
        $this->errors = [];
    }

    /**
     * 设置ZIP加密密码
     * @param string $password 密码（留空则不加密）
     * @return self
     */
    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    /**
     * 获取当前设置的密码
     * @return string|null
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * 多文件直接压缩（无需临时目录中转）
     *
     * 将散落在不同目录的多个文件直接压缩为一个包。
     * 相比 compress() + 临时目录方案：省去 copy/symlink 开销、无磁盘翻倍风险、无残留清理问题。
     *
     * @param array  $files      文件列表，每项格式：
     *                           - ['path' => '/abs/path/file.txt', 'name' => 'file.txt']  指定包内名称
     *                           - '/abs/path/file.txt'  自动使用 basename 作为包内名称
     * @param string $destination 目标压缩文件路径
     * @param string $format      压缩格式 (zip, tar.gz, tar.bz2 等)
     * @return bool 是否成功
     */
    public function compressFiles(array $files, string $destination, string $format = 'zip'): bool
    {
        $this->errors = [];

        if (empty($files)) {
            $this->errors[] = '文件列表不能为空';
            return false;
        }

        $normalizedFiles = [];
        foreach ($files as $file) {
            if (is_string($file)) {
                $realPath = realpath($file);
                if ($realPath === false || !is_file($realPath)) {
                    $this->errors[] = "文件不存在或不可读: {$file}";
                    continue;
                }
                $normalizedFiles[] = [
                    'path' => $realPath,
                    'name' => basename($file),
                ];
            } elseif (is_array($file) && isset($file['path'])) {
                $realPath = realpath($file['path']);
                if ($realPath === false || !is_file($realPath)) {
                    $this->errors[] = "文件不存在或不可读: {$file['path']}";
                    continue;
                }
                $normalizedFiles[] = [
                    'path' => $realPath,
                    'name' => $file['name'] ?? basename($file['path']),
                ];
            } else {
                $this->errors[] = '无效的文件项格式';
                continue;
            }
        }

        if (empty($normalizedFiles)) {
            return false;
        }

        $formatType = $this->getFormatType($format);
        if (!$this->isFormatSupported($formatType)) {
            $this->errors[] = "不支持 {$format} 格式的压缩";
            return false;
        }

        try {
            switch ($formatType) {
                case 'zip':
                    return $this->compressZipFiles($normalizedFiles, $destination);
                case 'phar':
                    return $this->compressPharFiles($normalizedFiles, $destination, $format);
                case 'rar':
                    $this->errors[] = 'RAR 格式不支持多文件直接压缩，请使用 ZIP 格式';
                    return false;
                default:
                    $this->errors[] = "不支持的格式: {$format}";
                    return false;
            }
        } catch (Exception $e) {
            $this->errors[] = "压缩失败: " . $e->getMessage();
            return false;
        }
    }

    /**
     * 向已有压缩包追加文件或目录
     *
     * @param string $archive   已存在的压缩包路径
     * @param array  $files     文件列表（格式同 compressFiles）
     * @param string $format    压缩格式（可选，默认自动检测）
     * @return bool 是否成功
     */
    public function addFileToArchive(string $archive, array $files, ?string $format = null): bool
    {
        $this->errors = [];

        if (!file_exists($archive)) {
            $this->errors[] = "压缩包不存在: {$archive}";
            return false;
        }

        if (empty($files)) {
            $this->errors[] = '文件列表不能为空';
            return false;
        }

        if ($format === null) {
            $format = $this->detectArchiveFormat($archive);
            if (!$format) {
                $this->errors[] = '无法检测压缩包格式，请手动指定 format 参数';
                return false;
            }
        }

        $formatType = $this->getFormatType($format);

        try {
            switch ($formatType) {
                case 'zip':
                    return $this->addFilesToZip($archive, $files);
                case 'phar':
                    $this->errors[] = 'TAR 系列格式不支持追加文件，请重新使用 compressFiles() 创建新包';
                    return false;
                default:
                    $this->errors[] = "{$format} 格式不支持追加文件";
                    return false;
            }
        } catch (Exception $e) {
            $this->errors[] = "追加文件失败: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * 列出压缩文件内容
     * @param string $archive 压缩文件路径
     * @return array 压缩文件内容列表
     */
    public function listContents($archive)
    {
        $this->errors = [];
        
        if (!file_exists($archive)) {
            $this->errors[] = "压缩文件不存在";
            return false;
        }
        
        $format = $this->detectArchiveFormat($archive);
        $formatType = $this->getFormatType($format);
        
        switch ($formatType) {
            case 'zip':
                return $this->listZipContents($archive);
            case 'phar':
                return $this->listPharContents($archive);
            case 'rar':
                return $this->listRarContents($archive);
            default:
                $this->errors[] = "不支持列出该格式的内容";
                return false;
        }
    }


    /**
     * 验证ZIP文件是否有效
     * @param string $file ZIP文件路径
     * @param bool $checkContents 是否检查文件内容
     * @return bool 是否有效
     */ 
    private function validateZip(string $file, bool $checkContents): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZIP扩展未安装');
        }

        $zip = new ZipArchive();
        $result = $zip->open($file, ZipArchive::RDONLY);
        
        if ($result !== true) {
            $errorMap = [
                ZipArchive::ER_NOENT => '文件不存在',
                ZipArchive::ER_NOZIP => '不是ZIP文件',
                ZipArchive::ER_INCONS => 'ZIP文件不一致',
                ZipArchive::ER_CRC => 'CRC校验失败',
            ];
            $errorMsg = $errorMap[$result] ?? "无法打开ZIP文件 (错误码: {$result})";
            throw new Exception($errorMsg);
        }

        $totalSize = 0;
        $fileCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) { 
            $stat = $zip->statIndex($i);
            
            if (!$this->validatePath($stat['name'])) {
                $zip->close();
                throw new Exception("文件名不安全: {$stat['name']}");
            }

            if ($checkContents) {
                if ($stat['size'] > $this->config['max_file_size']) {
                    $zip->close();
                    throw new Exception(sprintf(
                        "ZIP单文件过大: %s (限制: %s)", 
                        $this->formatBytes($stat['size']),
                        $this->formatBytes($this->config['max_file_size'])
                    ));
                }

                $totalSize += $stat['size'];
                if ($totalSize > $this->config['max_extract_size']) {
                    $zip->close();
                    throw new Exception(sprintf(
                        "ZIP总大小超限: %s (限制: %s)", 
                        $this->formatBytes($totalSize),
                        $this->formatBytes($this->config['max_extract_size'])
                    ));
                }

                $fileCount++;
                if ($fileCount > $this->config['max_file_count']) {
                    $zip->close();
                    throw new Exception(sprintf(
                        "文件数量超限: %d (限制: %d)", 
                        $fileCount,
                        $this->config['max_file_count']
                    ));
                }
            }
        }

        $zip->close();
        return true;
    }

    /**
     * 验证Phar/TAR文件是否有效
     * @param string $file Phar/TAR文件路径
     * @param bool $checkContents 是否检查文件内容
     * @return bool 是否有效
     */
    private function validatePhar(string $file, bool $checkContents): bool
    {
        if (!class_exists('PharData')) {
            throw new Exception('PHAR扩展未安装');
        }

        try {
            $phar = new PharData($file);
        } catch (Exception $e) {
            throw new Exception('无法打开TAR文件: ' . $e->getMessage());
        }

        $fileCount = 0;
        $totalSize = 0;

        foreach (new RecursiveIteratorIterator($phar) as $fileInfo) {
            if (!$this->validatePath($fileInfo->getPathname())) {
                throw new Exception("文件路径不安全: {$fileInfo->getPathname()}");
            }

            if ($checkContents) {
                if ($this->config['block_symlinks'] && $fileInfo->isLink()) {
                    throw new Exception("包含符号链接: {$fileInfo->getPathname()}");
                }

                $fileSize = $fileInfo->getSize();
                
                if ($fileSize > $this->config['max_file_size']) {
                    throw new Exception(sprintf(
                        "TAR单文件过大: %s (限制: %s)", 
                        $this->formatBytes($fileSize),
                        $this->formatBytes($this->config['max_file_size'])
                    ));
                }

                $fileCount++;
                if ($fileCount > $this->config['max_file_count']) {
                    throw new Exception(sprintf(
                        "文件数量超限: %d (限制: %d)", 
                        $fileCount,
                        $this->config['max_file_count']
                    ));
                }

                $totalSize += $fileSize;
                if ($totalSize > $this->config['max_extract_size']) {
                    throw new Exception(sprintf(
                        "总大小超限: %s (限制: %s)", 
                        $this->formatBytes($totalSize),
                        $this->formatBytes($this->config['max_extract_size'])
                    ));
                }
            }
        }
        
        return true;
    }

    /**
     * 验证RAR文件是否有效
     * @param string $file RAR文件路径
     * @param bool $checkContents 是否检查文件内容
     * @return bool 是否有效
     */
    private function validateRar(string $file, bool $checkContents): bool
    {
        if (!class_exists('RarArchive')) {
            throw new Exception('RAR扩展未安装');
        }

        $rar = \RarArchive::open($file);
        if (!$rar) {
            throw new Exception('无法打开RAR文件');
        }

        try {
            $entries = $rar->getEntries();
            if ($entries === false) {
                throw new Exception('无法获取RAR文件列表');
            }

            $fileCount = 0;
            $totalSize = 0;

            foreach ($entries as $entry) {
                if (!$this->validatePath($entry->getName())) {
                    $rar->close();
                    throw new Exception("文件名不安全: {$entry->getName()}");
                }

                if ($checkContents) {
                    $fileSize = $entry->getUnpackedSize();
                    
                    if ($fileSize > $this->config['max_file_size']) {
                        $rar->close();
                        throw new Exception(sprintf(
                            "RAR单文件过大: %s (限制: %s)", 
                            $this->formatBytes($fileSize),
                            $this->formatBytes($this->config['max_file_size'])
                        ));
                    }

                    $fileCount++;
                    if ($fileCount > $this->config['max_file_count']) {
                        $rar->close();
                        throw new Exception(sprintf(
                            "文件数量超限: %d (限制: %d)", 
                            $fileCount,
                            $this->config['max_file_count']
                        ));
                    }

                    $totalSize += $fileSize;
                    if ($totalSize > $this->config['max_extract_size']) {
                        $rar->close();
                        throw new Exception(sprintf(
                            "总大小超限: %s (限制: %s)", 
                            $this->formatBytes($totalSize),
                            $this->formatBytes($this->config['max_extract_size'])
                        ));
                    }
                }
            }
            
            $rar->close();
            return true;
        } catch (Exception $e) {
            if (isset($rar)) {
                $rar->close();
            }
            throw $e;
        }
    }

    /**
     * 验证GZ文件是否有效
     * @param string $file GZ文件路径
     * @param bool $checkContents 是否检查文件内容
     * @return bool 是否有效
     */
    private function validateGz(string $file, bool $checkContents): bool
    {
        if (!function_exists('gzopen')) {
            throw new Exception('Zlib扩展未安装');
        }

        $fp = @gzopen($file, 'rb');
        if ($fp === false) {
            throw new Exception('无法打开GZ文件，可能已损坏');
        }

        if ($checkContents) {
            $buffer = gzread($fp, 4096);
            if ($buffer === false) {
                gzclose($fp);
                throw new Exception('GZ文件读取失败，可能已损坏');
            }
        }

        gzclose($fp);
        return true;
    }

    /**
     * 验证BZ2文件
     * @param string $file 压缩文件路径
     * @param bool $checkContents 是否检查内容
     * @return bool 是否有效
     */
    private function validateBz2(string $file, bool $checkContents): bool
    {
        if (!function_exists('bzopen')) {
            throw new Exception('Bzip2扩展未安装');
        }

        $fp = @bzopen($file, 'r');
        if ($fp === false) {
            throw new Exception('无法打开BZ2文件，可能已损坏');
        }

        if ($checkContents) {
            $buffer = bzread($fp, 4096);
            if ($buffer === false) {
                bzclose($fp);
                throw new Exception('BZ2文件读取失败，可能已损坏');
            }
        }

        bzclose($fp);
        return true;
    }

    /**
     * 设置超时时间
     * @param int $seconds 超时时间（秒）
     */
    private function setTimeLimit($seconds = 300)
    {
        $this->config['timeout'] = $seconds;
        set_time_limit($seconds);
    }

    /**
     * 设置内存限制
     * @param string $limit 内存限制（如 '256M'）
     */
    private function setMemoryLimit($limit = '256M')
    {
        $this->config['memory_limit'] = $limit;
        ini_set('memory_limit', $limit);
    }

    /**
     * 检测支持的扩展
     */
    private function detectSupportedExtensions()
    {
        $this->supportedFormats = [
            'zip' => class_exists('ZipArchive'),
            'gz' => extension_loaded('zlib'),
            'bz2' => extension_loaded('bz2'),
            'phar' => class_exists('PharData'),
            'rar' => class_exists('RarArchive'),
        ];
    }
    
    /**
     * 压缩单个文件为 ZIP
     *
     * @param string $source 源文件（若传入目录会转交 compressDir）
     * @param string $destination 目标压缩文件路径
     * @return bool 是否成功
     */
    private function compressZip($source, $destination)
    {
        if (is_dir($source)) {
            return $this->compressDir($source, $destination, 'zip', ['include_root' => false]);
        }

        if (!is_file($source)) {
            $this->errors[] = "源文件不存在: {$source}";
            return false;
        }

        if (!class_exists('ZipArchive')) {
            $this->errors[] = 'ZIP扩展未安装';
            return false;
        }

        if (file_exists($destination) && !@unlink($destination)) {
            $this->errors[] = "无法覆盖已存在的压缩包: {$destination}";
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            $this->errors[] = "无法创建 ZIP 文件: {$destination}";
            return false;
        }

        $added = $zip->addFile($source, basename($source));
        $zip->close();

        if (!$added) {
            @unlink($destination);
            $this->errors[] = "添加文件失败: {$source}";
            return false;
        }

        return $this->isUsableArchive($destination);
    }


    /**
     * 判断某个文件名/目录名是否命中 skip 规则。
     *
     * 语义（向后兼容）：
     *  - 不含通配符 → **精确匹配**（与旧行为一致）；
     *  - 含 `*` / `?` / `[abc]` → 按 glob 匹配，**大小写不敏感**（Windows 与 Linux 结果一致）；
     *  - 只匹配 basename，因此 `*.log` 对任意层级的文件都生效，目录名命中则整棵子树跳过。
     *
     * 为什么不用 `fnmatch()`：该函数在 Windows 上不可用（本项目需跨平台）。
     *
     * @param array<int, string> $patterns
     */
    public static function matchesSkipPattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;

            if ($pattern === '') {
                continue;
            }

            if (strpbrk($pattern, '*?[') === false) {
                if ($name === $pattern) {
                    return true;
                }

                continue;
            }

            $regex = self::globToRegex($pattern);

            if ($regex !== null && preg_match($regex, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /** glob → 正则 的编译缓存（skip 在目录遍历中被逐条调用，避免重复编译） */
    private static array $globRegexCache = [];

    /**
     * 把 glob 转成大小写不敏感的正则；非法模式返回 null（等价于不匹配）。
     */
    private static function globToRegex(string $glob): ?string
    {
        if (isset(self::$globRegexCache[$glob])) {
            return self::$globRegexCache[$glob];
        }

        $out = '';
        $len = strlen($glob);

        for ($i = 0; $i < $len; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                $out .= '.*';
                continue;
            }

            if ($char === '?') {
                $out .= '.';
                continue;
            }

            if ($char === '[') {
                $end = strpos($glob, ']', $i + 1);

                if ($end !== false && $end > $i + 1) {
                    $class  = substr($glob, $i + 1, $end - $i - 1);
                    $negate = $class[0] === '!' || $class[0] === '^';

                    if ($negate) {
                        $class = substr($class, 1);
                    }

                    $class = str_replace(['\\', ']'], ['\\\\', '\]'], $class);

                    $out .= '[' . ($negate ? '^' : '') . $class . ']';
                    $i = $end;
                    continue;
                }

                $out .= '\[';
                continue;
            }

            $out .= preg_quote($char, '~');
        }

        $regex = '~^' . $out . '$~i';

        if (@preg_match($regex, '') === false) {
            $regex = '~^' . preg_quote($glob, '~') . '$~i';
        }

        return self::$globRegexCache[$glob] = $regex;
    }

    /**
     * 压缩目录（推荐入口）
     *
     * 相对旧 `addDirectoryToZip()` 的修复点：
     *   1. **相对路径基准**：以 `realpath($directory)` 为唯一基准计算条目名，并把
     *      Windows 反斜杠统一成 `/`；旧实现用 `strlen($directory)` 去截 `getRealPath()`，
     *      入参与 realpath 形式不一致时（相对路径 / 短文件名）会写出 `9644\a.txt`
     *      这类垃圾目录名，甚至把绝对路径前缀打进包里。
     *   2. **失败即失败**：任一条目添加失败整体返回 false 并删除半成品包；
     *      旧实现在源不存在时也会"静默成功"，产出空包。
     *   3. **可控内容**：可保留/剥离顶层目录、跳过 `.git`/`node_modules` 等、
     *      保留空目录、跳过符号链接（避免把目录外文件打进包）。
     *
     * @param string $directory   源目录
     * @param string $destination 目标压缩包路径
     * @param string $format      zip / tar / tar.gz / tar.bz2
     * @param array{
     *     include_root?:bool, root_name?:string, skip?:string[], skip_links?:bool,
     *     preserve_empty_dirs?:bool, level?:int, overwrite?:bool
     * } $options
     * @return bool 是否成功（失败原因见 {@see getErrors()}）
     */
    public function compressDir(string $directory, string $destination, string $format = 'zip', array $options = []): bool
    {
        $this->errors = [];

        $options = array_merge([
            'include_root'        => true,
            'root_name'           => '',
            'skip'                => [],
            'skip_links'          => true,
            'preserve_empty_dirs' => true,
            'level'               => 6,
            'overwrite'           => true,
        ], $options);

        $formatType = $this->getFormatType($format);

        if (!in_array($formatType, ['zip', 'phar'], true) || !$this->isFormatSupported($formatType)) {
            $this->errors[] = "不支持 {$format} 格式的目录压缩";
            return false;
        }

        if (is_file($destination) && !$options['overwrite']) {
            $this->errors[] = "目标压缩包已存在: {$destination}";
            return false;
        }

        $entries = $this->collectDirectoryEntries($directory, $options);

        if ($this->errors !== []) {
            return false;
        }

        $parent = dirname($destination);

        if ($parent !== '' && $parent !== '.' && !is_dir($parent)
            && !mkdir($parent, 0755, true) && !is_dir($parent)
        ) {
            $this->errors[] = "无法创建目标目录: {$parent}";
            return false;
        }

        try {
            $ok = $formatType === 'zip'
                ? $this->writeZipEntries($destination, $entries, $options)
                : $this->writeTarEntries($destination, $entries, $format);
        } catch (\Throwable $e) {
            $this->errors[] = '目录压缩失败: ' . $e->getMessage();
            $ok = false;
        }

        if (!$ok || !$this->isUsableArchive($destination)) {
            @unlink($destination);
            return false;
        }

        return true;
    }

    /**
     * 收集目录条目（只读元信息，不读文件内容）
     *
     * 相对路径一律以 realpath 基准计算并统一为 `/`，保证包内路径可跨平台还原。
     *
     * @param array<string, mixed> $options compressDir 的选项
     * @return array{files: array<int, array{path:string, name:string}>, emptyDirs: string[]}
     */
    protected function collectDirectoryEntries(string $directory, array $options): array
    {
        $result = ['files' => [], 'emptyDirs' => []];

        $base = realpath($directory);

        if ($base === false || !is_dir($base) || !is_readable($base)) {
            $this->errors[] = "目录不存在或不可读: {$directory}";
            return $result;
        }

        $rootName = str_replace('\\', '/', (string) $options['root_name']);
        if ($rootName === '') {
            $rootName = basename($base);
        }

        $prefix = $options['include_root'] ? trim($rootName, '/') . '/' : '';
        $skip   = array_values(array_filter(array_map('strval', (array) $options['skip']), static function ($name) {
            return $name !== '';
        }));

        $skipLinks = (bool) $options['skip_links'];

        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $current) use ($skip, $skipLinks): bool {
                if ($skipLinks && $current->isLink()) {
                    return false;
                }

                return !self::matchesSkipPattern($current->getFilename(), $skip);
            }
        );

        $files      = [];
        $dirs       = [];
        $hasChild   = [];
        $baseLength = strlen($base);

        foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $real = $item->getRealPath();

            if ($real === false || strlen($real) <= $baseLength) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($real, $baseLength + 1));

            if ($item->isDir()) {
                $dirs[$relative] = true;
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $files[] = ['path' => $real, 'name' => $prefix . $relative];

            $parent = strpos($relative, '/') === false ? '' : substr($relative, 0, (int) strrpos($relative, '/'));

            while ($parent !== '' && !isset($hasChild[$parent])) {
                $hasChild[$parent] = true;
                $parent = strpos($parent, '/') === false ? '' : substr($parent, 0, (int) strrpos($parent, '/'));
            }
        }

        if ($options['preserve_empty_dirs']) {
            foreach (array_keys($dirs) as $relative) {
                if (!isset($hasChild[$relative])) {
                    $result['emptyDirs'][] = $prefix . $relative . '/';
                }
            }
        }

        $result['files'] = $files;

        return $result;
    }

    /**
     * 写 ZIP 条目
     *
     * @param array{files: array<int, array{path:string, name:string}>, emptyDirs: string[]} $entries
     * @param array<string, mixed> $options
     */
    protected function writeZipEntries(string $destination, array $entries, array $options): bool
    {
        if (!class_exists('ZipArchive')) {
            $this->errors[] = 'ZIP扩展未安装';
            return false;
        }

        if (file_exists($destination) && !@unlink($destination)) {
            $this->errors[] = "无法覆盖已存在的压缩包: {$destination}";
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            $this->errors[] = "无法创建 ZIP 文件: {$destination}";
            return false;
        }

        $level = (int) $options['level'];
        $ok    = true;

        foreach ($entries['emptyDirs'] as $dir) {
            $zip->addEmptyDir($dir);
        }

        foreach ($entries['files'] as $file) {
            if (!$zip->addFile($file['path'], $file['name'])) {
                $this->errors[] = "添加文件失败: {$file['name']}";
                $ok = false;
                continue;
            }

            if ($level > 0 && method_exists($zip, 'setCompressionName')) {
                try {
                    $zip->setCompressionName($file['name'], ZipArchive::CM_DEFLATE, min(9, $level));
                } catch (\Throwable $e) {
                }
            }
        }

        $zip->close();

        return $ok;
    }

    /**
     * 写 TAR / TAR.GZ / TAR.BZ2 条目
     *
     * @param array{files: array<int, array{path:string, name:string}>, emptyDirs: string[]} $entries
     * @param string $format tar / tar.gz / tar.bz2（也接受 tgz / tbz2）
     */
    protected function writeTarEntries(string $destination, array $entries, string $format): bool
    {
        if (!class_exists('PharData')) {
            $this->errors[] = 'Phar 扩展未安装，无法处理 TAR 格式';
            return false;
        }

        $tempTar = tempnam($this->config['temp_dir'], 'tar_') . '.tar';

        try {
            if (file_exists($tempTar)) {
                @unlink($tempTar);
            }

            $phar = new PharData($tempTar);

            foreach ($entries['emptyDirs'] as $dir) {
                $phar->addEmptyDir($dir);
            }

            foreach ($entries['files'] as $file) {
                $phar->addFile($file['path'], $file['name']);
            }

            if ($format === 'tar') {
                $this->replaceArchiveFile($tempTar, $destination);
                return true;
            }

            $isGz = in_array($format, ['tar.gz', 'tgz'], true);

            if (!$isGz && !Phar::canCompress(Phar::BZ2)) {
                $this->errors[] = '服务器不支持 BZ2 压缩';
                return false;
            }

            $phar->compress($isGz ? Phar::GZ : Phar::BZ2);
            unset($phar);

            $this->replaceArchiveFile($tempTar . ($isGz ? '.gz' : '.bz2'), $destination);

            return true;
        } catch (\Throwable $e) {
            $this->errors[] = 'TAR 压缩失败: ' . $e->getMessage();
            return false;
        } finally {
            foreach (glob($tempTar . '*') ?: [] as $tmp) {
                @unlink($tmp);
            }
        }
    }

    /**
     * 把生成的压缩包移动到目标位置（覆盖同名文件；跨盘时退化为 copy + unlink）
     *
     * @throws Exception 源文件不存在或写入失败
     */
    protected function replaceArchiveFile(string $source, string $destination): void
    {
        if (!file_exists($source)) {
            throw new Exception("压缩包未生成: {$source}");
        }

        if (file_exists($destination)) {
            @unlink($destination);
        }

        if (!@rename($source, $destination)) {
            if (!@copy($source, $destination)) {
                throw new Exception("无法写入目标压缩包: {$destination}");
            }

            @unlink($source);
        }
    }

    /**
     * 压缩产物最小校验：文件存在且非空
     *
     * 刻意不做扩展名/格式识别 —— 调用方常把包写成自定义后缀（如 `.pkg`）。
     */
    protected function isUsableArchive(string $archive): bool
    {
        clearstatcache(true, $archive);

        if (!is_file($archive) || (int) filesize($archive) === 0) {
            $this->errors[] = "压缩包未生成或为空: {$archive}";
            return false;
        }

        return true;
    }


    /**
     * ZIP 多文件直接压缩
     * @param array  $files       标准化后的文件列表 [['path'=>.., 'name'=>..], ...]
     * @param string $destination 目标 ZIP 路径
     * @return bool
     */
    private function compressZipFiles(array $files, string $destination): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZIP扩展未安装');
        }

        $zip = new ZipArchive();
        $flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;

        if ($zip->open($destination, $flags) !== true) {
            $this->errors[] = "无法创建 ZIP 文件: {$destination}";
            return false;
        }

        try {
            if ($this->password !== null && method_exists($zip, 'setPassword')) {
                $zip->setPassword($this->password);
            }

            foreach ($files as $idx => $file) {
                $realPath = $file['path'];
                $localName = $file['name'];

                if (strpos($localName, '..') !== false) {
                    $this->errors[] = "包内路径不安全: {$localName}";
                    continue;
                }

                $fileSize = filesize($realPath);
                if ($fileSize > $this->config['max_file_size']) {
                    $this->errors[] = "文件过大: {$localName} (" . $this->formatBytes($fileSize) . ")";
                    continue;
                }

                $result = $zip->addFile($realPath, $localName);

                if ($result && $this->password !== null
                    && defined('ZipArchive::EM_AES_256')
                    && method_exists($zip, 'setEncryptionName')
                ) {
                    if (!$zip->setEncryptionName($localName, ZipArchive::EM_AES_256)) {
                        $this->errors[] = "文件加密失败: {$localName}";
                    }
                }

                if (!$result) {
                    $this->errors[] = "添加文件失败: {$localName}";
                }

                $this->progress($idx + 1, count($files), 'compressing');
            }

            $zip->close();
            return empty($this->errors) || count($this->errors) < count($files);
        } catch (Exception $e) {
            $zip->close();
            @unlink($destination);
            throw $e;
        }
    }

    /**
     * TAR/PHAR 系列多文件压缩
     * @param array  $files       标准化后的文件列表
     * @param string $destination 目标路径
     * @param string $format      tar / tar.gz / tar.bz2
     * @return bool
     */
    private function compressPharFiles(array $files, string $destination, string $format): bool
    {
        if (!class_exists('PharData')) {
            throw new Exception('Phar 扩展未安装');
        }

        $tempTar = tempnam($this->config['temp_dir'], 'tar_') . '.tar';

        try {
            $phar = new PharData($tempTar);

            foreach ($files as $idx => $file) {
                $localName = $file['name'];

                if (strpos($localName, '..') !== false) {
                    $this->errors[] = "包内路径不安全: {$localName}";
                    continue;
                }

                $phar->addFile($file['path'], $localName);
                $this->progress($idx + 1, count($files), 'compressing');
            }

            if ($format === 'tar') {
                rename($tempTar, $destination);
                @unlink(tempnam($this->config['temp_dir'], 'tar_'));
                return true;
            }

            $compression = null;
            switch ($format) {
                case 'tar.gz':
                case 'tgz':
                    $compression = Phar::GZ;
                    break;
                case 'tar.bz2':
                case 'tbz2':
                    if (Phar::canCompress(Phar::BZ2)) {
                        $compression = Phar::BZ2;
                    } else {
                        throw new Exception('服务器不支持 BZ2 压缩');
                    }
                    break;
                default:
                    throw new Exception("不支持的 TAR 变体: {$format}");
            }

            $phar->compress($compression);
            @unlink($tempTar);

            $compressedFile = $tempTar . ($format === 'tar.gz' ? '.gz' : '.bz2');
            if (!file_exists($compressedFile)) {
                throw new Exception('压缩文件未生成');
            }

            rename($compressedFile, $destination);
            return true;
        } catch (Exception $e) {
            foreach (glob($tempTar . '*') as $tmp) {
                @unlink($tmp);
            }
            throw $e;
        }
    }

    /**
     * 向已有 ZIP 追加文件
     * @param string $archive ZIP 路径
     * @param array  $rawFiles 原始文件列表（字符串或数组）
     * @return bool
     */
    private function addFilesToZip(string $archive, array $rawFiles): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZIP扩展未安装');
        }

        $normalized = [];
        foreach ($rawFiles as $file) {
            $path = is_array($file) ? ($file['path'] ?? '') : $file;
            $name = is_array($file) ? ($file['name'] ?? basename($path)) : basename($path);

            $realPath = realpath($path);
            if ($realPath === false || !is_file($realPath)) {
                $this->errors[] = "文件不存在: {$path}";
                continue;
            }
            $normalized[] = ['path' => $realPath, 'name' => $name];
        }

        if (empty($normalized)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE) !== true) {
            $this->errors[] = "无法打开 ZIP 文件: {$archive}";
            return false;
        }

        try {
            foreach ($normalized as $file) {
                if (!$zip->addFile($file['path'], $file['name'])) {
                    $this->errors[] = "追加文件失败: {$file['name']}";
                }
            }

            $zip->close();
            return true;
        } catch (Exception $e) {
            $zip->close();
            throw $e;
        }
    }


    /**
     * 从 ZIP 解压单个文件
     * @param string      $archive     ZIP 路径
     * @param string      $innerPath   包内文件路径
     * @param string|null $destination 输出路径（null 则返回内容）
     * @return string|bool
     */
    private function extractZipSingle(string $archive, string $innerPath, ?string $destination)
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZIP扩展未安装');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            $this->errors[] = '无法打开 ZIP 文件';
            return false;
        }

        $index = $zip->locateName($innerPath);
        if ($index === false) {
            $zip->close();
            $this->errors[] = "压缩包中不存在该文件: {$innerPath}";
            return false;
        }

        try {
            if ($this->password !== null && method_exists($zip, 'setPassword')) {
                $zip->setPassword($this->password);
            }

            $stream = $zip->getStream($index);
            if (!$stream) {
                $zip->close();
                $this->errors[] = '无法读取文件流';
                return false;
            }

            $contents = stream_get_contents($stream);
            fclose($stream);
            $zip->close();

            if ($contents === false) {
                $this->errors[] = '读取文件内容失败';
                return false;
            }

            if ($destination !== null) {
                $dir = dirname($destination);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($destination, $contents);
                return true;
            }

            return $contents;
        } catch (Exception $e) {
            $zip->close();
            throw $e;
        }
    }

    /**
     * 从 Phar/TAR 解压单个文件
     * @param string      $archive
     * @param string      $innerPath
     * @param string|null $destination
     * @return string|bool
     */
    private function extractPharSingle(string $archive, string $innerPath, ?string $destination)
    {
        if (!class_exists('PharData')) {
            throw new Exception('Phar 扩展未安装');
        }

        $tempDir = $this->createTempDir();

        try {
            $phar = new PharData($archive);
            $phar->extractTo($tempDir, [$innerPath], true);

            $extractedFile = $tempDir . '/' . $innerPath;
            if (!file_exists($extractedFile)) {
                $this->errors[] = "解压后未找到文件: {$innerPath}";
                return false;
            }

            $contents = file_get_contents($extractedFile);

            if ($destination !== null) {
                $dir = dirname($destination);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($destination, $contents);
                $this->cleanupDir($tempDir);
                return true;
            }

            $this->cleanupDir($tempDir);
            return $contents;
        } catch (Exception $e) {
            $this->cleanupDir($tempDir);
            throw $e;
        }
    }

    /**
     * @param string $source 源文件/目录路径
     * @param string $destination 目标压缩文件路径
     * @param string $format 压缩格式 (tar, gz, bz2)
     * @return bool 是否成功
     */
    private function compressTar($source, $destination, $format)
    {
        if (!$this->isFormatSupported('phar')) {
            $this->errors[] = "Phar 扩展不可用，无法处理 TAR 格式";
            return false;
        }
        
        $tempTar = tempnam($this->config['temp_dir'], 'tar_');
        
        try {
            $phar = new PharData($tempTar . '.tar');
            
            if (is_dir($source)) {
                $phar->buildFromDirectory($source);
            } else {
                $phar->addFile($source, basename($source));
            }
            
            $compression = null;
            switch ($format) {
                case 'tar.gz':
                case 'tgz':
                    $compression = Phar::GZ;
                    break;
                case 'tar.bz2':
                case 'tbz2':
                    if (Phar::canCompress(Phar::BZ2)) {
                        $compression = Phar::BZ2;
                    } else {
                        $this->errors[] = "服务器不支持 BZ2 压缩";
                        return false;
                    }
                    break;
                case 'tar':
                    rename($tempTar . '.tar', $destination);
                    @unlink($tempTar);
                    return true;
                default:
                    $this->errors[] = "不支持的 TAR 压缩格式: {$format}";
                    return false;
            }
            
            $phar->compress($compression);
            
            @unlink($tempTar . '.tar');
            
            $compressedFile = $tempTar . '.tar' . ($format === 'tar.gz' ? '.gz' : '.bz2');
            if (file_exists($compressedFile)) {
                if (!rename($compressedFile, $destination)) {
                    $this->errors[] = "无法移动压缩文件到目标位置";
                    return false;
                }
            } else {
                $this->errors[] = "压缩文件未生成";
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            $this->errors[] = "TAR 压缩失败: " . $e->getMessage();
            return false;
        } finally {
            $files = glob($tempTar . '*');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * ZIP 解压
     * @param string $archive 压缩文件路径
     * @param string $destination 目标解压目录
     * @return bool 是否成功
     */
    private function extractZip($archive, $destination)
    {
        $zip = new ZipArchive();
        $maxExtractSize = $this->config['max_extract_size'];
        $totalExtracted = 0;

        $extracted = [];
        
        if ($zip->open($archive) !== true) {
            $this->errors[] = "无法打开 ZIP 文件";
            return false;
        }
        
        $fileCount = $zip->numFiles;
        if ($fileCount > $this->config['max_file_count']) {
            $this->errors[] = "ZIP 文件包含文件过多（{$fileCount}），可能为攻击文件";
            $zip->close();
            return false;
        }
        
        for ($i = 0; $i < $fileCount; $i++) {
            $stats = $zip->statIndex($i);

            $filename = $stats['name'];
            if (strpos($filename, '..') !== false || strpos($filename, "\0") !== false) {
                $this->errors[] = "ZIP 包含不安全文件名: {$filename}";
                $zip->close();
                $this->cleanupExtractedFiles($destination, $extracted);
                return false;
            }

            if ($stats['size'] > $this->config['max_file_size']) {
                $this->errors[] = "文件过大: {$filename} ({$stats['size']} bytes)";
                $zip->close();
                $this->cleanupExtractedFiles($destination, $extracted);
                return false;
            }

            if (!$zip->extractTo($destination, $filename)) {
                $this->errors[] = "解压文件失败: {$filename}";
                $zip->close();
                $this->cleanupExtractedFiles($destination, $extracted);
                return false;
            }

            $extracted[] = $filename;

            $totalExtracted += $stats['size'];
            if ($totalExtracted > $maxExtractSize) {
                $this->errors[] = "解压文件总大小超过限制";
                $zip->close();

                $this->cleanupExtractedFiles($destination, $extracted);
                return false;
            }

            $this->progress($i + 1, $fileCount, 'extracting');
        }
        
        $zip->close();
        return true;
    }
    
    /**
     * 回滚本次解压产生的文件与空目录（仅限显式传入的条目名）
     *
     * 安全约束：**绝不按目录递归删除**。$destination 可能是插件根 / 上传根，
     * 全量递归删除会连带删掉与本次归档无关的既有文件。这里只处理：
     *   1. 清单中列出的文件（相对路径，越界/含 `..` 的条目直接跳过）；
     *   2. 这些文件所在的目录链（含 extractTo 自动创建的中间目录），且仅当目录为空时才 rmdir。
     *
     * @param string   $directory 解压目录
     * @param string[] $entries   本次成功落盘的归档条目名（相对路径，目录条目以 `/` 结尾）
     */
    private function cleanupExtractedFiles(string $directory, array $entries): void
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($entries === [] || !is_dir($root)) {
            return;
        }

        $dirs = [];

        foreach ($entries as $entry) {
            $entry = str_replace('\\', '/', (string) $entry);
            if ($entry === '' || str_contains($entry, '..') || str_starts_with($entry, '/')) {
                continue;
            }

            $relative = rtrim($entry, '/');
            if ($relative === '') {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (str_ends_with($entry, '/')) {
                if (is_dir($path)) {
                    $dirs[$path] = true;
                }
            } elseif (is_file($path)) {
                @unlink($path);
            }

            $parent = dirname($path);
            while (strlen($parent) > strlen($root) && str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) {
                $dirs[$parent] = true;
                $parent = dirname($parent);
            }
        }

        $dirs = array_keys($dirs);
        usort($dirs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }
    /**
     * Phar 格式解压（TAR, TAR.GZ, TAR.BZ2）
     * @param string $archive 压缩文件路径
     * @param string $destination 目标解压目录
     * @return bool 是否成功
     */
    private function extractPhar($archive, $destination)
    {
        try {
            $phar = new PharData($archive);
            $phar->extractTo($destination, null, true);
            return true;
        } catch (Exception $e) {
            $this->errors[] = "Phar 解压失败: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * GZ 解压
     * @param string $archive 压缩文件路径
     * @param string $destination 目标解压目录
     * @return bool 是否成功
     */
    private function extractGz($archive, $destination)
    {
        if (!extension_loaded('zlib')) {
            throw new Exception('Zlib 扩展未安装');
        }

        $gz = $out = null;
        $destFile = $destination . '/' . basename($archive, '.gz');
        
        try {
            $gz = gzopen($archive, 'rb');
            if (!$gz) {
                $this->errors[] = "无法打开 GZ 文件";
                return false;
            }
            
            $out = fopen($destFile, 'wb');
            if (!$out) {
                $this->errors[] = "无法创建输出文件";
                return false;
            }
            
            while (!gzeof($gz)) {
                $buffer = gzread($gz, 8192);
                if ($buffer === false) {
                    $this->errors[] = "读取 GZ 文件失败";
                    return false;
                }
                fwrite($out, $buffer);
            }
            
            return true;
        } catch (Exception $e) {
            $this->errors[] = "GZ 解压失败: " . $e->getMessage();
            return false;
        } finally {
            if ($gz) {
                gzclose($gz);
            }
            if ($out) {
                fclose($out);
            }
        }
    }
    
    /**
     * BZ2 解压
     * @param string $archive 压缩文件路径
     * @param string $destination 目标解压目录
     * @return bool 是否成功
     */
    private function extractBz2($archive, $destination)
    {
        if (!extension_loaded('bz2')) {
            throw new Exception('BZ2 扩展未安装');
        }
        
        $destFile = $destination . '/' . basename($archive, '.bz2');
        
        $content = bzdecompress(file_get_contents($archive));
        if ($content === false || is_int($content)) {
            $this->errors[] = "BZ2 解压失败";
            return false;
        }
        
        file_put_contents($destFile, $content);
        return true;
    }
    
    /**
     * RAR 解压
     * @param string $archive 压缩文件路径
     * @param string $destination 目标解压目录
     * @return bool 是否成功
     */
    private function extractRar($archive, $destination)
    {
        if (!class_exists('RarArchive')) {
            throw new Exception('RAR 扩展未安装');
        }

        $rar = \RarArchive::open($archive);
        if ($rar === false) {
            $this->errors[] = "无法打开 RAR 文件";
            return false;
        }
        
        $entries = $rar->getEntries();
        if ($entries === false) {
            $this->errors[] = "无法读取 RAR 文件内容";
            $rar->close();
            return false;
        }
        
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', (string) $entry->getName());
            if (str_contains($name, '..')
                || str_starts_with($name, '/')
                || preg_match('#^[A-Za-z]:#', $name) === 1
            ) {
                $this->errors[] = "RAR 包含不安全路径: {$name}";
                $rar->close();
                return false;
            }

            $entry->extract($destination);
        }
        
        $rar->close();
        return true;
    }
    
    /**
     * 检测压缩文件格式
     * @param string $file 压缩文件路径
     * @return string 压缩格式 (tar, gz, bz2, rar, zip)
     */
    private function detectArchiveFormat($file)
    {        
        $filename = strtolower(basename($file));
        
        $matchedExtensions = [];
        foreach ($this->extensions as $type => $exts) {
            foreach ($exts as $ext) {
                if (substr_compare($filename, '.' . $ext, -strlen('.' . $ext)) === 0) {
                    $matchedExtensions[$ext] = strlen($ext);
                }
            }
        }
        
        if (empty($matchedExtensions)) {
            return false;
        }
        
        arsort($matchedExtensions);
        return array_key_first($matchedExtensions);
    }

    /**
     * 获取格式类型
     * @param string $format 压缩格式
     * @return string 格式类型 (tar, gz, bz2, rar, zip)
     */
    private function getFormatType($format)
    {
        foreach ($this->extensions as $type => $exts) {
            if (in_array($format, $exts)) {
                return $type;
            }
        }
        
        return $format;
    }
        
    /**
     * 进度回调
     * @param int $current 当前处理项数
     * @param int $total 总项数
     * @param string $info 进度信息
     */
    private function progress(int $current, int $total, string $info): void
    {
        if ($this->progressCallback) {
            $percent = (int)(($current / max(1, $total)) * 100);
            call_user_func($this->progressCallback, $percent, $info);
        }
    }

    /**
     * 验证路径安全性
     * @param string $path 源路径
     * @return bool 是否安全
     */
    private function validatePath($path)
    {
        if (strlen(basename($path)) > $this->config['max_filename_length']) {
            $this->errors[] = "文件名过长: " . basename($path);
            return false;
        }

        if ($this->config['block_traversal']) {
            if (strpos($path, '..') !== false || 
                strpos($path, '../') !== false ||
                strpos($path, '..\\') !== false) {
                $this->errors[] = "路径包含目录遍历: {$path}";
                return false;
            }
        }

        if ($this->config['block_absolute_paths']) {
            if (strpos($path, '/') === 0 || 
                preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
                $this->errors[] = "路径包含绝对路径: {$path}";
                return false;
            }
        }

        $dangerousChars = ["\0", "\t", "\r", "\n"];
        foreach ($dangerousChars as $char) {
            if (strpos($path, $char) !== false) {
                $this->errors[] = "路径包含危险字符: {$path}";
                return false;
            }
        }
        
        if (DIRECTORY_SEPARATOR === '/') {
            $dangerousDirs = ['/', '/etc', '/bin', '/sbin', '/usr', '/var', '/tmp'];
            foreach ($dangerousDirs as $dir) {
                if (strpos($path, $dir) === 0 && $path !== $dir) {
                    $this->errors[] = "禁止写入系统目录: {$dir}";
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 检查路径是否允许(仅检查Linux、Unix系统)
     * @param string $path 路径
     * @return bool 是否允许
     */
    private function isPathAllowed($path)
    {
        foreach ($this->config['allowed_paths'] as $allowedRoot) {
            if (strpos($path, $allowedRoot) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 列出 ZIP 文件内容
     * @param string $archive ZIP 文件路径
     * @return array ZIP 文件内容列表
     */
    private function listZipContents($archive)
    {
        if (!class_exists('ZipArchive')) {
            $this->errors[] = 'ZIP扩展未安装';
            return false;
        }

        try{
            $zip = new ZipArchive();
            $contents = [];
            
            if ($zip->open($archive) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stats = $zip->statIndex($i);
                    $contents[] = [
                        'name' => $stats['name'],
                        'size' => $stats['size'],
                        'compressed_size' => $stats['comp_size'],
                        'modified' => date('Y-m-d H:i:s', $stats['mtime'])
                    ];
                }
                $zip->close();
            }
            
            return $contents;
        } catch (Exception $e) {
            $this->errors[] = "读取ZIP文件列表失败: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * 列出 Phar 文件内容
     * @param string $archive Phar 文件路径
     * @return array Phar 文件内容列表
     */
    private function listPharContents($archive)
    {
        if (!class_exists('PharData')) {
            $this->errors[] = 'Phar扩展未安装';
            return false;
        }
        
        try {
            $phar = new PharData($archive);
            $contents = [];
            
            foreach ($phar as $file) {
                $contents[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                ];
            }
            
            return $contents;
        } catch (Exception $e) {
            $this->errors[] = "读取Phar文件列表失败: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * 列出 RAR 文件内容
     * @param string $archive RAR 文件路径
     * @return array RAR 文件内容列表
     */
    private function listRarContents($archive)
    {
        if (!class_exists('RarArchive')) {
            $this->errors[] = 'RAR扩展未安装';
            return false;
        }
        try {
            $rar = RarArchive::open($archive);
            $contents = [];
            
            if ($rar !== false) {
                $entries = $rar->getEntries();
                foreach ($entries as $entry) {
                    $contents[] = [
                        'name' => $entry->getName(),
                        'size' => $entry->getUnpackedSize(),
                        'compressed_size' => $entry->getPackedSize(),
                        'modified' => date('Y-m-d H:i:s', $entry->getFileTime())
                    ];
                }
                $rar->close();
            }
            
            return $contents;
        } catch (Exception $e) {
            $this->errors[] = "读取RAR文件列表失败: " . $e->getMessage();
            return false;
        }
    }

    /**
     * 标准化路径
     * @param string $path 原始路径
     * @return string 标准化路径
     */
    private function normalizePath($path)
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        
        if (!preg_match('/^[a-zA-Z]:[\\\\\/]|^[\\\\\/]{2}/', $path)) {
            $path = realpath($path) ?: $path;
        }
        
        return $path;
    }

    /**
     * 格式化字节大小
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * 获取平台临时目录
     * @return string 平台临时目录
     */
    private function getPlatformTempDir(): string
    {
        return $this->normalizePath(sys_get_temp_dir());
    }
    
    /**
     * 设置文件权限
     * @param string $file 文件路径
     * @param bool $isDir 是否为目录
     * @return bool 是否设置成功
     */
    private function setFilePermissions($file, $isDir = false)
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        } else {
            $permissions = $isDir ? 0755 : 0644;
            return chmod($file, $permissions);
        }
    }

    /**
     * 创建临时目录
     * @return string 临时目录路径
     */
    private function createTempDir(): string
    {
        $dir = $this->config['temp_dir'] . '/extract_' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * 移动文件到目标目录
     * @param string $src 源目录路径
     * @param string $dest 目标目录路径
     */
    private function moveToDestination(string $src, string $dest): void
    {
        if (!is_dir($dest)) mkdir($dest, 0755, true);
        foreach (scandir($src) as $file) {
            if ($file === '.' || $file === '..') continue;
            rename($src . '/' . $file, $dest . '/' . $file);
        }
    }

    /**
     * 递归删除目录
     * @param string $dir 目录路径
     */
    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            is_dir($dir.'/'.$file)
                ? $this->cleanupDir($dir.'/'.$file)
                : unlink($dir.'/'.$file);
        }
        rmdir($dir);
    }
}