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
    const METHOD_EXEC = 'exec';
    const METHOD_SHELL_EXEC = 'shell_exec';
    const METHOD_SYSTEM = 'system';
    const METHOD_PASSTHRU = 'passthru';
    const METHOD_PROC_OPEN = 'proc_open';
    
    const ERROR_NONE = 0;
    const ERROR_FUNCTION_DISABLED = 1;
    const ERROR_EXECUTION_FAILED = 2;
    const ERROR_TIMEOUT = 3;
    const ERROR_INVALID_COMMAND = 4;
    const ERROR_NO_METHOD_AVAILABLE = 5;
    
    protected const DEFAULT_CONFIG = [
        'default_method' => null,
        'timeout' => 30,
        'async_timeout' => 0,
        'max_output_length' => 1024*1024*1,
        'allow_dangerous' => false,
        'log_execution' => true,
        'dangerous_patterns' => [
            '/rm\s+-rf\s+\//',
            '/dd\s+if=.*of=\/dev/',
            '/(^|\s)mkfs/',
            '/(^|\s)fdisk/',
            '/>\s*\/dev\/sda/',
            '/chmod\s+[0-7]{3,4}\s+\//',
        ],
    ];
    
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
        
        $options = array_merge($this->config, $options);
        
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
        
        $method = $this->determineBestMethod($command, $options);
        
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
        
        $result = $this->executeByMethod($command, $method, $options);
        
        $result['execution_time'] = microtime(true) - $startTime;
        $result['method_used'] = $method;
        
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
        $allowedCommands = array_values(array_filter(
            array_map('strval', (array) ($options['allowed_commands'] ?? []))
        ));
        if ($allowedCommands !== []) {
            $firstWord = strtolower((string) preg_split('/\s+/', trim($command), 2)[0]);
            if (!in_array($firstWord, $allowedCommands, true)) {
                return [
                    'allowed' => false,
                    'reason'  => "命令 [{$firstWord}] 不在白名单内",
                ];
            }
        }

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
        if (!empty($options['default_method']) && $this->isFunctionAvailable($options['default_method'])) {
            return $options['default_method'];
        }
        
        $commandLower = strtolower($command);
        
        if (strpos($commandLower, 'progress') !== false || 
            strpos($commandLower, 'pv ') !== false ||
            strpos($commandLower, '--progress') !== false) {
            if ($this->isFunctionAvailable(self::METHOD_PASSTHRU)) {
                return self::METHOD_PASSTHRU;
            }
        }
        
        if (strpos($commandLower, 'test ') === 0 || 
            strpos($commandLower, 'check') !== false ||
            preg_match('/\|\s*(grep|awk|sed)\s+/', $command)) {
            if ($this->isFunctionAvailable(self::METHOD_EXEC)) {
                return self::METHOD_EXEC;
            }
        }
        
        if (strpos($commandLower, 'tail ') === 0 || 
            strpos($commandLower, 'log') !== false) {
            if ($this->isFunctionAvailable(self::METHOD_PROC_OPEN)) {
                return self::METHOD_PROC_OPEN;
            }
        }
        
        $methodsPriority = [
            self::METHOD_EXEC,
            self::METHOD_PROC_OPEN,
            self::METHOD_SHELL_EXEC,
            self::METHOD_SYSTEM,
            self::METHOD_PASSTHRU,
        ];
        
        foreach ($methodsPriority as $method) {
            if ($this->isFunctionAvailable($method)) {
                return $method;
            }
        }
        
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
        $disabled = array_map('trim', $disabled);
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
        $output = @shell_exec('(' . $command . ') 2>&1; echo "\n__EXITCODE_' . uniqid() . ':$?"');
        $outputString = $output !== null ? (string)$output : '';
        
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
            substr($outputString, -1000),
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
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        
        $procOptions = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];
        $process = @proc_open($command, $descriptorspec, $pipes, null, null, $procOptions);
        
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
        
        $timeout = $options['timeout'];
        $startTime = time();
        
        $stdout = '';
        $stderr = '';
        $isTimeout = false;
        
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        do {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            
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
            
            if ($timeout > 0 && (time() - $startTime) > $timeout) {
                $isTimeout = true;
                proc_terminate($process);
                break;
            }
            
            $status = proc_get_status($process);
        } while ($status['running']);
        
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
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
            
            $message = 'command_exec ' . json_encode(
                $logData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if (class_exists(\think\facade\Log::class)) {
                \think\facade\Log::write($message, 'info');
            } else {
                error_log($message);
            }
        } catch (\Exception $e) {
        }
    }
    
    /**
     * 异步执行命令（不等待结果）
     */
    public function executeAsync(string $command, array $options = []): array
    {
        $options['timeout'] = $options['async_timeout'] ?? 0;
        
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
