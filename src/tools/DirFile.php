<?php
namespace wise\support\tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 文件夹和文件处理类
 */
class DirFile
{
    /**
     * 创建目录
     * @param string $path
     * @param int $mode
     * @return bool
     */
    public static function createDir($path, $mode = 0777)
    {
        if (is_dir($path)) {
            return true;
        } else {
            //如果目录不存在，则递归创建
            if (mkdir($path, $mode, true)) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * 循环遍历目录下的文件和文件夹并输出
     * @param string $pathName
     * @param array $filterDir 需要过滤的文件夹，空数组表示不递归文件夹，['*']表示递归所有文件,['common']表示仅递归common文件夹内容
     * @param array $filterFile 需要过滤的文件名，空数组表示所有文件不显示，['*']表示所有文件都显示,['xls','xlsx']表示仅显示xls文件
     * @param array $selected 设置是否已经选中
     * @param array $output
     * @return array|null
     */
    public static function recurDir($pathName, $filterDir = ['*'], $filterFile = ['*'], $selected = [], $output = ['id'=>'id', 'fullName' => 'fullName', 'baseName' => 'baseName'])
    {
        $filterDir = is_array($filterDir) ? $filterDir : explode(',', $filterDir);
        $filterFile = is_array($filterFile) ? $filterFile : explode(',', $filterFile);
        
        //将结果保存在result变量中
        $result = [];
        $temp   = [];
        //判断传入的变量是否是目录
        if (!is_dir($pathName) || !is_readable($pathName)) {
            return $result;
        }
        //取出目录中的文件和子目录名,使用scandir函数
        $allFiles = scandir($pathName);
        $id = 0;
        //遍历他们
        foreach ($allFiles as $fileName) {
            if (in_array($fileName, ['.', '..'])) {
                continue;
            }
            //路径加文件名
            if (mb_substr($pathName, -1, 1) == DIRECTORY_SEPARATOR) {
                $fullName = $pathName . $fileName;
            } else {
                $fullName = $pathName . DIRECTORY_SEPARATOR . $fileName;
            }
            $baseName = basename($fileName);
            //如果是目录的话就继续遍历这个目录
            if (is_dir($fullName)) {
                if (!in_array('*', $filterDir) && !in_array(strtolower(basename(pathinfo($fullName, PATHINFO_DIRNAME))), $filterDir)) {
                    continue;
                }

                //将这个目录中的文件信息存入到数组中
                $res = [
                    $output['baseName'] => $baseName,
                    $output['fullName'] => $fullName,
                    'type' => 'dir',
                    'children' => self::recurDir($fullName, $filterDir, $filterFile, $selected, $output),
                ];
                if (in_array($fullName, $selected)) {
                    $res['state'] = ['selected' => true];
                } else {
                    $res['state'] = ['selected' => false];
                }
                $res['id'] = ++$id;
                $result[] = $res;
            } else {
                if($filterFile != []){
                    if (!in_array('*', $filterFile) && !in_array(strtolower(pathinfo($fullName, PATHINFO_EXTENSION)), $filterFile)) {
                        continue;
                    }
                    //如果是文件就先存入临时变量
                    //将这个目录中的文件信息存入到数组中
                    $tem = [$output['id'] => ++$id, $output['baseName'] => $baseName, $output['fullName'] => $fullName, 'type' => 'file', 'state' => ['selected' => false]];
                    if (in_array($fullName, $selected)) {
                        $tem['state'] = ['selected' => true];
                    } else {
                        $tem['state'] = ['selected' => false];
                    }
                    $temp[] = $tem;
                }
            }
        }
        //取出文件
        if ($temp) {
            foreach ($temp as $f) {
                $result[] = $f;
            }
        }
        return $result;
    }

    /**
     * 删除文件夹
     * @param string $dirname 目录
     * @param bool $withself 是否删除自身
     * @return boolean
     */
    public static function rmDirs($dirname, $withself = true)
    {
        if (!is_dir($dirname)) {
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $path = $fileinfo->getRealPath();
            if ($fileinfo->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        if ($withself) {
            @rmdir($dirname);
        }
        return true;
    }

    /**
     * 复制文件夹
     * @param string $source 源文件夹
     * @param string $dest 目标文件夹
     */
    public static function copyDirs($source, $dest)
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            // 以源目录为基准计算相对路径（RecursiveIteratorIterator 上不存在 getSubPathname()）
            $relativePath = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($source)), '/');
            $targetPath = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }
}