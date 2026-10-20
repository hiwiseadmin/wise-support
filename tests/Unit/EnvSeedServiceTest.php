<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\contracts\EnvSeedProvider;
use wise\support\env\EnvSeedService;
use wise\support\env\EnvValueGenerator;

/**
 * .env 密钥播种引擎 EnvSeedService 单元测试
 */
class EnvSeedServiceTest extends TestCase
{
    private string $tmpDir;
    private string $envPath;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_seed_' . bin2hex(random_bytes(4));
        $this->envPath = $this->tmpDir . DIRECTORY_SEPARATOR . '.env';
        @mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function writeEnv(string $content): void
    {
        file_put_contents($this->envPath, $content);
    }

    private function readEnv(): string
    {
        return (string) file_get_contents($this->envPath);
    }

    // ============================================================
    // discoverProviderClasses()
    // ============================================================

    public function test_discover_warns_when_installed_json_missing(): void
    {
        $warnings = [];
        $classes  = (new EnvSeedService())->discoverProviderClasses($this->tmpDir, $warnings);

        $this->assertSame([], $classes);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('已安装包清单不存在', $warnings[0]);
    }

    public function test_discover_warns_on_invalid_json(): void
    {
        $this->writeInstalledJson('{not-json');

        $warnings = [];
        $classes  = (new EnvSeedService())->discoverProviderClasses($this->tmpDir, $warnings);

        $this->assertSame([], $classes);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('解析 Composer 已安装包清单失败', $warnings[0]);
    }

    public function test_discover_warns_when_packages_key_missing(): void
    {
        $this->writeInstalledJson(json_encode(['other' => []]));

        $warnings = [];
        $classes  = (new EnvSeedService())->discoverProviderClasses($this->tmpDir, $warnings);

        $this->assertSame([], $classes);
        $this->assertStringContainsString('解析 Composer 已安装包清单失败', $warnings[0]);
    }

    public function test_discover_extracts_unique_service_classes(): void
    {
        $this->writeInstalledJson(json_encode([
            'packages' => [
                ['extra' => ['think' => ['services' => ['PkgA', 'PkgB', 'PkgA']]]],
                ['extra' => ['think' => ['services' => 'PkgC']]],
                ['extra' => ['think' => ['other' => 'x']]],
                ['extra' => []],
                'not-an-array',
                ['name' => 'no-extra'],
            ],
        ]));

        $warnings = [];
        $classes  = (new EnvSeedService())->discoverProviderClasses($this->tmpDir, $warnings);

        $this->assertSame(['PkgA', 'PkgB', 'PkgC'], $classes);
        $this->assertSame([], $warnings);
    }

    private function writeInstalledJson(string $raw): void
    {
        @mkdir($this->tmpDir . DIRECTORY_SEPARATOR . 'composer', 0700, true);
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json', $raw);
    }

    // ============================================================
    // collectSpecs()
    // ============================================================

    public function test_collect_specs_filters_non_providers(): void
    {
        $specs = (new EnvSeedService())->collectSpecs([
            SeedNotAProvider::class,
            SeedProviderFixture::class,
        ]);

        $this->assertArrayHasKey('SEED_STATIC', $specs);
        $this->assertArrayHasKey('SEED_EXTERNAL', $specs);
        $this->assertArrayHasKey('SEED_SECRET', $specs);
        $this->assertCount(3, $specs);
    }

    public function test_collect_specs_skips_unknown_classes(): void
    {
        $specs = (new EnvSeedService())->collectSpecs(['This\\Class\\Does\\Not\\Exist']);

        $this->assertSame([], $specs);
    }

    public function test_collect_specs_later_provider_overrides_same_key(): void
    {
        $specs = (new EnvSeedService())->collectSpecs([
            SeedProviderFixture::class,
            SeedProviderOverrideFixture::class,
        ]);

        $this->assertSame('override', $specs['SEED_STATIC']['default']);
    }

    // ============================================================
    // mergeIntoEnv() — 基本类型
    // ============================================================

    public function test_merge_warns_when_env_missing(): void
    {
        $report = (new EnvSeedService())->mergeIntoEnv(['X' => ['type' => 'static', 'default' => '1']], $this->envPath);

        $this->assertSame([], $report['written']);
        $this->assertStringContainsString('.env 不存在', $report['warnings'][0]);
        $this->assertFileDoesNotExist($this->envPath);
    }

    public function test_merge_writes_static_value_with_comment(): void
    {
        $this->writeEnv("APP_DEBUG = true\n");

        $report = (new EnvSeedService())->mergeIntoEnv([
            'SEED_STATIC' => ['type' => 'static', 'default' => 'abc', 'comment' => '静态值'],
        ], $this->envPath);

        $this->assertSame(['SEED_STATIC'], $report['written']);
        $this->assertSame([], $report['skipped']);
        $this->assertSame([], $report['warnings']);

        $content = $this->readEnv();
        $this->assertStringContainsString("APP_DEBUG = true", $content);
        $this->assertStringContainsString("; 静态值\nSEED_STATIC = abc", $content);
    }

    public function test_merge_writes_static_without_comment(): void
    {
        $this->writeEnv('');

        (new EnvSeedService())->mergeIntoEnv([
            'NO_COMMENT' => ['type' => 'static', 'default' => 'v'],
        ], $this->envPath);

        $this->assertStringContainsString("NO_COMMENT = v", $this->readEnv());
        $this->assertStringNotContainsString('; ', $this->readEnv());
    }

    public function test_merge_external_writes_empty_placeholder(): void
    {
        $this->writeEnv('');

        $report = (new EnvSeedService())->mergeIntoEnv([
            'OPENAI_API_KEY' => ['type' => 'external', 'comment' => 'OpenAI 密钥'],
        ], $this->envPath);

        $content = $this->readEnv();

        $this->assertSame(['OPENAI_API_KEY'], $report['written']);
        $this->assertStringContainsString('; OpenAI 密钥', $content);
        $this->assertStringContainsString('; 请在下方填入真实值（当前为空）', $content);
        $this->assertStringContainsString('OPENAI_API_KEY =', $content);
        $this->assertStringNotContainsString('OPENAI_API_KEY = fake', $content);
    }

    public function test_merge_static_uses_default_type_when_omitted(): void
    {
        $this->writeEnv('');

        (new EnvSeedService())->mergeIntoEnv(['IMPLICIT' => ['default' => 'd']], $this->envPath);

        $this->assertStringContainsString('IMPLICIT = d', $this->readEnv());
    }

    public function test_merge_quotes_values_containing_whitespace(): void
    {
        $this->writeEnv('');

        (new EnvSeedService())->mergeIntoEnv([
            'WITH_SPACE' => ['type' => 'static', 'default' => 'hello world'],
        ], $this->envPath);

        $this->assertStringContainsString('WITH_SPACE = "hello world"', $this->readEnv());
    }

    // ============================================================
    // mergeIntoEnv() — secret 类型
    // ============================================================

    public function test_merge_secret_uses_generator_result(): void
    {
        $this->writeEnv('');
        $service = new EnvSeedService(new SeedGeneratorStub('generated-secret'));

        $report = $service->mergeIntoEnv([
            'JWT_SECRET' => ['type' => 'secret', 'generator' => 'random_bytes_32', 'comment' => 'JWT 密钥'],
        ], $this->envPath);

        $this->assertSame(['JWT_SECRET'], $report['written']);
        $this->assertStringContainsString('JWT_SECRET = generated-secret', $this->readEnv());
    }

    public function test_merge_secret_array_result_is_mapped_by_keys(): void
    {
        $this->writeEnv('');
        $service = new EnvSeedService(new SeedGeneratorStub([
            'private' => 'file:///tmp/a.pem',
            'public'  => 'file:///tmp/b.pem',
        ]));

        $report = $service->mergeIntoEnv([
            'API_ENC_KEY' => [
                'type'      => 'secret',
                'generator' => 'rsa_2048_pem',
                'keys'      => ['private' => 'API_ENC_PRIVATE_KEY', 'public' => 'API_ENC_PUBLIC_KEY'],
            ],
        ], $this->envPath);

        $content = $this->readEnv();

        $this->assertSame(['API_ENC_PRIVATE_KEY', 'API_ENC_PUBLIC_KEY'], $report['written']);
        $this->assertStringContainsString('API_ENC_PRIVATE_KEY = file:///tmp/a.pem', $content);
        $this->assertStringContainsString('API_ENC_PUBLIC_KEY = file:///tmp/b.pem', $content);
        $this->assertStringNotContainsString('API_ENC_KEY =', $content);
    }

    public function test_merge_secret_array_result_without_keys_mapping_uses_generator_keys(): void
    {
        $this->writeEnv('');
        $service = new EnvSeedService(new SeedGeneratorStub(['private' => 'p', 'public' => 'q']));

        $report = $service->mergeIntoEnv([
            'PAIR' => ['type' => 'secret', 'generator' => 'rsa_2048_pem'],
        ], $this->envPath);

        $this->assertSame(['private', 'public'], $report['written']);
    }

    public function test_merge_secret_missing_generator_is_reported(): void
    {
        $this->writeEnv('');

        $report = (new EnvSeedService())->mergeIntoEnv([
            'NO_GEN' => ['type' => 'secret', 'comment' => '缺 generator'],
        ], $this->envPath);

        $this->assertSame([], $report['written']);
        $this->assertCount(1, $report['warnings']);
        $this->assertStringContainsString('secret 密钥生成失败 1 项', $report['warnings'][0]);
        $this->assertStringContainsString('NO_GEN: 契约项缺少 generator', $report['warnings'][0]);
        $this->assertSame('', $this->readEnv());
    }

    public function test_merge_secret_generator_exception_is_aggregated(): void
    {
        $this->writeEnv('');
        $service = new EnvSeedService(new SeedGeneratorStub(null, new \RuntimeException('openssl 不可用')));

        $report = $service->mergeIntoEnv([
            'K1' => ['type' => 'secret', 'generator' => 'random_bytes_32'],
            'K2' => ['type' => 'secret', 'generator' => 'uuid'],
        ], $this->envPath);

        $this->assertSame([], $report['written']);
        $this->assertStringContainsString('secret 密钥生成失败 2 项', $report['warnings'][0]);
        $this->assertStringContainsString('openssl 不可用', $report['warnings'][0]);
    }

    public function test_merge_passes_resolved_file_dir_to_generator(): void
    {
        $this->writeEnv('');
        $stub    = new SeedGeneratorStub('x');
        $service = new EnvSeedService($stub);

        $service->mergeIntoEnv([
            'KEY' => ['type' => 'secret', 'generator' => 'rsa_2048_pem', 'file_dir' => 'storage/keys'],
        ], $this->envPath);

        $this->assertSame(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'storage/keys',
            $stub->lastOptions['file_dir'] ?? null
        );
    }

    public function test_merge_keeps_absolute_file_dir_untouched(): void
    {
        $this->writeEnv('');
        $stub    = new SeedGeneratorStub('x');
        $service = new EnvSeedService($stub);
        $absDir  = sys_get_temp_dir() . '/abs_keys';

        $service->mergeIntoEnv([
            'KEY' => ['type' => 'secret', 'generator' => 'rsa_2048_pem', 'file_dir' => $absDir],
        ], $this->envPath);

        $this->assertSame(str_replace('\\', '/', $absDir), str_replace('\\', '/', $stub->lastOptions['file_dir'] ?? ''));
    }

    // ============================================================
    // mergeIntoEnv() — 幂等
    // ============================================================

    public function test_merge_is_idempotent_for_existing_keys(): void
    {
        $this->writeEnv(implode("\n", [
            'PLAIN=1',
            'export EXPORTED=2',
            'SPACED = 3',
            'QUOTED="v"',
            'SINGLE=\'v\'',
            '# COMMENTED=4',
            '; SEMI_COMMENTED=4',
            'APP_DEBUG = true',
        ]));

        $report = (new EnvSeedService(new SeedGeneratorStub('gen')))->mergeIntoEnv([
            'PLAIN'             => ['type' => 'static', 'default' => 'new'],
            'EXPORTED'          => ['type' => 'static', 'default' => 'new'],
            'SPACED'            => ['type' => 'static', 'default' => 'new'],
            'QUOTED'            => ['type' => 'static', 'default' => 'new'],
            'SINGLE'            => ['type' => 'static', 'default' => 'new'],
            'COMMENTED'         => ['type' => 'static', 'default' => 'written'],
            'SEMI_COMMENTED'    => ['type' => 'static', 'default' => 'written'],
            'NEW_KEY'           => ['type' => 'secret', 'generator' => 'uuid'],
        ], $this->envPath);

        // 注释行里的键不算「已存在」，因此 COMMENTED / SEMI_COMMENTED 会被写入
        $this->assertSame(['COMMENTED', 'SEMI_COMMENTED', 'NEW_KEY'], $report['written']);
        $this->assertSame(
            ['PLAIN', 'EXPORTED', 'SPACED', 'QUOTED', 'SINGLE'],
            $report['skipped']
        );

        $content = $this->readEnv();
        $this->assertStringContainsString('PLAIN=1', $content);
        $this->assertStringContainsString("COMMENTED = written", $content);
        $this->assertStringContainsString('NEW_KEY = gen', $content);
    }

    public function test_merge_does_not_duplicate_key_within_single_run(): void
    {
        $this->writeEnv('');

        $report = (new EnvSeedService(new SeedGeneratorStub('v')))->mergeIntoEnv([
            'A' => ['type' => 'secret', 'generator' => 'uuid'],
            'B' => ['type' => 'secret', 'generator' => 'uuid'],
        ], $this->envPath);

        // generator 对 A/B 返回同一结果，但键名不同 → 两条都写入
        $this->assertSame(['A', 'B'], $report['written']);
    }

    public function test_merge_commits_via_atomic_rename_preserving_existing_keys(): void
    {
        $this->writeEnv("KEEP=1\n");
        $inodeBefore = @fileinode($this->envPath);

        $report = (new EnvSeedService(new SeedGeneratorStub('gen')))->mergeIntoEnv([
            'NEW_KEY' => ['type' => 'secret', 'generator' => 'uuid'],
        ], $this->envPath);

        $this->assertSame(['NEW_KEY'], $report['written']);

        $content = $this->readEnv();
        $this->assertStringContainsString('KEEP=1', $content, '既有键不得丢失');
        $this->assertStringContainsString('NEW_KEY = gen', $content);

        // 同目录临时文件 + rename 会替换目录项（inode 变化）；
        // 原先的就地 ftruncate/fwrite 不会改变 inode。平台不支持时跳过该断言。
        $inodeAfter = @fileinode($this->envPath);
        if (is_int($inodeBefore) && $inodeBefore > 0 && is_int($inodeAfter) && $inodeAfter > 0) {
            $this->assertNotSame($inodeBefore, $inodeAfter, '提交应替换目录项（原子 rename）');
        }

        $this->assertSame([], glob($this->tmpDir . DIRECTORY_SEPARATOR . '.env.*.tmp') ?: [], '不得残留临时文件');
    }

    public function test_merge_skips_without_touching_env_when_lock_unavailable(): void
    {
        $this->writeEnv("KEEP=1\n");

        // 让锁文件的 fopen('c') 必然失败：在锁路径上放一个目录。
        // 不用 flock 抢占——服务端是阻塞式 LOCK_EX（会等其他 seeder 完成），
        // 抢占只会让它等待，而不会走到「拿不到锁则跳过」的分支。
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-env-seed-' . md5($this->envPath) . '.lock';
        @unlink($lockPath);
        @mkdir($lockPath);

        try {
            $report = (new EnvSeedService(new SeedGeneratorStub('gen')))->mergeIntoEnv([
                'NEW_KEY' => ['type' => 'secret', 'generator' => 'uuid'],
            ], $this->envPath);
        } finally {
            @rmdir($lockPath);
        }

        $this->assertSame([], $report['written'], '拿不到锁时不得写入');
        $this->assertStringContainsString('播种锁不可用', $report['warnings'][0] ?? '');
        $this->assertSame("KEEP=1\n", $this->readEnv(), '拿不到锁时 .env 必须保持原样');
    }

    public function test_merge_appends_after_existing_content_and_trims_tail(): void
    {
        $this->writeEnv("APP_DEBUG = true\n\n\n");

        (new EnvSeedService())->mergeIntoEnv([
            'A' => ['type' => 'static', 'default' => '1'],
        ], $this->envPath);

        $content = $this->readEnv();

        $this->assertStringStartsWith("APP_DEBUG = true\n\nA = 1", $content);
    }

    public function test_merge_skips_write_when_all_keys_exist(): void
    {
        $this->writeEnv("EXISTING = 1\n");

        $report = (new EnvSeedService())->mergeIntoEnv([
            'EXISTING' => ['type' => 'static', 'default' => '2'],
        ], $this->envPath);

        $this->assertSame([], $report['written']);
        $this->assertSame("EXISTING = 1\n", $this->readEnv());
    }

    public function test_merge_with_empty_specs_does_not_touch_env(): void
    {
        $this->writeEnv('UNCHANGED = 1');

        $report = (new EnvSeedService())->mergeIntoEnv([], $this->envPath);

        $this->assertSame(['written' => [], 'skipped' => [], 'warnings' => []], $report);
        $this->assertSame('UNCHANGED = 1', $this->readEnv());
    }

    // ============================================================
    // seed() — 编排
    // ============================================================

    public function test_seed_runs_full_pipeline(): void
    {
        $this->writeEnv('');
        $this->writeInstalledJson(json_encode([
            'packages' => [
                ['extra' => ['think' => ['services' => [SeedProviderSingleFixture::class]]]],
            ],
        ]));

        $report = (new EnvSeedService())->seed($this->envPath, $this->tmpDir);

        $this->assertSame(['SEED_ONLY_KEY'], $report['written']);
        $this->assertSame([], $report['warnings']);
        $this->assertMatchesRegularExpression('/^SEED_ONLY_KEY = [0-9a-f]{64}$/m', $this->readEnv());
    }

    public function test_seed_merges_discovery_warnings(): void
    {
        $this->writeEnv('');

        $report = (new EnvSeedService())->seed($this->envPath, $this->tmpDir);

        $this->assertSame([], $report['written']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertStringContainsString('已安装包清单不存在', $report['warnings'][0]);
    }

    public function test_seed_skips_existing_keys_end_to_end(): void
    {
        $this->writeEnv("SEED_ONLY_KEY = keep-me\n");
        $this->writeInstalledJson(json_encode([
            'packages' => [
                ['extra' => ['think' => ['services' => [SeedProviderSingleFixture::class]]]],
            ],
        ]));

        $report = (new EnvSeedService())->seed($this->envPath, $this->tmpDir);

        $this->assertSame([], $report['written']);
        $this->assertSame(['SEED_ONLY_KEY'], $report['skipped']);
        $this->assertStringContainsString('keep-me', $this->readEnv());
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class SeedGeneratorStub extends EnvValueGenerator
{
    public array $lastOptions = [];

    public function __construct(private mixed $value = 'generated', private ?\Throwable $error = null)
    {
    }

    public function generate(string $strategy, array $options = []): string|array
    {
        $this->lastOptions = $options;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->value;
    }
}

class SeedProviderFixture implements EnvSeedProvider
{
    public static function envSeeds(): array
    {
        return [
            'SEED_STATIC'   => ['type' => 'static', 'default' => 'abc', 'comment' => '静态值'],
            'SEED_EXTERNAL' => ['type' => 'external', 'comment' => '外部凭证'],
            'SEED_SECRET'   => ['type' => 'secret', 'generator' => 'random_bytes_32', 'comment' => '随机密钥'],
        ];
    }
}

class SeedProviderOverrideFixture implements EnvSeedProvider
{
    public static function envSeeds(): array
    {
        return [
            'SEED_STATIC' => ['type' => 'static', 'default' => 'override'],
        ];
    }
}

class SeedProviderSingleFixture implements EnvSeedProvider
{
    public static function envSeeds(): array
    {
        return [
            'SEED_ONLY_KEY' => ['type' => 'secret', 'generator' => 'random_bytes_32', 'comment' => '单键'],
        ];
    }
}

class SeedNotAProvider
{
    public static function envSeeds(): array
    {
        return ['SHOULD_NOT_APPEAR' => ['type' => 'static', 'default' => 'x']];
    }
}
