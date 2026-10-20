<?php
namespace wise\support\tools;
/* 
 * 版本号比较器
 * 
 * 该类用于比较两个版本号的大小关系，支持语义化版本号（Semantic Versioning）格式。
 * 支持的版本号格式：
 * - 主版本号.次版本号.修订版本号
 * - 主版本号.次版本号.修订版本号-预发布版本
 * - 主版本号.次版本号.修订版本号+构建版本
 * 
 * 示例：
 * Version::compare('v1.2.3', 'version1.2.4') === -1
 * Version::compare('1.2.3-rc1', '1.2.3') === 1
 * Version::compare('1.2.3+build123', '1.2.3+build456') === 0
 * Version::gt('1.2.5', '1.2.3') === true
 * Version::gte('1.2.5', '1.2.3') === true
 * Version::lt('1.2.3', '1.2.5') === true
 * Version::lte('1.2.3', '1.2.5') === true
 * Version::eq('1.2.3', '1.2.5') === false
 */

class Version
{
    /**
     * 比较两个版本号
     * 
     * @param string $version1 第一个版本号
     * @param string $version2 第二个版本号
     * @return int 返回 -1, 0, 1 分别表示 version1 <, =, > version2
     */
    public static function compare(string $version1, string $version2): int
    {
        // 标准化版本号
        $normalized1 = self::normalizeVersion($version1);
        $normalized2 = self::normalizeVersion($version2);
        
        // 分割版本组件
        $parts1 = self::splitVersion($normalized1);
        $parts2 = self::splitVersion($normalized2);
        
        // 比较主版本号
        if ($parts1['major'] !== $parts2['major']) {
            return $parts1['major'] <=> $parts2['major'];
        }
        
        // 比较次版本号
        if ($parts1['minor'] !== $parts2['minor']) {
            return $parts1['minor'] <=> $parts2['minor'];
        }
        
        // 比较修订版本号
        if ($parts1['patch'] !== $parts2['patch']) {
            return $parts1['patch'] <=> $parts2['patch'];
        }
        
        // 比较预发布版本
        return self::comparePreRelease($parts1['pre_release'], $parts2['pre_release']);
    }
    
    /**
     * 检查 version1 是否大于 version2
     */
    public static function gt(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) === 1;
    }
    
    /**
     * 检查 version1 是否大于等于 version2
     */
    public static function gte(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) >= 0;
    }
    
    /**
     * 检查 version1 是否小于 version2
     */
    public static function lt(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) === -1;
    }
    
    /**
     * 检查 version1 是否小于等于 version2
     */
    public static function lte(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) <= 0;
    }
    
    /**
     * 检查两个版本是否相等
     */
    public static function eq(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) === 0;
    }
    
    /**
     * 标准化版本号
     */
    private static function normalizeVersion(string $version): string
    {
        // 转换为小写并移除前缀
        $version = strtolower(trim($version));
        // 先尝试去掉 "version" 前缀（可能后接空白），再去掉单个 v/V 前缀
        $version = preg_replace('/^version\s*|^[vV]/', '', $version);
        
        return $version;
    }
    
    /**
     * 分割版本号为组件
     */
    private static function splitVersion(string $version): array
    {
        // 分离预发布版本
        $preRelease = '';
        if (strpos($version, '-') !== false) {
            list($version, $preRelease) = explode('-', $version, 2);
        }
        
        // 分割主版本号
        $parts = explode('.', $version);
        
        return [
            'major' => isset($parts[0]) ? intval($parts[0]) : 0,
            'minor' => isset($parts[1]) ? intval($parts[1]) : 0,
            'patch' => isset($parts[2]) ? intval($parts[2]) : 0,
            'pre_release' => $preRelease
        ];
    }
    
    /**
     * 比较预发布版本
     */
    private static function comparePreRelease(string $pre1, string $pre2): int
    {
        // 如果都没有预发布版本，则相等
        if (empty($pre1) && empty($pre2)) {
            return 0;
        }
        
        // 有预发布版本的版本号比没有的小
        if (empty($pre1)) {
            return 1;
        }
        if (empty($pre2)) {
            return -1;
        }
        
        // 比较预发布标识符
        $identifiers1 = explode('.', $pre1);
        $identifiers2 = explode('.', $pre2);
        
        $length = max(count($identifiers1), count($identifiers2));
        
        for ($i = 0; $i < $length; $i++) {
            $id1 = $identifiers1[$i] ?? '';
            $id2 = $identifiers2[$i] ?? '';
            
            $result = self::compareIdentifier($id1, $id2);
            if ($result !== 0) {
                return $result;
            }
        }
        
        return 0;
    }
    
    /**
     * 比较预发布标识符
     */
    private static function compareIdentifier(string $id1, string $id2): int
    {
        $isNumeric1 = is_numeric($id1);
        $isNumeric2 = is_numeric($id2);
        
        // 数字标识符比非数字标识符小
        if ($isNumeric1 && !$isNumeric2) {
            return -1;
        }
        if (!$isNumeric1 && $isNumeric2) {
            return 1;
        }
        
        // 都是数字，比较数值
        if ($isNumeric1 && $isNumeric2) {
            return intval($id1) <=> intval($id2);
        }
        
        // 都是非数字，按字符串比较
        return strcmp($id1, $id2);
    }
}