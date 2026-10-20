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
 * 
 * @author AI Assistant
 * @version 2.0
 */

class Tree
{
    // 配置选项：默认值即初始 options，运行时通过构造参数 / updateOptions() 合并覆盖（单一真源，不再从配置文件读取）
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

    // 统计信息
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
        $this->arr = $arr;
        $this->stats['node_count'] = count($arr);
        
        if (!is_null($pidname)) $this->pidname = $pidname;
        if (!is_null($idname)) $this->idname = $idname;
        if (!is_null($nbsp)) $this->nbsp = $nbsp;

        $this->clearCache();
        
        // 自动排序
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
        return $this;
    }

    /**
     * 获取缓存数据
     * @param string $key 缓存键
     * @return mixed|null
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
        if ($cached = $this->getCache($cacheKey)) {
            return $cached;
        }

        $newarr = [];
        foreach ($this->arr as $value) {
            if (!isset($value[$this->idname])) continue;
            
            if ($value[$this->pidname] == $myid) {
                $newarr[$value[$this->idname]] = $value;
            }
        }

        $this->setCache($cacheKey, $newarr);
        return $newarr;
    }

    /**
     * 获取所有子孙节点（递归）
     * @param mixed $myid 当前节点ID
     * @param bool $withself 是否包含自身
     * @return array
     */
    public function getChildren($myid, $withself = false)
    {
        $cacheKey = "children_{$myid}_" . ($withself ? '1' : '0');
        if ($cached = $this->getCache($cacheKey)) {
            return $cached;
        }

        $newarr = [];
        foreach ($this->arr as $value) {
            if (!isset($value[$this->idname])) continue;
            
            if ((string)$value[$this->pidname] == (string)$myid) {
                $newarr[] = $value;
                $newarr = array_merge($newarr, $this->getChildren($value[$this->idname]));
            } elseif ($withself && (string)$value[$this->idname] == (string)$myid) {
                $newarr[] = $value;
            }
        }

        $this->setCache($cacheKey, $newarr);
        return $newarr;
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
        if ($cached = $this->getCache($cacheKey)) {
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
        foreach ($this->arr as $value) {
            if (!isset($value[$this->idname])) continue;
            if ($value[$this->idname] == $myid) {
                $pid = $value[$this->pidname];
                foreach ($this->arr as $parent) {
                    if ($parent[$this->idname] == $pid) {
                        return $parent;
                    }
                }
                break;
            }
        }
        return null;
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
        if ($cached = $this->getCache($cacheKey)) {
            return $cached;
        }

        $newarr = [];
        $current = null;
        
        // 找到当前节点
        foreach ($this->arr as $value) {
            if ($value[$this->idname] == $myid) {
                $current = $value;
                break;
            }
        }
        
        if (!$current) {
            return $newarr;
        }
        
        if ($withself) {
            $newarr[] = $current;
        }
        
        $pid = $current[$this->pidname];
        while ($pid) {
            foreach ($this->arr as $value) {
                if ($value[$this->idname] == $pid) {
                    array_unshift($newarr, $value);
                    $pid = $value[$this->pidname];
                    break;
                }
            }
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
     * @return string
     */
    public function getTree($myid, $itemtpl = "<option value=@id @selected @disabled>@spacer@name</option>", $selectedids = '', $disabledids = '', $itemprefix = '', $toptpl = '')
    {
        $ret = '';
        $number = 1;
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
                
                $value = array_merge($value, [
                    'selected' => $selected, 
                    'disabled' => $disabled, 
                    'spacer' => $spacer
                ]);
                
                $value = $this->addTemplatePrefix($value);
                $nstr = strtr((($value["@{$this->pidname}"] == 0 || $this->getChild($id)) && $toptpl ? $toptpl : $itemtpl), $value);
                $ret .= $nstr;
                $ret .= $this->getTree($id, $itemtpl, $selectedids, $disabledids, $itemprefix . $k . $this->nbsp, $toptpl);
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
     * @return string
     */
    public function getTreeUl($myid, $itemtpl, $selectedids = '', $disabledids = '', $wraptag = 'ul', $wrapattr = '')
    {
        $str = '';
        $childs = $this->getChild($myid);
        if ($childs) {
            foreach ($childs as $value) {
                $id = $value[$this->idname];
                unset($value['child']);
                
                $selected = $this->isSelected($id, $selectedids) ? 'selected' : '';
                $disabled = $this->isDisabled($id, $disabledids) ? 'disabled' : '';
                
                $value = array_merge($value, [
                    'selected' => $selected, 
                    'disabled' => $disabled
                ]);
                
                $value = $this->addTemplatePrefix($value);
                $nstr = strtr($itemtpl, $value);
                $childdata = $this->getTreeUl($id, $itemtpl, $selectedids, $disabledids, $wraptag, $wrapattr);
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
     * @param string $wraptag 包装标签
     * @param string $wrapattr 包装属性
     * @param string $childwrap 子菜单包装标签
     * @param string $childattr 子菜单属性
     * @param int $maxDepth 最大深度
     * @param int $currentDepth 当前深度
     * @return string
     */
    public function getTreeMenu($myid, $itemtpl, $selectedids = '', $disabledids = '', $wraptag = 'ul', $wrapattr = '', $childwrap = 'ul', $childattr = 'class="submenu"', $maxDepth = 0, $currentDepth = 0)
    {
        $str = '';
        $childs = $this->getChild($myid);
        
        // 检查深度限制
        if ($maxDepth > 0 && $currentDepth >= $maxDepth) {
            return $str;
        }
        
        if ($childs) {
            foreach ($childs as $value) {
                $id = $value[$this->idname];
                unset($value['child']);
                
                $selected = $this->isSelected($id, $selectedids) ? 'selected' : '';
                $disabled = $this->isDisabled($id, $disabledids) ? 'disabled' : '';
                $hasChildren = !empty($this->getChild($id));
                $isActive = $this->isSelected($id, $selectedids);
                
                $value = array_merge($value, [
                    'selected' => $selected, 
                    'disabled' => $disabled,
                    'haschildren' => $hasChildren ? '1' : '0',
                    'isactive' => $isActive ? 'active' : '',
                    'depth' => $currentDepth
                ]);
                
                $value = $this->addTemplatePrefix($value);
                $nstr = strtr($itemtpl, $value);
                
                // 递归生成子菜单
                $childdata = $this->getTreeMenu($id, $itemtpl, $selectedids, $disabledids, $wraptag, $wrapattr, $childwrap, $childattr, $maxDepth, $currentDepth + 1);
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
     * @return array
     */
    public function getTreeArray($myid, $itemprefix = null)
    {
        $cacheKey = "tree_array_{$myid}_{$itemprefix}";
        if ($cached = $this->getCache($cacheKey)) {
            return $cached;
        }

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
                    // $k = $itemprefix == null ? $this->nbsp : '';
                    $k = $itemprefix == null ?  '' : '';
                } else {
                    $j .= $this->icon[1];
                    // $k = $itemprefix == null ? $this->icon[0] : '';
                    $k = $itemprefix == null ? $this->nbsp : $this->icon[0];
                }
                $spacer = $itemprefix ? $itemprefix . $j : '';

                $value['spacer'] = $spacer;
                $data[$n] = $value;
                $data[$n]['childlist'] = $this->getTreeArray($id, $itemprefix . $k . $this->nbsp);
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
            $v[$field] = $v['spacer'] . ' ' . $v[$field];
            $v['haschild'] = $childlist ? 1 : 0;
            if ($v[$this->idname]) {
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
     * @return string
     */
    public function getTreeText($myid, $prefix = '')
    {
        $text = '';
        $childs = $this->getChild($myid);
        $count = count($childs);
        $i = 0;
        
        foreach ($childs as $value) {
            $i++;
            $isLast = ($i == $count);
            $currentPrefix = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');
            
            $text .= $prefix . $currentPrefix . $value[$this->namefield] . "\n";
            $text .= $this->getTreeText($value[$this->idname], $childPrefix);
        }

        return $text;
    }

    /**
     * 扁平化树形结构（带层级信息）
     * @param mixed $myid 起始节点ID
     * @param int $level 当前层级
     * @return array
     */
    public function getFlatTree($myid, $level = 0)
    {
        $result = [];
        $childs = $this->getChild($myid);
        
        foreach ($childs as $value) {
            $value['level'] = $level;
            $result[] = $value;
            $result = array_merge($result, $this->getFlatTree($value[$this->idname], $level + 1));
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
            $item = $this->addTemplatePrefix($item);
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
        $this->calculateDepth($myid, 0, $maxDepth);
        $this->stats['max_depth'] = $maxDepth;
        return $maxDepth;
    }

    /**
     * 计算深度辅助方法
     * @param mixed $myid 当前节点ID
     * @param int $currentDepth 当前深度
     * @param int $maxDepth 最大深度
     */
    protected function calculateDepth($myid, $currentDepth, &$maxDepth)
    {
        if ($currentDepth > $maxDepth) {
            $maxDepth = $currentDepth;
        }
        
        $childs = $this->getChild($myid);
        foreach ($childs as $child) {
            $this->calculateDepth($child[$this->idname], $currentDepth + 1, $maxDepth);
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
        // 验证数据完整性
        if (!isset($data[$this->idname]) || !isset($data[$this->pidname])) {
            throw new \Exception("节点数据必须包含 id 和 pid 字段");
        }
        
        // 检查ID是否已存在
        foreach ($this->arr as $item) {
            if ($item[$this->idname] == $data[$this->idname]) {
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
     * @return int 删除的节点数量
     */
    public function deleteNode($myid)
    {
        $childrenIds = $this->getChildrenIds($myid, true);
        
        $this->arr = array_filter($this->arr, function($item) use ($childrenIds) {
            return !in_array($item[$this->idname], $childrenIds);
        });
        
        $this->clearCache();
        $this->stats['node_count'] = count($this->arr);
        
        return count($childrenIds); // 返回删除的节点数量
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
        // 检查循环引用
        if ($this->willCauseCycle($myid, $newPid)) {
            throw new \Exception("移动节点会导致循环引用");
        }
        
        foreach ($this->arr as &$item) {
            if ($item[$this->idname] == $myid) {
                $item[$this->pidname] = $newPid;
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
        if ($myid == $newPid) return true;
        
        $childrenIds = $this->getChildrenIds($myid);
        return in_array($newPid, $childrenIds);
    }

    /**
     * 更新节点
     * @param mixed $myid 节点ID
     * @param array $data 更新数据
     * @return $this
     */
    public function updateNode($myid, $data)
    {
        foreach ($this->arr as &$item) {
            if ($item[$this->idname] == $myid) {
                $item = array_merge($item, $data);
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
            if (isset($item[$field]) && stripos($item[$field], $keyword) !== false) {
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
     */
    public function getTreeJson($myid, $options = JSON_UNESCAPED_UNICODE)
    {
        $treeArray = $this->getTreeArray($myid);
        return json_encode($treeArray, $options);
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
        if (!$selectedids) return false;
        
        $selectedArray = is_array($selectedids) ? $selectedids : explode(',', $selectedids);
        return in_array($id, $selectedArray);
    }

    /**
     * 检查节点是否被禁用
     * @param mixed $id 节点ID
     * @param string|array $disabledids 禁用的ID
     * @return bool
     */
    protected function isDisabled($id, $disabledids)
    {
        if (!$disabledids) return false;
        
        $disabledArray = is_array($disabledids) ? $disabledids : explode(',', $disabledids);
        return in_array($id, $disabledArray);
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
        
        // 检查根节点
        $rootNodes = array_filter($this->arr, function($item) {
            return empty($item[$this->pidname]) || $item[$this->pidname] == 0;
        });
        
        if (count($rootNodes) === 0) {
            $errors[] = "未找到根节点";
        }
        
        // 检查循环引用
        foreach ($this->arr as $item) {
            if ($this->willCauseCycle($item[$this->idname], $item[$this->pidname])) {
                $errors[] = "检测到循环引用: 节点 {$item[$this->idname]} -> {$item[$this->pidname]}";
            }
        }
        
        // 检查孤节点（父节点不存在）
        foreach ($this->arr as $item) {
            $pid = $item[$this->pidname];
            if ($pid && $pid != 0) {
                $parentExists = false;
                foreach ($this->arr as $parent) {
                    if ($parent[$this->idname] == $pid) {
                        $parentExists = true;
                        break;
                    }
                }
                if (!$parentExists) {
                    $errors[] = "孤节点: 节点 {$item[$this->idname]} 的父节点 {$pid} 不存在";
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}