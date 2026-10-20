<?php
namespace wise\support\tools;

/**
 * 命令行工具类
 * 智能选择最佳命令执行方式，统一返回格式
 * 支持同步和异步执行，可配置超时时间、最大输出长度等
 * 支持危险命令检测、日志记录等功能
 *
 * 注意：配置在实例化时注入一次，实例状态独立（FPM / Swoole 常驻进程下均安全）；
 *       如需单次覆盖配置，在方法第二参数 $options 中传入即可（优先级高于构造配置）。
 *
 * 使用示例：
 *
 * // 1. 基础使用（默认配置）
 * $cmd = new CommandTool();
 * $result = $cmd->execute('ls -la');
 *
 * // 2. 带配置执行（配置在构造时注入一次）
 * $cmd = new CommandTool([
 *     'timeout' => 10,
 *     'log_execution' => true,
 * ]);
 * $result = $cmd->execute('ping -c 4 baidu.com');
 *
 * // 3. 单次调用覆盖配置
 * $result = $cmd->execute('ls -la', ['timeout' => 60]);
 *
 * // 4. 批量执行
 * $results = $cmd->executeBatch([
 *     'cmd1' => 'pwd',
 *     'cmd2' => 'whoami',
 *     'cmd3' => 'date',
 * ]);
 *
 * // 5. 异步执行
 * $asyncResult = $cmd->executeAsync('sleep 10 && echo "done" > /tmp/test.txt');
 *
 * // 6. 获取系统信息
 * $systemInfo = $cmd->getSystemInfo();
 *
 * // 7. 链式执行多个命令（execute 支持数组）
 * $result = $cmd->execute([
 *     'cd /var/www',
 *     'git pull',
 *     'composer install',
 *     'php think optimize:route',
 * ]);
 *
 * // 8. 错误处理
 * $result = $cmd->execute('invalid_command_xyz');
 * if (!$result['success']) {
 *     switch ($result['error_code']) {
 *         case CommandTool::ERROR_TIMEOUT:
 *             // 超时处理
 *             break;
 *         case CommandTool::ERROR_INVALID_COMMAND:
 *             // 无效命令处理
 *             break;
 *         default:
 *             // 其他错误处理
 *             break;
 *     }
 * }
 *
 * // 9. 测试所有可用方法
 * $availableMethods = $cmd->testMethods();
 * 
 * 返回格式：
 * [
 *     'success' => true/false,
 *     'error_code' => 0-4,
 *     'message' => '执行结果描述',
 *     'command' => '执行的命令',
 *     'output' => '命令输出内容',
 *     'exit_code' => '命令退出码',
 *     'last_line' => '最后一行输出',
 *     'execution_time' => '执行时间(秒)',
 *     'timestamp' => '时间戳',
 *     'method_used' => '使用的执行方法',
 * ]
 */
class CommandTool
{
    // 命令执行方式常量
    const METHOD_EXEC = 'exec';
    const METHOD_SHELL_EXEC = 'shell_exec';
    const METHOD_SYSTEM = 'system';
    const METHOD_PASSTHRU = 'passthru';
    const METHOD_PROC_OPEN = 'proc_open';
    
    // 错误码
    const ERROR_NONE = 0;
    const ERROR_FUNCTION_DISABLED = 1;
    const ERROR_EXECUTION_FAILED = 2;
    const ERROR_TIMEOUT = 3;
    const ERROR_INVALID_COMMAND = 4;
    const ERROR_NO_METHOD_AVAILABLE = 5;  // 新增：无可用执行方法
    
    // 默认配置（不可变；外部如需覆盖，请在调用 execute($command, $options) 时通过 $options 传入）
    protected const DEFAULT_CONFIG = [
        'default_method' => null,           // 默认执行方式 
        'timeout' => 30,                    // 默认超时时间(秒) 
        'async_timeout' => 0,               // 异步执行超时(秒)，0表示不限制
        'max_output_length' => 1024*1024*1, // 最大输出长度(1MB)
        'allow_dangerous' => false,         // 是否允许危险命令
        'log_execution' => true,            // 是否记录执行日志
        'dangerous_patterns' => [           // 危险命令模式
            '/rm\s+-rf\s+\//',
            '/dd\s+if=.*of=\/dev/',
            '/(^|\s)mkfs/',
            '/(^|\s)fdisk/',
            '/>\s*\/dev\/sda/',
            '/chmod\s+[0-7]{3,4}\s+\//',
        ],
    ];
    
    // 函数可用性缓存（环境级只读事实，进程内缓存安全）
    protected static $functionAvailability = [];

    /**
     * @var array 当前配置（DEFAULT_CONFIG + 构造时传入配置的合并结果）
     */
    protected array $config;

    /**
     * @param array $config 配置项，将覆盖 DEFAULT_CONFIG 中的同名项
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);
    }
    
    /**
     * 执行命令（主入口）
     * @param string|array $command 命令字符串或数组
     * @param array $options 执行选项
     * @return array 统一返回格式
     */
    public function execute($command, array $options = []): array
    {
        $startTime = microtime(true);
        
        // 合并配置
        $options = array_merge($this->config, $options);
        
        // 处理命令
        if (is_array($command)) {
            $command = implode(' && ', $command);
        }
        
        $command = trim($command);
        if (empty($command)) {
            return $this->formatResult(
                self::ERROR_INVALID_COMMAND,
                '命令不能为空',
                '',
                '',
                0,
                '',
                microtime(true) - $startTime
            );
        }
        
        // 安全检查
        $securityCheck = $this->checkSecurity($command, $options);
        if (!$securityCheck['allowed']) {
            return $this->formatResult(
                self::ERROR_INVALID_COMMAND,
                '命令安全性检查失败: ' . $securityCheck['reason'],
                $command,
                '',
                0,
                '',
                microtime(true) - $startTime
            );
        }
        
        // 确定执行方法
        $method = $this->determineBestMethod($command, $options);
        
        // 检查是否有可用的执行方法
        if ($method === null) {
            return $this->formatResult(
                self::ERROR_NO_METHOD_AVAILABLE,
                '没有可用的命令执行方法，请检查PHP配置中的disable_functions',
                $command,
                '',
                0,
                '',
                microtime(true) - $startTime
            );
        }
        
        // 执行命令
        $result = $this->executeByMethod($command, $method, $options);
        
        // 添加执行时间
        $result['execution_time'] = microtime(true) - $startTime;
        $result['method_used'] = $method;
        
        // 记录日志
        if ($options['log_execution']) {
            $this->logExecution($command, $result, $options);
        }
        
        return $result;
    }
    
    /**
     * 安全检查
     */
    protected function checkSecurity(string $command, array $options): array
    {
        if (!$options['allow_dangerous']) {
            foreach ($options['dangerous_patterns'] as $pattern) {
                if (preg_match($pattern, $command)) {
                    return [
                        'allowed' => false,
                        'reason' => '命令包含危险操作'
                    ];
                }
            }
        }
        
        return ['allowed' => true, 'reason' => ''];
    }
    
    /**
     * 确定最佳执行方法
     * @return string|null 返回方法名或null（无可用方法时）
     */
    protected function determineBestMethod(string $command, array $options): ?string
    {
        // 如果有指定方法且可用，则使用指定方法
        if (!empty($options['default_method']) && $this->isFunctionAvailable($options['default_method'])) {
            return $options['default_method'];
        }
        
        // 根据命令特点选择方法
        $commandLower = strtolower($command);
        
        // 需要实时输出的命令（如进度条）
        if (strpos($commandLower, 'progress') !== false || 
            strpos($commandLower, 'pv ') !== false ||
            strpos($commandLower, '--progress') !== false) {
            if ($this->isFunctionAvailable(self::METHOD_PASSTHRU)) {
                return self::METHOD_PASSTHRU;
            }
        }
        
        // 需要返回退出状态码的命令
        if (strpos($commandLower, 'test ') === 0 || 
            strpos($commandLower, 'check') !== false ||
            preg_match('/\|\s*(grep|awk|sed)\s+/', $command)) {
            if ($this->isFunctionAvailable(self::METHOD_EXEC)) {
                return self::METHOD_EXEC;
            }
        }
        
        // 大输出量的命令
        if (strpos($commandLower, 'tail ') === 0 || 
            strpos($commandLower, 'log') !== false) {
            if ($this->isFunctionAvailable(self::METHOD_PROC_OPEN)) {
                return self::METHOD_PROC_OPEN;
            }
        }
        
        // 按优先级尝试
        $methodsPriority = [
            self::METHOD_EXEC,      // 最灵活，可以获取退出码和输出
            self::METHOD_PROC_OPEN, // 功能强大，支持超时和流处理
            self::METHOD_SHELL_EXEC, // 简单命令
            self::METHOD_SYSTEM,    // 需要直接输出的命令
            self::METHOD_PASSTHRU,  // 二进制输出
        ];
        
        foreach ($methodsPriority as $method) {
            if ($this->isFunctionAvailable($method)) {
                return $method;
            }
        }
        
        // 所有方法都不可用，返回 null
        return null;
    }
    
    /**
     * 检查函数是否可用
     */
    protected function isFunctionAvailable(string $function): bool
    {
        if (isset(self::$functionAvailability[$function])) {
            return self::$functionAvailability[$function];
        }
        
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled); // 清理空格
        $available = function_exists($function) && !in_array($function, $disabled);
        
        self::$functionAvailability[$function] = $available;
        return $available;
    }
    
    /**
     * 按指定方法执行命令
     */
    protected function executeByMethod(string $command, string $method, array $options): array
    {
        switch ($method) {
            case self::METHOD_EXEC:
                return $this->executeWithExec($command, $options);
                
            case self::METHOD_SHELL_EXEC:
                return $this->executeWithShellExec($command, $options);
                
            case self::METHOD_SYSTEM:
                return $this->executeWithSystem($command, $options);
                
            case self::METHOD_PASSTHRU:
                return $this->executeWithPassthru($command, $options);
                
            case self::METHOD_PROC_OPEN:
                return $this->executeWithProcOpen($command, $options);
                
            default:
                // 检查exec是否可用，不可用则返回错误
                if ($this->isFunctionAvailable(self::METHOD_EXEC)) {
                    return $this->executeWithExec($command, $options);
                } else {
                    return $this->formatResult(
                        self::ERROR_NO_METHOD_AVAILABLE,
                        '指定的执行方法不存在且exec方法不可用',
                        $command,
                        '',
                        0,
                        '',
                        0
                    );
                }
        }
    }
    
    /**
     * 使用exec执行
     */
    protected function executeWithExec(string $command, array $options): array
    {
        $output = [];
        $returnVar = 0;
        $lastLine = '';
        
        @exec($command . ' 2>&1', $output, $returnVar);
        
        if (is_array($output)) {
            $outputString = implode(PHP_EOL, $output);
            $lastLine = end($output) ?: '';
        } else {
            $outputString = (string)$output;
            $lastLine = $outputString;
        }
        
        // 限制输出长度
        $outputString = $this->limitOutputLength($outputString, $options);
        
        return $this->formatResult(
            $returnVar === 0 ? self::ERROR_NONE : self::ERROR_EXECUTION_FAILED,
            $returnVar === 0 ? '执行成功' : "执行失败，退出码: {$returnVar}",
            $command,
            $outputString,
            $returnVar,
            $lastLine,
            0
        );
    }
    
    /**
     * 使用shell_exec执行
     */
    protected function executeWithShellExec(string $command, array $options): array
    {
        // 使用 shell_exec 一次性获取输出和退出码，避免命令执行两次
        $output = @shell_exec('(' . $command . ') 2>&1; echo "\n__EXITCODE_' . uniqid() . ':$?"');
        $outputString = $output !== null ? (string)$output : '';
        
        // 从输出末尾提取退出码（避免再次执行命令）
        $returnVar = 0;
        if (preg_match('/\n__EXITCODE_[^:]+:(\d+)$/', $outputString, $matches)) {
            $returnVar = (int)$matches[1];
            $outputString = preg_replace('/\n__EXITCODE_[^:]+:\d+$/', '', $outputString);
        }
        
        $outputString = $this->limitOutputLength($outputString, $options);
        
        return $this->formatResult(
            $returnVar === 0 ? self::ERROR_NONE : self::ERROR_EXECUTION_FAILED,
            $returnVar === 0 ? '执行成功' : "执行失败",
            $command,
            $outputString,
            $returnVar,
            substr($outputString, -1000), // 取最后1000字符作为最后一行
            0
        );
    }
    
    /**
     * 使用system执行
     */
    protected function executeWithSystem(string $command, array $options): array
    {
        ob_start();
        $returnVar = @system($command . ' 2>&1', $returnCode);
        $outputString = ob_get_clean();
        
        $outputString = $this->limitOutputLength($outputString, $options);
        
        return $this->formatResult(
            $returnCode === 0 ? self::ERROR_NONE : self::ERROR_EXECUTION_FAILED,
            $returnCode === 0 ? '执行成功' : "执行失败，退出码: {$returnCode}",
            $command,
            $outputString,
            $returnCode,
            $returnVar,
            0
        );
    }
    
    /**
     * 使用passthru执行
     */
    protected function executeWithPassthru(string $command, array $options): array
    {
        ob_start();
        @passthru($command . ' 2>&1', $returnVar);
        $outputString = ob_get_clean();
        
        $outputString = $this->limitOutputLength($outputString, $options);
        
        return $this->formatResult(
            $returnVar === 0 ? self::ERROR_NONE : self::ERROR_EXECUTION_FAILED,
            $returnVar === 0 ? '执行成功' : "执行失败，退出码: {$returnVar}",
            $command,
            $outputString,
            $returnVar,
            substr($outputString, -1000),
            0
        );
    }
    
    /**
     * 使用proc_open执行（支持超时）
     */
    protected function executeWithProcOpen(string $command, array $options): array
    {
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];
        
        $process = @proc_open($command, $descriptorspec, $pipes, null, null);
        
        if (!is_resource($process)) {
            return $this->formatResult(
                self::ERROR_EXECUTION_FAILED,
                '无法创建进程',
                $command,
                '',
                1,
                '',
                0
            );
        }
        
        // 设置超时
        $timeout = $options['timeout'];
        $startTime = time();
        
        $stdout = '';
        $stderr = '';
        $isTimeout = false;
        
        // 设置管道为非阻塞
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        do {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            
            // 等待数据可用
            $streamsChanged = stream_select($read, $write, $except, 1);
            
            if ($streamsChanged > 0) {
                foreach ($read as $stream) {
                    if ($stream === $pipes[1]) {
                        $stdout .= fread($stream, 8192);
                    }
                    if ($stream === $pipes[2]) {
                        $stderr .= fread($stream, 8192);
                    }
                }
            }
            
            // 检查超时
            if ($timeout > 0 && (time() - $startTime) > $timeout) {
                $isTimeout = true;
                // 终止进程
                proc_terminate($process);
                break;
            }
            
            // 检查进程是否结束
            $status = proc_get_status($process);
        } while ($status['running']);
        
        // 关闭所有管道
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // 获取退出码
        $returnVar = proc_close($process);
        
        $outputString = $stdout . ($stderr ? PHP_EOL . "STDERR: " . $stderr : '');
        $outputString = $this->limitOutputLength($outputString, $options);
        
        $errorCode = $isTimeout ? self::ERROR_TIMEOUT : 
                    ($returnVar === 0 ? self::ERROR_NONE : self::ERROR_EXECUTION_FAILED);
        $errorMsg = $isTimeout ? "命令执行超时 ({$timeout}秒)" : 
                    ($returnVar === 0 ? '执行成功' : "执行失败，退出码: {$returnVar}");
        
        return $this->formatResult(
            $errorCode,
            $errorMsg,
            $command,
            $outputString,
            $returnVar,
            substr($outputString, -1000),
            0
        );
    }
    
    /**
     * 限制输出长度
     */
    protected function limitOutputLength(string $output, array $options): string
    {
        $maxLength = $options['max_output_length'];
        if (strlen($output) > $maxLength) {
            $output = substr($output, 0, $maxLength) . 
                     PHP_EOL . "... [输出被截断，超过 {$maxLength} 字节]";
        }
        return $output;
    }
    
    /**
     * 统一返回格式
     */
    protected function formatResult(
        int $errorCode,
        string $message,
        string $command,
        string $output,
        int $exitCode,
        string $lastLine,
        float $executionTime
    ): array {
        return [
            'success' => $errorCode === self::ERROR_NONE,
            'error_code' => $errorCode,
            'message' => $message,
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'last_line' => $lastLine,
            'execution_time' => $executionTime,
            'timestamp' => time(),
        ];
    }
    
    /**
     * 记录执行日志
     */
    protected function logExecution(string $command, array $result, array $options): void
    {
        try {
            $logData = [
                'command' => $command,
                'method' => $result['method_used'] ?? 'unknown',
                'success' => $result['success'],
                'exit_code' => $result['exit_code'],
                'execution_time' => round($result['execution_time'], 4),
                'output_length' => strlen($result['output']),
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            
            // 这里可以记录到文件或数据库
            // 例如：Log::write('command_exec', json_encode($logData, JSON_UNESCAPED_UNICODE));
            
        } catch (\Exception $e) {
            // 日志记录失败，不中断主流程
        }
    }
    
    /**
     * 异步执行命令（不等待结果）
     */
    public function executeAsync(string $command, array $options = []): array
    {
        $options['timeout'] = $options['async_timeout'] ?? 0;
        
        // 后台执行
        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start /B ' . $command;
        } else {
            $command = $command . ' > /dev/null 2>&1 &';
        }
        
        return $this->execute($command, $options);
    }
    
    /**
     * 批量执行命令
     */
    public function executeBatch(array $commands, array $options = []): array
    {
        $results = [];
        foreach ($commands as $key => $command) {
            $results[$key] = $this->execute($command, $options);
            
            // 如果配置了停止条件，遇到失败则停止
            if (!empty($options['stop_on_failure']) && !$results[$key]['success']) {
                break;
            }
        }
        
        return $results;
    }
    
    /**
     * 测试所有可用方法
     */
    public function testMethods(): array
    {
        $testCommand = 'echo "test"';
        $methods = [
            self::METHOD_EXEC,
            self::METHOD_SHELL_EXEC,
            self::METHOD_SYSTEM,
            self::METHOD_PASSTHRU,
            self::METHOD_PROC_OPEN,
        ];
        
        $results = [];
        foreach ($methods as $method) {
            if ($this->isFunctionAvailable($method)) {
                $results[$method] = $this->executeByMethod($testCommand, $method, $this->config);
                $results[$method]['available'] = true;
            } else {
                $results[$method] = ['available' => false, 'reason' => '函数被禁用或不存在'];
            }
        }
        
        return $results;
    }
    
    /**
     * 获取系统信息
     */
    public function getSystemInfo(): array
    {
        $commands = [
            'php_version' => 'php -v | head -1',
            'os' => PHP_OS_FAMILY === 'Windows' ? 'ver' : 'uname -a',
            'disk' => PHP_OS_FAMILY === 'Windows' ? 'wmic logicaldisk get size,freespace,caption' : 'df -h',
            'memory' => PHP_OS_FAMILY === 'Windows' ? 'wmic OS get FreePhysicalMemory,TotalVisibleMemorySize' : 'free -m',
        ];
        
        $info = [];
        foreach ($commands as $key => $command) {
            $result = $this->execute($command);
            $info[$key] = $result['success'] ? $result['output'] : '获取失败';
        }
        
        return $info;
    }
}
