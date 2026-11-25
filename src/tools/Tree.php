<?php
namespace wise\support\tools;

/**
 * 通用树形类
 *
 * 功能特性：
 * 1. 多格式输出：HTML option、UL列表、数组、文本、JSON等
 * 2. 灵活配置：可自定义字段名、缩进符号、样式等
 * 3. 高效查询：多种节点查询方法
 * 4. 状态控制：支持选中和禁用状态设置
 * 5. 数据操作：支持节点增删改、移动、排序
 * 6. 验证检查：循环引用检测、数据完整性验证
 * 7. 单例模式：支持单例和实例复用
 * 8. 性能优化：内置缓存机制
 * 9. 安全防护：HTML 输出自动转义、递归环路保护
 *
 * @author AI Assistant
 * @version 2.1
 */
class Tree
{
    public $options = [
        'icon' => ['│', '├', '└'],
        'nbsp' => "&emsp;",
        'pidname' => 'parent_id',
        'idname' => 'id',
        'namefield' => 'name',
        'sortfield' => 'sort',
        'childKey' => 'children',
        'enable_cache' => true,
        'cache_ttl' => 300,
        'auto_sort' => true,
        'sort_direction' => 'asc'
    ];

    /**
     * 生成树型结构所需要的2维数组
     * @var array
     */
    public $arr = [];

    /**
     * 数据缓存
     * @var array
     */
    protected $cache = [];

    /**
     * ID 集合解析缓存（避免每个节点重复 explode 选中/禁用列表）
     * @var array
     */
    protected $idSetCache = [];

    /**
     * 生成树型结构所需修饰符号
     * @var array
     */
    public $icon = [];
    public $nbsp = "";
    public $pidname = '';
    public $idname = '';
    public $namefield = '';
    public $sortfield = '';
    public $childKey = '';

    protected $stats = [
        'node_count' => 0,
        'max_depth' => 0,
        'execution_time' => 0
    ];

    /**
     * 构造函数
     * @param array $options 配置选项
     */
    public function __construct($options = [])
    {
        $startTime = microtime(true);

        $this->options = array_merge($this->options, $options);
        $this->applyOptions();
        
        $this->stats['execution_time'] = microtime(true) - $startTime;
    }

    /**
     * 应用配置选项到属性
     */
    protected function applyOptions()
    {
        $properties = ['icon', 'nbsp', 'pidname', 'idname', 'namefield', 'sortfield', 'childKey'];
        foreach ($properties as $property) {
            if (isset($this->options[$property])) {
                $this->$property = $this->options[$property];
            }
        }
    }

    /**
     * 获取 Tree 实例
     *
     * 每次调用均返回新实例（不使用静态单例），避免常驻进程（Swoole）下跨协程/跨请求
     * 复用同一实例导致的 $arr / $cache / 配置串号问题。
     *
     * @param array $options 配置选项
     * @return static
     */
    public static function instance($options = [])
    {
        return new static($options);
    }

    /**
     * 更新配置选项
     * @param array $options 配置选项
     * @return $this
     */
    public function updateOptions($options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->applyOptions();
        $this->clearCache();
        return $this;
    }

    /**
     * 初始化数据
     * @param array $arr 数据数组
     * @param string|null $pidname 父ID字段名
     * @param string|null $idname ID字段名
     * @param string|null $nbsp 缩进符号
     * @return $this
     */
    public function init($arr = [], $pidname = null, $idname = null, $nbsp = null)
    {
        $this->arr = is_array($arr) ? $arr : [];
        $this->stats['node_count'] = count($this->arr);
        
        if (!is_null($pidname)) $this->pidname = $pidname;
        if (!is_null($idname)) $this->idname = $idname;
        if (!is_null($nbsp)) $this->nbsp = $nbsp;

        $this->clearCache();
        
        if ($this->options['auto_sort']) {
            $this->sortTree();
        }
        
        return $this;
    }

    /**
     * 清空缓存
     * @return $this
     */
    public function clearCache()
    {
        $this->cache = [];
        $this->idSetCache = [];
        return $this;
    }

    /**
     * 获取缓存数据
     * @param string $key 缓存键
     * @return mixed|null 未命中返回 null（空数组是合法缓存值）
     */
    protected function getCache($key)
    {
        if (!$this->options['enable_cache']) return null;
        
        if (isset($this->cache[$key]) && 
            (time() - $this->cache[$key]['timestamp']) < $this->options['cache_ttl']) {
            return $this->cache[$key]['data'];
        }
        return null;
    }

    /**
     * 设置缓存数据
     * @param string $key 缓存键
     * @param mixed $data 缓存数据
     */
    protected function setCache($key, $data)
    {
        if (!$this->options['enable_cache']) return;
        
        $this->cache[$key] = [
            'data' => $data,
            'timestamp' => time()
        ];
    }

    /**
     * 归一化节点ID，保证不同来源（int / string / null）的数据可以一致比较
     * @param mixed $id
     * @return string
     */
    protected function normalizeId($id)
    {
        return $id === null ? '0' : (string)$id;
    }

    /**
     * 构建 ID => 节点 的索引（带缓存，避免 O(n·depth) 全表扫描）
     * @return array
     */
    protected function buildIndex()
    {
        $cached = $this->getCache('node_index');
        if ($cached !== null) {
            return $cached;
        }

        $index = [];
        foreach ($this->arr as $value) {
            if (isset($value[$this->idname])) {
                $index[$this->normalizeId($value[$this->idname])] = $value;
            }
        }

        $this->setCache('node_index', $index);
        return $index;
    }

    /**
     * 对树数据进行排序
     * @param string|null $sortField 排序字段
     * @param string|null $direction 排序方向
     * @return $this
     */
    public function sortTree($sortField = null, $direction = null)
    {
        $sortField = $sortField ?: $this->sortfield;
        $direction = $direction ?: $this->options['sort_direction'];
        
        usort($this->arr, function($a, $b) use ($sortField, $direction) {
            $valA = isset($a[$sortField]) ? $a[$sortField] : 0;
            $valB = isset($b[$sortField]) ? $b[$sortField] : 0;
            
            if ($direction === 'desc') {
                return $valB <=> $valA;
            }
            return $valA <=> $valB;
        });
        
        $this->clearCache();
        return $this;
    }

    /**
     * 获取直接子节点
     * @param mixed $myid 当前节点ID
     * @return array
     */
    public function getChild($myid)
    {
        $cacheKey = "child_{$myid}";
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $mykey = $this->normalizeId($myid);
        $newarr = [];
        foreach ($this->arr as $value) {
            if (!isset($value[$this->idname], $value[$this->pidname])) {
                continue;
            }

            if ($this->normalizeId($value[$this->pidname]) === $mykey) {
                $newarr[$value[$this->idname]] = $value;
            }
        }

        $this->setCache($cacheKey, $newarr);
        return $newarr;
    }

    /**
     * 获取所有子孙节点（递归）
     * @param mixed $myid 当前节点ID
     * @param bool $withself 是否包含自身（自身固定排在最前）
     * @return array
     */
    public function getChildren($myid, $withself = false)
    {
        $cacheKey = "children_{$myid}_" . ($withself ? '1' : '0');
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $mykey = $this->normalizeId($myid);
        $newarr = [];
        $visited = [$mykey => true];

        if ($withself) {
            foreach ($this->arr as $value) {
                if (isset($value[$this->idname]) && $this->normalizeId($value[$this->idname]) === $mykey) {
                    $newarr[] = $value;
                    break;
                }
            }
        }

        $this->collectChildren($mykey, $newarr, $visited);

        $this->setCache($cacheKey, $newarr);
        return $newarr;
    }

    /**
     * 递归收集子孙节点（带环路保护，避免脏数据导致栈溢出）
     * @param mixed $myid 当前节点ID
     * @param array $newarr 结果集（引用传递，避免 array_merge 反复重建）
     * @param array $visited 已访问节点集合（引用传递）
     */
    protected function collectChildren($myid, array &$newarr, array &$visited)
    {
        $mykey = $this->normalizeId($myid);
        foreach ($this->arr as $value) {
            if (!isset($value[$this->idname], $value[$this->pidname])) {
                continue;
            }

            $childKey = $this->normalizeId($value[$this->idname]);
            if ($this->normalizeId($value[$this->pidname]) !== $mykey || isset($visited[$childKey])) {
                continue;
            }

            $visited[$childKey] = true;
            $newarr[] = $value;
            $this->collectChildren($childKey, $newarr, $visited);
        }
    }

    /**
     * 获取所有子孙节点ID
     * @param mixed $myid 当前节点ID
     * @param bool $withself 是否包含自身
     * @return array
     */
    public function getChildrenIds($myid, $withself = false)
    {
        $cacheKey = "children_ids_{$myid}_" . ($withself ? '1' : '0');
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $childrenlist = $this->getChildren($myid, $withself);
        $childrenids = array_column($childrenlist, $this->idname);

        $this->setCache($cacheKey, $childrenids);
        return $childrenids;
    }

    /**
     * 获取直接父节点
     * @param mixed $myid 当前节点ID
     * @return array|null
     */
    public function getParent($myid)
    {
        $index = $this->buildIndex();
        $mykey = $this->normalizeId($myid);

        if (!isset($index[$mykey])) {
            return null;
        }

        $pid = isset($index[$mykey][$this->pidname]) ? $this->normalizeId($index[$mykey][$this->pidname]) : '0';

        if ($pid === '0' || $pid === '') {
            return null;
        }

        return isset($index[$pid]) ? $index[$pid] : null;
    }

    /**
     * 获取所有祖先节点
     * @param mixed $myid 当前节点ID
     * @param bool $withself 是否包含自身
     * @return array
     */
    public function getParents($myid, $withself = false)
    {
        $cacheKey = "parents_{$myid}_" . ($withself ? '1' : '0');
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $newarr = [];
        $mykey = $this->normalizeId($myid);

        $index = $this->buildIndex();

        if (!isset($index[$mykey])) {
            $this->setCache($cacheKey, $newarr);
            return $newarr;
        }

        $current = $index[$mykey];
        if ($withself) {
            $newarr[] = $current;
        }

        $pid = isset($current[$this->pidname]) ? $this->normalizeId($current[$this->pidname]) : '0';
        $visited = [$mykey => true];

        while ($pid !== '0' && $pid !== '') {
            if (!isset($index[$pid]) || isset($visited[$pid])) {
                break;
            }

            $visited[$pid] = true;
            $parent = $index[$pid];
            array_unshift($newarr, $parent);
            $pid = isset($parent[$this->pidname]) ? $this->normalizeId($parent[$this->pidname]) : '0';
        }

        $this->setCache($cacheKey, $newarr);
        return $newarr;
    }

    /**
     * 获取所有祖先节点ID
     * @param mixed $myid 当前节点ID
     * @param bool $withself 是否包含自身
     * @return array
     */
    public function getParentsIds($myid, $withself = false)
    {
        $parentlist = $this->getParents($myid, $withself);
        return array_column($parentlist, $this->idname);
    }

    /**
     * 生成树型结构 Option
     * @param mixed $myid 起始节点ID
     * @param string $itemtpl 项目模板
     * @param string|array $selectedids 选中的ID
     * @param string|array $disabledids 禁用的ID
     * @param string $itemprefix 前缀
     * @param string $toptpl 顶级模板
     * @param array $visited 已访问节点（内部递归用，环路保护）
     * @return string
     */
    public function getTree($myid, $itemtpl = "<option value=@id @selected @disabled>@spacer@name</option>", $selectedids = '', $disabledids = '', $itemprefix = '', $toptpl = '', array $visited = [])
    {
        $ret = '';
        $number = 1;

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return $ret;
        }
        $visited[$mykey] = true;

        $childs = $this->getChild($myid);
        
        if ($childs) {
            $total = count($childs);
            foreach ($childs as $value) {
                $id = $value[$this->idname];
                $j = $k = '';
                if ($number == $total) {
                    $j .= $this->icon[2];
                    $k = $itemprefix ? $this->nbsp : '';
                } else {
                    $j .= $this->icon[1];
                    $k = $itemprefix ? $this->icon[0] : '';
                }
                $spacer = $itemprefix ? $itemprefix . $j : '';
                
                $selected = $this->isSelected($id, $selectedids) ? 'selected' : '';
                $disabled = $this->isDisabled($id, $disabledids) ? 'disabled' : '';

                $value = array_merge($this->escapeFields($value), [
                    'selected' => $selected, 
                    'disabled' => $disabled, 
                    'spacer' => $spacer
                ]);
                
                $value = $this->addTemplatePrefix($value);
                $pidValue = isset($value["@{$this->pidname}"]) ? $value["@{$this->pidname}"] : '0';
                $nstr = strtr((($pidValue == 0 || $this->getChild($id)) && $toptpl ? $toptpl : $itemtpl), $value);
                $ret .= $nstr;
                $ret .= $this->getTree($id, $itemtpl, $selectedids, $disabledids, $itemprefix . $k . $this->nbsp, $toptpl, $visited);
                $number++;
            }
        }
        return $ret;
    }

    /**
     * 生成树型结构 UL
     * @param mixed $myid 起始节点ID
     * @param string $itemtpl 项目模板
     * @param string|array $selectedids 选中的ID
     * @param string|array $disabledids 禁用的ID
     * @param string $wraptag 包装标签
     * @param string $wrapattr 包装属性
     * @param array $visited 已访问节点（内部递归用，环路保护）
     * @return string
     */
    public function getTreeUl($myid, $itemtpl, $selectedids = '', $disabledids = '', $wraptag = 'ul', $wrapattr = '', array $visited = [])
    {
        $str = '';

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return $str;
        }
        $visited[$mykey] = true;

        $childs = $this->getChild($myid);
        if ($childs) {
            foreach ($childs as $value) {
                $id = $value[$this->idname];
                unset($value['child'], $value[$this->childKey]);
                
                $selected = $this->isSelected($id, $selectedids) ? 'selected' : '';
                $disabled = $this->isDisabled($id, $disabledids) ? 'disabled' : '';
                
                $value = array_merge($this->escapeFields($value), [
                    'selected' => $selected, 
                    'disabled' => $disabled
                ]);
                
                $value = $this->addTemplatePrefix($value);
                $nstr = strtr($itemtpl, $value);
                $childdata = $this->getTreeUl($id, $itemtpl, $selectedids, $disabledids, $wraptag, $wrapattr, $visited);
                $childlist = $childdata ? "<{$wraptag} {$wrapattr}>" . $childdata . "</{$wraptag}>" : "";
                $str .= strtr($nstr, ['@childlist' => $childlist]);
            }
        }
        return $str;
    }

    /**
     * 生成菜单样式HTML
     * @param mixed $myid 起始节点ID
     * @param string $itemtpl 菜单项模板
     * @param string|array $selectedids 选中的ID
     * @param string|array $disabledids 禁用的ID
     * @param string $wraptag 顶级包装标签（顶级菜单容器）
     * @param string $wrapattr 顶级包装属性
     * @param string $childwrap 下级包装标签（子菜单容器）
     * @param string $childattr 下级包装属性
     * @param int $maxDepth 最大深度（0 表示不限制）
     * @param int $currentDepth 当前深度
     * @param array $visited 已访问节点（内部递归用，环路保护）
     * @return string
     */
    public function getTreeMenu($myid, $itemtpl, $selectedids = '', $disabledids = '', $wraptag = 'ul', $wrapattr = '', $childwrap = 'ul', $childattr = 'class="submenu"', $maxDepth = 0, $currentDepth = 0, array $visited = [])
    {
        $str = '';

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return $str;
        }
        $visited[$mykey] = true;

        if ($maxDepth > 0 && $currentDepth >= $maxDepth) {
            return $str;
        }

        $childs = $this->getChild($myid);
        
        if ($childs) {
            foreach ($childs as $value) {
                $id = $value[$this->idname];
                unset($value['child'], $value[$this->childKey]);
                
                $selected = $this->isSelected($id, $selectedids) ? 'selected' : '';
                $disabled = $this->isDisabled($id, $disabledids) ? 'disabled' : '';
                $hasChildren = !empty($this->getChild($id));
                
                $value = array_merge($this->escapeFields($value), [
                    'selected' => $selected, 
                    'disabled' => $disabled,
                    'haschildren' => $hasChildren ? '1' : '0',
                    'isactive' => $selected ? 'active' : '',
                    'depth' => $currentDepth
                ]);
                
                $value = $this->addTemplatePrefix($value);
                $nstr = strtr($itemtpl, $value);
                
                $childdata = $this->getTreeMenu($id, $itemtpl, $selectedids, $disabledids, $wraptag, $wrapattr, $childwrap, $childattr, $maxDepth, $currentDepth + 1, $visited);
                $childlist = $childdata ? "<{$childwrap} {$childattr}>" . $childdata . "</{$childwrap}>" : "";
                
                $str .= strtr($nstr, ['@childlist' => $childlist]);
            }
        }
        
        return $str ? "<{$wraptag} {$wrapattr}>" . $str . "</{$wraptag}>" : $str;
    }

    /**
     * 获取树状数组
     * @param mixed $myid 起始节点ID
     * @param string $itemprefix 前缀
     * @param array $visited 已访问节点（内部递归用，环路保护）
     * @return array
     */
    public function getTreeArray($myid, $itemprefix = null, array $visited = [])
    {
        $cacheKey = "tree_array_{$myid}_{$itemprefix}";
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return [];
        }
        $visited[$mykey] = true;

        $childs = $this->getChild($myid);
        $n = 0;
        $data = [];
        $number = 1;
        
        if ($childs) {
            $total = count($childs);
            foreach ($childs as $id => $value) {
                $j = $k = '';
                if ($number == $total) {
                    $j .= $this->icon[2];
                    $k = $itemprefix ? $this->nbsp : '';
                } else {
                    $j .= $this->icon[1];
                    $k = $itemprefix ? $this->icon[0] : '';
                }
                $spacer = $itemprefix ? $itemprefix . $j : '';

                $value['spacer'] = $spacer;
                $data[$n] = $value;
                $data[$n]['childlist'] = $this->getTreeArray($id, $itemprefix . $k . $this->nbsp, $visited);
                $n++;
                $number++;
            }
        }

        $this->setCache($cacheKey, $data);
        return $data;
    }

    /**
     * 将树形数组转换为二维数组
     * @param array $data 树形数组
     * @param string $field 显示字段
     * @return array
     */
    public function getTreeList($data = [], $field = 'name')
    {
        $arr = [];
        foreach ($data as $k => $v) {
            $childlist = $v['childlist'] ?? [];
            unset($v['childlist']);
            $v[$field] = ($v['spacer'] ?? '') . ' ' . ($v[$field] ?? '');
            $v['haschild'] = $childlist ? 1 : 0;
            if (!empty($v[$this->idname])) {
                $arr[] = $v;
            }
            if ($childlist) {
                $arr = array_merge($arr, $this->getTreeList($childlist, $field));
            }
        }
        return $arr;
    }

    /**
     * 生成简单的树形文本
     * @param mixed $myid 起始节点ID
     * @param string $prefix 前缀
     * @param array $visited 已访问节点（内部递归用，环路保护）
     * @return string
     */
    public function getTreeText($myid, $prefix = '', array $visited = [])
    {
        $text = '';

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return $text;
        }
        $visited[$mykey] = true;

        $childs = $this->getChild($myid);
        $count = count($childs);
        $i = 0;
        
        foreach ($childs as $value) {
            $i++;
            $isLast = ($i == $count);
            $currentPrefix = ($isLast ? $this->icon[2] : $this->icon[1]) . '── ';
            $childPrefix = $prefix . ($isLast ? '    ' : $this->icon[0] . '   ');
            
            $text .= $prefix . $currentPrefix . ($value[$this->namefield] ?? '') . "\n";
            $text .= $this->getTreeText($value[$this->idname], $childPrefix, $visited);
        }

        return $text;
    }

    /**
     * 扁平化树形结构（带层级信息）
     * @param mixed $myid 起始节点ID
     * @param int $level 当前层级
     * @param array $visited 已访问节点（内部递归用，环路保护）
     * @return array
     */
    public function getFlatTree($myid, $level = 0, array $visited = [])
    {
        $result = [];

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return $result;
        }
        $visited[$mykey] = true;

        $childs = $this->getChild($myid);
        
        foreach ($childs as $value) {
            $value['level'] = $level;
            $result[] = $value;
            $result = array_merge($result, $this->getFlatTree($value[$this->idname], $level + 1, $visited));
        }
        
        return $result;
    }

    /**
     * 生成面包屑导航
     * @param mixed $myid 当前节点ID
     * @param string $separator 分隔符
     * @param string $tpl 模板
     * @return string
     */
    public function getBreadcrumb($myid, $separator = ' &gt; ', $tpl = '<a href="@url">@name</a>')
    {
        $parents = $this->getParents($myid, true);
        $breadcrumbs = [];
        
        foreach ($parents as $item) {
            $item = $this->addTemplatePrefix($this->escapeFields($item));
            $breadcrumbs[] = strtr($tpl, $item);
        }
        
        return implode($separator, $breadcrumbs);
    }

    /**
     * 获取节点深度
     * @param mixed $myid 节点ID
     * @return int
     */
    public function getNodeDepth($myid)
    {
        $parents = $this->getParents($myid);
        return count($parents);
    }

    /**
     * 获取树的最大深度
     * @param mixed $myid 起始节点ID
     * @return int
     */
    public function getMaxDepth($myid = 0)
    {
        $maxDepth = 0;
        $visited = [];
        $this->calculateDepth($myid, 0, $maxDepth, $visited);
        $this->stats['max_depth'] = $maxDepth;
        return $maxDepth;
    }

    /**
     * 计算深度辅助方法
     * @param mixed $myid 当前节点ID
     * @param int $currentDepth 当前深度
     * @param int $maxDepth 最大深度
     * @param array $visited 已访问节点（环路保护）
     */
    protected function calculateDepth($myid, $currentDepth, &$maxDepth, array $visited = [])
    {
        if ($currentDepth > $maxDepth) {
            $maxDepth = $currentDepth;
        }

        $mykey = $this->normalizeId($myid);
        if (isset($visited[$mykey])) {
            return;
        }
        $visited[$mykey] = true;

        foreach ($this->getChild($myid) as $child) {
            if (!isset($child[$this->idname])) {
                continue;
            }
            $this->calculateDepth($child[$this->idname], $currentDepth + 1, $maxDepth, $visited);
        }
    }

    /**
     * 添加节点
     * @param array $data 节点数据
     * @return $this
     * @throws \Exception
     */
    public function addNode($data)
    {
        if (!isset($data[$this->idname]) || !isset($data[$this->pidname])) {
            $missing = [];
            foreach ([$this->idname, $this->pidname] as $field) {
                if (!isset($data[$field])) {
                    $missing[] = $field;
                }
            }
            throw new \Exception('节点数据缺少必要字段: ' . implode(', ', $missing));
        }
        
        $newkey = $this->normalizeId($data[$this->idname]);
        foreach ($this->arr as $item) {
            if (isset($item[$this->idname]) && $this->normalizeId($item[$this->idname]) === $newkey) {
                throw new \Exception("节点ID已存在: " . $data[$this->idname]);
            }
        }
        
        $this->arr[] = $data;
        $this->clearCache();
        $this->stats['node_count']++;
        
        return $this;
    }

    /**
     * 删除节点及其所有子节点
     * @param mixed $myid 节点ID
     * @return int 实际删除的节点数量
     */
    public function deleteNode($myid)
    {
        $childrenIds = $this->getChildrenIds($myid, true);
        if (!$childrenIds) {
            return 0;
        }

        $idSet = [];
        foreach ($childrenIds as $cid) {
            $idSet[$this->normalizeId($cid)] = true;
        }

        $deleted = 0;
        $this->arr = array_values(array_filter($this->arr, function ($item) use ($idSet, &$deleted) {
            if (isset($item[$this->idname]) && isset($idSet[$this->normalizeId($item[$this->idname])])) {
                $deleted++;
                return false;
            }
            return true;
        }));

        $this->clearCache();
        $this->stats['node_count'] = count($this->arr);

        return $deleted;
    }

    /**
     * 移动节点
     * @param mixed $myid 节点ID
     * @param mixed $newPid 新的父节点ID
     * @return $this
     * @throws \Exception
     */
    public function moveNode($myid, $newPid)
    {
        if ($this->willCauseCycle($myid, $newPid)) {
            throw new \Exception("移动节点会导致循环引用");
        }

        $newkey = $this->normalizeId($newPid);
        if ($newkey !== '0' && $newkey !== '') {
            $nodeIndex = $this->buildIndex();
            if (!isset($nodeIndex[$newkey])) {
                throw new \Exception("目标父节点不存在: " . $newPid);
            }
        }

        $mykey = $this->normalizeId($myid);
        foreach ($this->arr as $index => $item) {
            if (isset($item[$this->idname]) && $this->normalizeId($item[$this->idname]) === $mykey) {
                $this->arr[$index][$this->pidname] = $newPid;
                break;
            }
        }
        
        $this->clearCache();
        return $this;
    }

    /**
     * 检查是否会导致循环引用
     * @param mixed $myid 节点ID
     * @param mixed $newPid 新的父节点ID
     * @return bool
     */
    protected function willCauseCycle($myid, $newPid)
    {
        $mykey = $this->normalizeId($myid);
        $newkey = $this->normalizeId($newPid);

        if ($mykey === $newkey) {
            return true;
        }
        
        $childrenIds = $this->getChildrenIds($myid);
        foreach ($childrenIds as $cid) {
            if ($this->normalizeId($cid) === $newkey) {
                return true;
            }
        }

        return false;
    }

    /**
     * 更新节点
     * @param mixed $myid 节点ID
     * @param array $data 更新数据
     * @return $this
     */
    public function updateNode($myid, $data)
    {
        $mykey = $this->normalizeId($myid);
        foreach ($this->arr as $index => $item) {
            if (isset($item[$this->idname]) && $this->normalizeId($item[$this->idname]) === $mykey) {
                $this->arr[$index] = array_merge($item, $data);
                break;
            }
        }
        
        $this->clearCache();
        return $this;
    }

    /**
     * 搜索节点
     * @param string $keyword 关键词
     * @param string|null $field 搜索字段
     * @return array
     */
    public function searchNodes($keyword, $field = null)
    {
        $field = $field ?: $this->namefield;
        $results = [];
        
        foreach ($this->arr as $item) {
            if (!isset($item[$field]) || !is_scalar($item[$field])) {
                continue;
            }
            if (stripos((string)$item[$field], (string)$keyword) !== false) {
                $results[] = $item;
            }
        }
        
        return $results;
    }

    /**
     * 生成JSON格式的树
     * @param mixed $myid 起始节点ID
     * @param int $options JSON选项
     * @return string
     * @throws \Exception
     */
    public function getTreeJson($myid, $options = JSON_UNESCAPED_UNICODE)
    {
        $treeArray = $this->getTreeArray($myid);
        $json = json_encode($treeArray, $options);

        if ($json === false) {
            throw new \Exception("树结构 JSON 编码失败: " . json_last_error_msg());
        }

        return $json;
    }

    /**
     * 从JSON导入数据
     * @param string $jsonString JSON字符串
     * @return $this
     * @throws \Exception
     */
    public function importFromJson($jsonString)
    {
        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON解析错误: " . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \Exception("JSON数据格式错误: 期望节点数组");
        }
        
        return $this->init($data);
    }

    /**
     * 获取统计信息
     * @return array
     */
    public function getStats()
    {
        return array_merge($this->stats, [
            'cache_size' => count($this->cache),
            'cache_enabled' => $this->options['enable_cache']
        ]);
    }

    /**
     * 检查节点是否被选中
     * @param mixed $id 节点ID
     * @param string|array $selectedids 选中的ID
     * @return bool
     */
    protected function isSelected($id, $selectedids)
    {
        if (empty($selectedids)) return false;

        return in_array($this->normalizeId($id), $this->idSet($selectedids), true);
    }

    /**
     * 检查节点是否被禁用
     * @param mixed $id 节点ID
     * @param string|array $disabledids 禁用的ID
     * @return bool
     */
    protected function isDisabled($id, $disabledids)
    {
        if (empty($disabledids)) return false;

        return in_array($this->normalizeId($id), $this->idSet($disabledids), true);
    }

    /**
     * 解析并缓存 ID 集合（去空格、统一为字符串），避免每个节点重复 explode
     * @param string|array $ids 逗号分隔字符串或数组
     * @return array
     */
    protected function idSet($ids)
    {
        $cacheKey = is_array($ids) ? 'array_' . md5(serialize($ids)) : 'str_' . $ids;

        if (!isset($this->idSetCache[$cacheKey])) {
            $list = is_array($ids) ? $ids : explode(',', (string)$ids);
            $set = [];
            foreach ($list as $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $set[] = $this->normalizeId($value);
                }
            }
            $this->idSetCache[$cacheKey] = $set;
        }

        return $this->idSetCache[$cacheKey];
    }

    /**
     * 转义节点原始字段值，防止输出到 HTML 时产生 XSS
     * 说明：仅处理原始数据字段；spacer / childlist 等模板字段在转义之后再合并
     * @param array $value 值数组
     * @return array
     */
    protected function escapeFields($value)
    {
        foreach ($value as $key => $item) {
            if (is_scalar($item) && !is_bool($item)) {
                $value[$key] = htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $value;
    }

    /**
     * 为模板变量添加@前缀
     * @param array $value 值数组
     * @return array
     */
    protected function addTemplatePrefix($value)
    {
        return array_combine(
            array_map(function ($k) {
                return '@' . $k;
            }, array_keys($value)), 
            $value
        );
    }

    /**
     * 获取原始数据
     * @return array
     */
    public function getRawData()
    {
        return $this->arr;
    }

    /**
     * 验证树结构完整性
     * @return array
     */
    public function validateTree()
    {
        $errors = [];

        $index = $this->buildIndex();

        $rootCount = 0;
        foreach ($this->arr as $item) {
            if (!isset($item[$this->idname])) {
                $errors[] = "存在缺少 {$this->idname} 字段的节点";
                continue;
            }

            $pid = isset($item[$this->pidname]) ? $this->normalizeId($item[$this->pidname]) : '0';
            if ($pid === '0' || $pid === '') {
                $rootCount++;
            }
        }

        if ($rootCount === 0) {
            $errors[] = "未找到根节点";
        }

        foreach ($this->arr as $item) {
            if (!isset($item[$this->idname])) {
                continue;
            }

            $id = $item[$this->idname];
            $pid = isset($item[$this->pidname]) ? $this->normalizeId($item[$this->pidname]) : '0';

            if ($pid === '0' || $pid === '') {
                continue;
            }

            if (!isset($index[$pid])) {
                $errors[] = "孤节点: 节点 {$id} 的父节点 {$pid} 不存在";
                continue;
            }

            $visited = [$this->normalizeId($id) => true];
            $cursor = $pid;
            while ($cursor !== '0' && $cursor !== '' && isset($index[$cursor])) {
                if (isset($visited[$cursor])) {
                    $errors[] = "检测到循环引用: 节点 {$id} -> {$pid}";
                    break;
                }

                $visited[$cursor] = true;
                $cursor = isset($index[$cursor][$this->pidname]) ? $this->normalizeId($index[$cursor][$this->pidname]) : '0';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
