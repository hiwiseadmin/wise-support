<?php
declare(strict_types=1);

/**
 * ExtPoint 子系统迁移验证脚本
 *
 * 模拟各包 ServiceProvider 的注册逻辑，验证从配置读取
 * 并注册到 ExtPoint 的完整流程。
 *
 * 运行方式: php tests/test_extpoint_migration.php
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'wise\\support\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use wise\support\ExtPoint;
use wise\support\ExtPoints;

$pass = 0;
$fail = 0;

function assert_eq($label, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_count($label, int $expected, array $actual): void {
    assert_eq($label, $expected, count($actual));
}

echo "=== ExtPoint 子系统迁移验证 ===\n\n";

// ============================================================
// 1. 模拟 wise-oauth 迁移
// ============================================================
echo "1. wise-oauth → ExtPoints::OAUTH_DRIVER (routed)\n";
ExtPoint::reset();

// 模拟 config('wise-oauth.platforms')
$oauthPlatforms = [
    'wechat_open' => ['driver' => '\\wise\\oauth\\driver\\WechatOpenDriver', 'enabled' => true],
    'wechat_mp'   => ['driver' => '\\wise\\oauth\\driver\\WechatMpDriver', 'enabled' => true],
    'github'      => ['driver' => '\\wise\\oauth\\driver\\GithubDriver', 'enabled' => true],
    'google'      => ['driver' => '\\wise\\oauth\\driver\\GoogleDriver', 'enabled' => true],
];

if (!ExtPoint::defined(ExtPoints::OAUTH_DRIVER)) {
    ExtPoint::definePoint(ExtPoints::OAUTH_DRIVER, ExtPoint::POLICY_ROUTED);
}

foreach ($oauthPlatforms as $name => $config) {
    $driverClass = $config['driver'] ?? null;
    if ($driverClass) {
        ExtPoint::register(ExtPoints::OAUTH_DRIVER, $name, $driverClass);
    }
}

$allOauth = ExtPoint::all(ExtPoints::OAUTH_DRIVER);
assert_count('注册 4 个 OAuth 平台', 4, $allOauth);
assert_eq('按名称路由 github', '\\wise\\oauth\\driver\\GithubDriver', ExtPoint::get(ExtPoints::OAUTH_DRIVER, 'github'));
assert_eq('按名称路由 wechat_open', '\\wise\\oauth\\driver\\WechatOpenDriver', ExtPoint::get(ExtPoints::OAUTH_DRIVER, 'wechat_open'));

// ============================================================
// 2. 模拟 wise-notification 迁移
// ============================================================
echo "\n2. wise-notification → ExtPoints::NOTIFICATION_CHANNEL (multi)\n";
ExtPoint::reset();

// 模拟 NotificationManager::$defaultDrivers (通过反射读取)
$notifDrivers = [
    'email'     => '\\wise\\notification\\channel\\EmailChannel',
    'database'  => '\\wise\\notification\\channel\\DatabaseChannel',
    'sms'       => '\\wise\\notification\\channel\\SmsChannel',
    'dingtalk'  => '\\wise\\notification\\channel\\DingtalkChannel',
    'wecom'     => '\\wise\\notification\\channel\\WecomChannel',
    'feishu'    => '\\wise\\notification\\channel\\FeishuChannel',
    'webhook'   => '\\wise\\notification\\channel\\WebhookChannel',
    'wechat_oa' => '\\wise\\notification\\channel\\WechatOfficialAccountChannel',
];

if (!ExtPoint::defined(ExtPoints::NOTIFICATION_CHANNEL)) {
    ExtPoint::definePoint(ExtPoints::NOTIFICATION_CHANNEL, ExtPoint::POLICY_MULTI);
}

foreach ($notifDrivers as $name => $class) {
    ExtPoint::register(ExtPoints::NOTIFICATION_CHANNEL, $name, $class);
}

$allNotif = ExtPoint::all(ExtPoints::NOTIFICATION_CHANNEL);
assert_count('注册 8 个通知通道', 8, $allNotif);
assert_eq('email 通道存在', '\\wise\\notification\\channel\\EmailChannel', ExtPoint::get(ExtPoints::NOTIFICATION_CHANNEL, 'email'));
assert_eq('sms 通道存在', '\\wise\\notification\\channel\\SmsChannel', ExtPoint::get(ExtPoints::NOTIFICATION_CHANNEL, 'sms'));

// ============================================================
// 3. 模拟 wise-workflow 迁移
// ============================================================
echo "\n3. wise-workflow → ExtPoints::WORKFLOW_PIPE (multi)\n";
ExtPoint::reset();

// 模拟 config('wise-workflow.pipelines.default')
$defaultPipes = [
    '\\wise\\workflow\\pipes\\FindTransitionPipe',
    '\\wise\\workflow\\pipes\\CheckPendingTasksPipe',
    '\\wise\\workflow\\pipes\\LoadMarkingPipe',
    '\\wise\\workflow\\pipes\\TransitionStatePipe',
];

// 模拟 config('wise-workflow.pipe_slots')
$pipeSlots = [
    'before_LoadMarking' => [],
    'after_VerifyVersion' => ['\\plugin\\audit\\AuditPipe'],
];

if (!ExtPoint::defined(ExtPoints::WORKFLOW_PIPE)) {
    ExtPoint::definePoint(ExtPoints::WORKFLOW_PIPE, ExtPoint::POLICY_MULTI);
}

foreach ($defaultPipes as $index => $pipeClass) {
    $shortName = substr($pipeClass, strrpos($pipeClass, '\\') + 1);
    ExtPoint::register(ExtPoints::WORKFLOW_PIPE, $shortName, $pipeClass, $index);
}

foreach ($pipeSlots as $slotName => $pipes) {
    if (!is_array($pipes)) continue;
    foreach ($pipes as $index => $pipeClass) {
        $key = $slotName . '_' . $index;
        ExtPoint::register(ExtPoints::WORKFLOW_PIPE, $key, $pipeClass);
    }
}

$allPipes = ExtPoint::all(ExtPoints::WORKFLOW_PIPE);
assert_count('注册 5 个工作流管道（4 默认 + 1 插槽）', 5, $allPipes);
assert_eq('FindTransitionPipe 存在', '\\wise\\workflow\\pipes\\FindTransitionPipe', ExtPoint::get(ExtPoints::WORKFLOW_PIPE, 'FindTransitionPipe'));
assert_eq('插槽 pipe 存在', '\\plugin\\audit\\AuditPipe', ExtPoint::get(ExtPoints::WORKFLOW_PIPE, 'after_VerifyVersion_0'));

// ============================================================
// 4. 模拟 wise-image 迁移
// ============================================================
echo "\n4. wise-image → ExtPoints::IMAGE_DRIVER (single)\n";
ExtPoint::reset();

$imageDrivers = [
    'gd'      => '\\wise\\image\\driver\\GdDriver',
    'imagick' => '\\wise\\image\\driver\\ImagickDriver',
];
$activeImageDriver = 'gd';

if (!ExtPoint::defined(ExtPoints::IMAGE_DRIVER)) {
    ExtPoint::definePoint(ExtPoints::IMAGE_DRIVER, ExtPoint::POLICY_SINGLE);
}

foreach ($imageDrivers as $name => $class) {
    ExtPoint::register(ExtPoints::IMAGE_DRIVER, $name, $class);
}
ExtPoint::setActive(ExtPoints::IMAGE_DRIVER, $activeImageDriver, true);

$active = ExtPoint::active(ExtPoints::IMAGE_DRIVER);
assert_eq('gd 是活跃驱动', 'gd', $active['name']);

// 切换到 imagick
ExtPoint::setActive(ExtPoints::IMAGE_DRIVER, 'imagick', true);
$active2 = ExtPoint::active(ExtPoints::IMAGE_DRIVER);
assert_eq('切换到 imagick', 'imagick', $active2['name']);

// 验证 single 策略
$all = ExtPoint::all(ExtPoints::IMAGE_DRIVER);
$activeCount = 0;
foreach ($all as $item) {
    if ($item['active']) $activeCount++;
}
assert_eq('single 策略只有一个 active', 1, $activeCount);

// ============================================================
// 5. 模拟 wise-login-security 迁移
// ============================================================
echo "\n5. wise-login-security → ExtPoints::CAPTCHA_DRIVER (single)\n";
ExtPoint::reset();

// 模拟 CaptchaDriverFactory::$map
$captchaMap = [
    'gd'       => '\\wise\\loginsecurity\\captcha\\GdCaptchaDriver',
    'geetest'  => '\\wise\\loginsecurity\\captcha\\GeetestV4CaptchaDriver',
    'tencent'  => '\\wise\\loginsecurity\\captcha\\TencentCaptchaDriver',
    'hcaptcha' => '\\wise\\loginsecurity\\captcha\\HcaptchaDriver',
];
$activeCaptcha = 'geetest';

if (!ExtPoint::defined(ExtPoints::CAPTCHA_DRIVER)) {
    ExtPoint::definePoint(ExtPoints::CAPTCHA_DRIVER, ExtPoint::POLICY_SINGLE);
}

foreach ($captchaMap as $name => $class) {
    ExtPoint::register(ExtPoints::CAPTCHA_DRIVER, $name, $class);
}
ExtPoint::setActive(ExtPoints::CAPTCHA_DRIVER, $activeCaptcha, true);

$allCaptcha = ExtPoint::all(ExtPoints::CAPTCHA_DRIVER);
assert_count('注册 4 个验证码驱动', 4, $allCaptcha);
assert_eq('活跃驱动是 geetest', 'geetest', ExtPoint::active(ExtPoints::CAPTCHA_DRIVER)['name']);

// ============================================================
// 6. 模拟 wise-agent 迁移
// ============================================================
echo "\n6. wise-agent → ExtPoints::AI_TOOL / AI_SKILL / AI_CONTEXT_PROVIDER (multi)\n";
ExtPoint::reset();

// 模拟 config('wise-agent.tools')
$agentTools = [
    'file_read'   => ['class' => '\\wise\\agent\\tool\\FileReadTool', 'enabled' => false],
    'file_write'  => ['class' => '\\wise\\agent\\tool\\FileWriteTool', 'enabled' => false],
    'db_query'    => ['class' => '\\wise\\agent\\tool\\DatabaseQueryTool', 'enabled' => true],
    'orchestrate' => ['class' => '\\wise\\agent\\tool\\OrchestrateStepTool', 'enabled' => true],
    'web_search'  => ['class' => '\\wise\\agent\\tool\\WebSearchTool', 'enabled' => true],
];

// 模拟 config('wise-agent.skills.builtin')
$agentSkills = [
    'title_generate'   => '\\wise\\agent\\skill\\TitleGenerateSkill',
    'article_generate' => '\\wise\\agent\\skill\\ArticleGenerateSkill',
    'translate'        => '\\wise\\agent\\skill\\TranslateSkill',
];

// 模拟 config('wise-agent.context.builtin')
$agentContext = [
    '\\wise\\agent\\context\\builtin\\SystemContextProvider' => true,
    '\\wise\\agent\\context\\builtin\\UserContextProvider' => true,
];

if (!ExtPoint::defined(ExtPoints::AI_TOOL)) {
    ExtPoint::definePoint(ExtPoints::AI_TOOL, ExtPoint::POLICY_MULTI);
}
foreach ($agentTools as $name => $config) {
    $class = $config['class'] ?? null;
    if ($class) {
        $priority = $config['enabled'] ? 10 : 200;
        ExtPoint::register(ExtPoints::AI_TOOL, $name, $class, $priority);
    }
}

if (!ExtPoint::defined(ExtPoints::AI_SKILL)) {
    ExtPoint::definePoint(ExtPoints::AI_SKILL, ExtPoint::POLICY_MULTI);
}
foreach ($agentSkills as $name => $class) {
    ExtPoint::register(ExtPoints::AI_SKILL, $name, $class);
}

if (!ExtPoint::defined(ExtPoints::AI_CONTEXT_PROVIDER)) {
    ExtPoint::definePoint(ExtPoints::AI_CONTEXT_PROVIDER, ExtPoint::POLICY_MULTI);
}
foreach ($agentContext as $class => $enabled) {
    $priority = $enabled ? 10 : 200;
    ExtPoint::register(ExtPoints::AI_CONTEXT_PROVIDER, $class, $class, $priority);
}

// 验证
$allTools = ExtPoint::all(ExtPoints::AI_TOOL);
assert_count('注册 5 个 AI 工具', 5, $allTools);

$allSkills = ExtPoint::all(ExtPoints::AI_SKILL);
assert_count('注册 3 个 AI 技能', 3, $allSkills);

$allContexts = ExtPoint::all(ExtPoints::AI_CONTEXT_PROVIDER);
assert_count('注册 2 个上下文提供者', 2, $allContexts);

// 验证 enabled 工具优先排序（优先级相等时维持插入顺序）
$toolKeys = array_keys($allTools);
$thisEnabled = [];
foreach ($agentTools as $name => $c) {
    if ($c['enabled']) $thisEnabled[] = $name;
}
assert_eq('enabled 工具排前面（优先级高）', $thisEnabled[0], $toolKeys[0]);

// ============================================================
// 7. 综合验证：所有扩展点共存
// ============================================================
echo "\n7. 综合：所有扩展点共存\n";
ExtPoint::reset();

// 逐一注册所有扩展点
ExtPoint::definePoint(ExtPoints::OAUTH_DRIVER, ExtPoint::POLICY_ROUTED);
ExtPoint::definePoint(ExtPoints::NOTIFICATION_CHANNEL, ExtPoint::POLICY_MULTI);
ExtPoint::definePoint(ExtPoints::WORKFLOW_PIPE, ExtPoint::POLICY_MULTI);
ExtPoint::definePoint(ExtPoints::IMAGE_DRIVER, ExtPoint::POLICY_SINGLE);
ExtPoint::definePoint(ExtPoints::CAPTCHA_DRIVER, ExtPoint::POLICY_SINGLE);
ExtPoint::definePoint(ExtPoints::AI_TOOL, ExtPoint::POLICY_MULTI);
ExtPoint::definePoint(ExtPoints::AI_SKILL, ExtPoint::POLICY_MULTI);
ExtPoint::definePoint(ExtPoints::AI_CONTEXT_PROVIDER, ExtPoint::POLICY_MULTI);

// 模拟所有迁移的注册
foreach ($oauthPlatforms as $name => $c) {
    ExtPoint::register(ExtPoints::OAUTH_DRIVER, $name, $c['driver']);
}
foreach ($notifDrivers as $name => $class) {
    ExtPoint::register(ExtPoints::NOTIFICATION_CHANNEL, $name, $class);
}
foreach ($defaultPipes as $i => $pipeClass) {
    ExtPoint::register(ExtPoints::WORKFLOW_PIPE, substr($pipeClass, strrpos($pipeClass, '\\') + 1), $pipeClass, $i);
}
foreach ($imageDrivers as $name => $class) {
    ExtPoint::register(ExtPoints::IMAGE_DRIVER, $name, $class);
}
foreach ($captchaMap as $name => $class) {
    ExtPoint::register(ExtPoints::CAPTCHA_DRIVER, $name, $class);
}
foreach ($agentTools as $name => $c) {
    ExtPoint::register(ExtPoints::AI_TOOL, $name, $c['class'], $c['enabled'] ? 10 : 200);
}

$points = ExtPoint::points();
assert_count('注册 8 个扩展点（含上下文提供者）', 8, $points);

$totalImplementations = 0;
foreach ($points as $point) {
    $totalImplementations += ExtPoint::count($point);
}
// OAuth:4 + Notification:8 + Workflow:4 + Image:2 + Captcha:4 + AI Tool:5 = 27
assert_eq('合计 27 个实现', 27, $totalImplementations);

// ============================================================
// 结果
// ============================================================
echo "\n============================\n";
echo "  通过: {$pass}  |  失败: {$fail}\n";
echo "============================\n";

if ($fail > 0) {
    exit(1);
}
