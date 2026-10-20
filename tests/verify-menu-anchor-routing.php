<?php
/**
 * 容器 anchor 三态（方案 B+）落库护栏
 *
 * 契约：{@see \wise\support\contracts\MenuItemProvider::containers()} —— 容器去向三态，
 * `mount = LAUNCHER` 优先于 `anchor`：
 *   · mount = LAUNCHER            → 启动台入口（顶层 + is_plugin_root，可钉选；一个实现只取首个）
 *   · anchor 缺省 / 'console'     → 控制台根下自持目录
 *   · anchor = 既有目录名          → 挂靠，不创建不拥有；目录非一级时只告警
 *   · anchor = 'launcher' / 入口 key → 挂到入口下作**同级**子分组
 *
 * 覆盖：
 *   A) 真实 MarketMenuProvider 的声明层校验（零写入）
 *   B) 落库：入口 + 同级分组（key 匹配 / 字面量兜底 / 既有目录）+ 入口直挂节点 + 层级
 *   C) 无入口容器 + anchor='launcher' → 告警 + 回退控制台
 *   D) 多个 mount=LAUNCHER → 取首个作入口 + 告警 + 其余降级
 *   E) 既有目录非一级 → 只告警不阻断
 *   F) 既有目录不存在 → 告警 + 该容器整批跳过
 *   G) 幂等（二次同步新增 0）
 *   H) 启动台：整包只出 1 条 + canPin=1 + 钉选/取消
 *
 * 运行（**需要可用的开发库**；脚本会自动清理自己写入的探针菜单/权限，零残留）：
 *   php src/packages/wiseadmin/wise-support/tests/verify-menu-anchor-routing.php
 * 退出码：0 = 全部通过，1 = 有失败
 */
require dirname(__DIR__, 4) . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\admin\model\Menu;
use app\admin\service\MenuItemSync;
use app\common\service\MenuLauncherService;
use think\facade\Db;
use wise\market\menu\MarketMenuProvider;
use wise\plugin\adapter\MenuRegistrar;
use wise\support\contracts\MenuItemProvider;
use wise\support\menu\MenuMount;

$fails = [];
$check = static function (bool $ok, string $msg) use (&$fails): void {
    printf("  %s %s\n", $ok ? '✔' : '✘', $msg);
    if (!$ok) {
        $fails[] = $msg;
    }
};

$probeIdents = ['wpanchor-a', 'wpanchor-b', 'wpanchor-c', 'wpanchor-d', 'wpanchor-e', 'wpanchor-f'];
$tmpDirId    = 0;

$cleanup = static function () use ($probeIdents, &$tmpDirId): void {
    foreach ($probeIdents as $ident) {
        Db::execute("delete from ws_menus where plugin = '{$ident}'");
        Db::execute("delete from ws_auth_permissions where plugin = '{$ident}'");
    }
    Db::execute("delete from ws_auth_permissions where route like '/admin/wpanchor/%'");

    if ($tmpDirId > 0) {
        Db::execute("delete from ws_menus where id = {$tmpDirId}");
    }
};

$consoleId = (int) (Db::query("select id from ws_menus where name='控制台' and type='dir' and delete_time is null limit 1")[0]['id'] ?? 0);
$logDirId  = (int) (Db::query("select id from ws_menus where name='日志管理' and type='dir' and delete_time is null limit 1")[0]['id'] ?? 0);

// ══════════════════════════ A) 真实 provider 声明层 ══════════════════════════
echo "== A) 真实 MarketMenuProvider 声明层（零写入）==\n";

$sync = new MenuItemSync();
$rm   = new ReflectionMethod($sync, 'prepareContainers');
$rm->setAccessible(true);
$declared = $rm->invoke($sync, new MarketMenuProvider());

$check(($declared['entry'] ?? '') === 'entry', '入口容器 = entry');
$check(count($declared['containers']) === 3, '容器 3 个（入口 + 2 分组）');
$check(($declared['containers']['entry']['mount'] ?? 0) === MenuMount::LAUNCHER, 'entry 声明 mount=LAUNCHER');
$check(($declared['containers']['entry']['anchor'] ?? '') === 'launcher', 'entry 的 anchor 归一出启动台语义');
$check(($declared['containers']['market']['anchor'] ?? '') === 'launcher', '分组「应用管理」anchor 归一为 launcher');
$check(($declared['containers']['audit']['anchor'] ?? '') === 'launcher', '分组「审核与许可」anchor 归一为 launcher');
$check($declared['warnings'] === [], '真实声明零告警');

$rg = new ReflectionMethod($sync, 'groupByContainer');
$rg->setAccessible(true);
$items = \wise\support\menu\MenuSchema::normalizeTree((new MarketMenuProvider())->items());
$w2    = [];
$groups = $rg->invokeArgs($sync, [$items, $declared['containers'], &$w2]);
$check(array_keys($groups) === ['market', 'audit'], '节点分组 = market/audit 两个分组容器');
$check(array_sum(array_map(static fn($g) => \wise\support\menu\MenuSchema::countNodes($g['items']), $groups)) === 24, '节点总数 24');
$check($w2 === [], '分组阶段零告警');

// ══════════════════════════ 探针实现 ══════════════════════════
abstract class ProbeBase implements MenuItemProvider
{
    protected function node(string $name, string $path, string $container, string $type = 'menu', array $children = []): array
    {
        $node = ['name' => $name, 'path' => $path, 'container' => $container, 'type' => $type];
        if ($children !== []) {
            $node['children'] = $children;
        }
        return $node;
    }
}

/** B) 入口 + key 匹配 / 字面量兜底 / 既有目录 + 入口直挂节点 */
final class WpA extends ProbeBase
{
    public function ident(): string { return 'wpanchor-a'; }

    public function containers(): array
    {
        return [
            ['key' => 'entry', 'name' => '探针入口', 'icon' => 'bi bi-shop', 'sort' => 9000, 'mount' => MenuMount::LAUNCHER],
            ['key' => 'ga', 'name' => '探针分组A', 'anchor' => 'entry', 'sort' => 10],        // 按 key 匹配入口
            ['key' => 'gb', 'name' => '探针分组B', 'anchor' => 'launcher', 'sort' => 20],     // 字面量兜底
            ['key' => 'gc', 'name' => '探针日志', 'anchor' => '日志管理', 'sort' => 30],       // 既有目录（不创建不拥有）
            ['key' => 'gm', 'name' => '探针缺失', 'anchor' => '不存在的目录XYZ', 'sort' => 40], // 缺失 → 跳过
        ];
    }

    public function items(): array
    {
        return [
            // 入口自身直接承载节点（用户确认允许）
            $this->node('探针入口页', '/admin/wpanchor/entry_page/index', 'entry', 'menu', [
                $this->node('探针入口按钮', '/admin/wpanchor/entry_page/export', 'entry', 'button'),
            ]),
            // 分组 A（key 匹配）
            $this->node('探针A页', '/admin/wpanchor/a_page/index', 'ga', 'menu', [
                $this->node('探针A按钮', '/admin/wpanchor/a_page/export', 'ga', 'button'),
            ]),
            // 分组 B（字面量）
            $this->node('探针B页', '/admin/wpanchor/b_page/index', 'gb'),
            // 既有目录
            $this->node('探针日志页', '/admin/wpanchor/log_page/index', 'gc'),
            // 缺失锚点（容器被跳过 → 该节点不应落库）
            $this->node('探针缺失页', '/admin/wpanchor/gm_page/index', 'gm'),
        ];
    }
}

/** C) 无入口 + anchor='launcher' → 回退控制台 */
final class WpC extends ProbeBase
{
    public function ident(): string { return 'wpanchor-c'; }

    public function containers(): array
    {
        return [['key' => 'g', 'name' => '探针无入口分组', 'anchor' => 'launcher', 'sort' => 9000]];
    }

    public function items(): array
    {
        return [$this->node('探针无入口页', '/admin/wpanchor/c_page/index', 'g')];
    }
}

/** D) 多个 LAUNCHER：首个作入口，其余降级 */
final class WpD extends ProbeBase
{
    public function ident(): string { return 'wpanchor-d'; }

    public function containers(): array
    {
        return [
            ['key' => 'e1', 'name' => '探针入口1', 'mount' => MenuMount::LAUNCHER, 'sort' => 9100],
            ['key' => 'e2', 'name' => '探针入口2', 'mount' => MenuMount::LAUNCHER, 'sort' => 9110],
            ['key' => 'g', 'name' => '探针D分组', 'anchor' => 'e1', 'sort' => 10],
        ];
    }

    public function items(): array
    {
        return [
            $this->node('探针D页', '/admin/wpanchor/d_page/index', 'g'),
            $this->node('探针E2页', '/admin/wpanchor/e2_page/index', 'e2'),
        ];
    }
}

/** E) 既有目录非一级 → 只告警不阻断 */
final class WpE extends ProbeBase
{
    public static string $anchorName = '';

    public function ident(): string { return 'wpanchor-e'; }

    public function containers(): array
    {
        return [['key' => 'g', 'name' => '探针深目录', 'anchor' => self::$anchorName, 'sort' => 9200]];
    }

    public function items(): array
    {
        return [$this->node('探针深目录页', '/admin/wpanchor/e_page/index', 'g')];
    }
}

$sync = new MenuItemSync();

// ══════════════════════════ B) 落库形态 ══════════════════════════
echo "\n== B) 入口 + 同级分组（含 key 匹配 / 字面量 / 既有目录 / 入口直挂）==\n";
$r = $sync->sync(new WpA());
printf("  结果：%s\n", strip_tags($r['message']));

$row = static fn(string $ident, string $name) => Db::query("select * from ws_menus where plugin='{$ident}' and name='{$name}' and delete_time is null limit 1")[0] ?? null;

$entry = $row('wpanchor-a', '探针入口');
$ga    = $row('wpanchor-a', '探针分组A');
$gb    = $row('wpanchor-a', '探针分组B');

$check($entry !== null && (int) $entry['parent_id'] === 0 && (int) $entry['mount'] === MenuMount::LAUNCHER && (int) $entry['is_plugin_root'] === 1,
    '入口：parent_id=0 + mount=3 + is_plugin_root=1');
$check($ga !== null && (int) $ga['parent_id'] === (int) $entry['id'], '分组A（anchor 写入口 key）挂在入口下');
$check($gb !== null && (int) $gb['parent_id'] === (int) $entry['id'], '分组B（anchor 字面量 launcher）挂在入口下');
$check($ga !== null && $gb !== null && (int) $ga['parent_id'] === (int) $gb['parent_id'], '两个分组**同级**（同一父=入口）');
$check((int) $ga['mount'] === MenuMount::MODULE && (int) $ga['is_plugin_root'] === 0, '分组 mount=MODULE / root=0（不参与启动台）');

$entryPage = $row('wpanchor-a', '探针入口页');
$check($entryPage !== null && (int) $entryPage['parent_id'] === (int) $entry['id'], '入口允许直挂节点（页面挂在入口下）');

$logPage = $row('wpanchor-a', '探针日志页');
$logRow  = Db::query("select id,plugin from ws_menus where name='日志管理' and type='dir' limit 1")[0] ?? null;
$check($logPage !== null && (int) $logPage['parent_id'] === (int) $logRow['id'], '既有目录锚点：节点挂到「日志管理」下');
$check(($logRow['plugin'] ?? '') === 'system', '既有目录**未被本实现接管**（plugin 仍为 system）');
$check($row('wpanchor-a', '探针缺失') === null, '锚点目录不存在 → 未创建自持容器');
$check($row('wpanchor-a', '探针缺失页') === null, '该容器的节点整批跳过（未落库）');
$check(str_contains(strip_tags($r['message']), '未找到挂靠目录'), '并给出「未找到挂靠目录」告警');
$check(!str_contains(strip_tags($r['message']), '不在侧边栏一级'), '挂靠一级目录「日志管理」不产生层级告警');

// 层级：页面相对入口 ≤ 2 层
$depth = static function (int $id): int {
    $d = 0;
    $cur = $id;
    $guard = 0;
    while ($cur > 0 && $guard++ < 16) {
        $p = Db::query("select parent_id from ws_menus where id={$cur}")[0] ?? null;
        if ($p === null || (int) $p['parent_id'] === 0) { break; }
        $cur = (int) $p['parent_id'];
        $d++;
    }
    return $d;
};
$maxMenuDepth = 0;
foreach (Db::query("select id from ws_menus where plugin='wpanchor-a' and type='menu' and delete_time is null") as $m) {
    $maxMenuDepth = max($maxMenuDepth, $depth((int) $m['id']));
}
$check($maxMenuDepth <= 2, "页面节点最深 {$maxMenuDepth} 层（≤3 层渲染范围内）");

// ══════════════════════════ C) 无入口回退 ══════════════════════════
echo "\n== C) 无 LAUNCHER 容器 + anchor='launcher' → 告警 + 回退控制台 ==\n";
$r = $sync->sync(new WpC());
$msg = strip_tags($r['message']);
$cRow = $row('wpanchor-c', '探针无入口分组');
$check($cRow !== null && (int) $cRow['parent_id'] === $consoleId, '分组回退到「控制台」下');
$check(str_contains($msg, '没有 mount=LAUNCHER'), '给出「没有 mount=LAUNCHER 入口容器」告警');
$check(str_contains($msg, '回退到「控制台」'), '告警中说明已回退控制台');

// ══════════════════════════ D) 多入口降级 ══════════════════════════
echo "\n== D) 多个 mount=LAUNCHER → 取首个作入口 + 告警 + 其余降级 ==\n";
$r = $sync->sync(new WpD());
$msg = strip_tags($r['message']);
$e1 = $row('wpanchor-d', '探针入口1');
$e2 = $row('wpanchor-d', '探针入口2');
$gd = $row('wpanchor-d', '探针D分组');
$check($e1 !== null && (int) $e1['parent_id'] === 0 && (int) $e1['is_plugin_root'] === 1, '首个 LAUNCHER 容器作入口（顶层）');
$check($e2 !== null && (int) $e2['parent_id'] === $consoleId && (int) $e2['mount'] === MenuMount::MODULE, '第二个 LAUNCHER 容器降级为普通分组（挂控制台）');
$check($gd !== null && (int) $gd['parent_id'] === (int) $e1['id'], 'anchor 写「e1」的分组挂到入口下');
$check(str_contains($msg, '也声明了 mount=LAUNCHER'), '给出「多个 LAUNCHER」告警');

// ══════════════════════════ E) 深目录告警 ══════════════════════════
echo "\n== E) 挂靠目录非一级 → 只告警不阻断 ==\n";
// 找一个「其父不是系统顶级」的目录；没有则临时建一个（结束后删除）
$deep = Db::query("select c.id,c.name from ws_menus c join ws_menus p on p.id=c.parent_id where c.type='dir' and c.delete_time is null and p.parent_id<>0 limit 1")[0] ?? null;

if ($deep === null) {
    $tmpDir = new Menu();
    $tmpDir->save([
        'parent_id' => $logDirId,
        'name'      => '探针临时深目录',
        'icon'      => 'bi bi-folder',
        'path'      => '',
        'type'      => 'dir',
        'status'    => 1,
        'plugin'    => 'system',
        'sort'      => 99,
    ]);
    $tmpDirId = (int) $tmpDir->id;
    $deep     = ['id' => $tmpDirId, 'name' => '探针临时深目录'];
    echo "  （已临时创建用于验证的二级目录 id={$tmpDirId}）\n";
}

WpE::$anchorName = (string) $deep['name'];

$r = $sync->sync(new WpE());
$msg   = strip_tags($r['message']);
$ePage = $row('wpanchor-e', '探针深目录页');
$check($ePage !== null && (int) $ePage['parent_id'] === (int) $deep['id'], '仍然挂靠成功（只告警不阻断）');
$check(str_contains($msg, '不在侧边栏一级'), '给出「不在侧边栏一级」告警');
$check(str_contains($msg, '菜单管理'), '告警中提示到「菜单管理」调整');

// ══════════════════════════ F) 缺失目录跳过 ══════════════════════════
echo "\n== F) 锚点目录缺失 → 告警 + 跳过（既有行为回归）==\n";
$r   = $sync->sync(new WpA());
$msg = strip_tags($r['message']);
$check($row('wpanchor-a', '探针缺失') === null, '缺失锚点的容器仍未创建');
$check(str_contains($msg, '未找到挂靠目录'), '告警保持');

// ══════════════════════════ G/H) 幂等 + 启动台 ══════════════════════════
echo "\n== G) 幂等 ==\n";
$r = $sync->sync(new WpA());
$check((int) $r['created'] === 0, '二次同步新增 0');

echo "\n== H) 启动台：整包一条 + 可钉选 ==\n";
$roots = [];
foreach (Db::query("select id,parent_id,name,icon,path,plugin,is_plugin_root,mount,pinned from ws_menus where parent_id=0 and delete_time is null") as $x) {
    $x['childlist'] = [];
    $roots[] = $x;
}
$layout = MenuLauncherService::classifyMenuLayout($roots);
$mine = array_values(array_filter($layout['launcherPlugins'], static fn($i) => ($i['ident'] ?? '') === 'wpanchor-a'));
$check(count($mine) === 1, '启动台里该实现只出 1 条');
$check(($mine[0]['title'] ?? '') === '探针入口' && ($mine[0]['canPin'] ?? 0) === 1, '条目=探针入口 / canPin=1');
$check(array_filter($layout['launcherPlugins'], static fn($i) => ($i['ident'] ?? '') === 'wpanchor-d') !== []
    && count(array_filter($layout['launcherPlugins'], static fn($i) => ($i['ident'] ?? '') === 'wpanchor-d')) === 1,
    '多入口声明也只剩 1 条');

$registrar = new MenuRegistrar();
$check($registrar->pinPlugin('wpanchor-a') === true, 'pinPlugin() 成功');
$check($registrar->unpinPlugin('wpanchor-a') === true, 'unpinPlugin() 成功');

// ══════════════════════════ 清理 ══════════════════════════
echo "\n== 清理探针数据 ==\n";
$cleanup();
$left = 0;
foreach ($probeIdents as $ident) {
    $left += (int) (Db::query("select count(*) c from ws_menus where plugin='{$ident}'")[0]['c'] ?? 0);
}
$check($left === 0, '探针菜单已全部删除（含软删行）');
$check((int) (Db::query("select count(*) c from ws_auth_permissions where route like '/admin/wpanchor/%'")[0]['c'] ?? 0) === 0, '探针权限已删除');

echo "\n", $fails === [] ? "全部通过 ✔\n" : ('失败 ' . count($fails) . " 项：\n - " . implode("\n - ", $fails) . "\n");
exit($fails === [] ? 0 : 1);
