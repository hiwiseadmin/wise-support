<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\tools\ArchiveTool;
use ZipArchive;

/**
 * ArchiveTool 解压失败回滚单元测试
 *
 * 回归目标（数据丢失缺陷）：解压失败时**只能**删除本次归档产生的文件与该归档创建的空目录，
 * 不得递归删除 $destination —— 它可能是插件根（plugin_path()）或上传根。
 */
class ArchiveToolRollbackTest extends TestCase
{
    private string $work;
    private string $dest;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive 扩展不可用');
        }

        $this->work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_archive_' . bin2hex(random_bytes(6));
        $this->dest = $this->work . DIRECTORY_SEPARATOR . 'dest';

        mkdir($this->dest . DIRECTORY_SEPARATOR . 'existing', 0777, true);

        file_put_contents($this->dest . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');
        file_put_contents($this->dest . DIRECTORY_SEPARATOR . 'existing' . DIRECTORY_SEPARATOR . 'old.txt', 'old');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->work);
    }


    public function test_rollback_on_total_size_exceeded_only_removes_this_archive(): void
    {
        $zip = $this->makeZip([
            'ok/a.txt' => 'aaa',
            'ok/b.txt' => str_repeat('x', 2048),
        ]);

        $tool = new ArchiveTool(['max_extract_size' => 1024, 'max_file_size' => 1024 * 1024]);

        $this->assertFalse($tool->extract($zip, $this->dest), '总大小超限应返回 false');
        $this->assertStringContainsString('总大小', (string) $tool->getLastError());

        $this->assertFileExists($this->dest . '/keep.txt');
        $this->assertFileExists($this->dest . '/existing/old.txt');

        $this->assertFileDoesNotExist($this->dest . '/ok/a.txt');
        $this->assertFileDoesNotExist($this->dest . '/ok/b.txt');
        $this->assertDirectoryDoesNotExist($this->dest . '/ok');
    }


    public function test_rollback_on_single_file_too_large(): void
    {
        $zip = $this->makeZip([
            'pkg/small.txt' => 'hello',
            'pkg/big.txt'   => str_repeat('y', 4096),
        ]);

        $tool = new ArchiveTool(['max_file_size' => 1024, 'max_extract_size' => 1024 * 1024]);

        $this->assertFalse($tool->extract($zip, $this->dest));

        $this->assertFileExists($this->dest . '/keep.txt');
        $this->assertFileDoesNotExist($this->dest . '/pkg/small.txt');
        $this->assertDirectoryDoesNotExist($this->dest . '/pkg');
    }


    public function test_extract_succeeds_below_limits(): void
    {
        $zip = $this->makeZip([
            'ok/a.txt' => 'aaa',
            'ok/b.txt' => str_repeat('x', 2048),
        ]);

        $tool = new ArchiveTool();

        $this->assertTrue($tool->extract($zip, $this->dest));
        $this->assertFileExists($this->dest . '/ok/a.txt');
        $this->assertFileExists($this->dest . '/ok/b.txt');
        $this->assertSame('aaa', file_get_contents($this->dest . '/ok/a.txt'));
        $this->assertFileExists($this->dest . '/keep.txt');
    }


    /**
     * @param array<string, string> $entries 条目名 => 内容
     */
    private function makeZip(array $entries): string
    {
        $archive = $this->work . DIRECTORY_SEPARATOR . 'archive_' . bin2hex(random_bytes(3)) . '.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $archive;
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
