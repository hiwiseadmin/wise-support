<?php
namespace wise\support\tools;

use wise\support\context\ContextualLogger;
use think\facade\Cache;

/**
 * Http客户端类
 * 支持多种请求方式、并发请求、中间件等功能
 */
class HttpClient
{
    /**
     * @var array 默认配置
     */
    private $defaultConfig = [
        'base_uri'        => '',    // 基础URI
        'timeout'         => 60,    // 请求超时时间（秒）
        'connect_timeout' => 5,     // 连接超时时间（秒）
        'buffer_size'     => 1024*256,  // 缓冲区大小
        'verify'          => false, // SSL验证
        'http_errors'     => true,  // 是否抛出HTTP错误
        'allow_redirects' => [
            'max'       => 5,       // 最大重定向次数
            'strict'    => false,   // 是否严格遵守重定向规则
            'referer'   => true,    // 是否自动添加Referer头
            'protocols' => ['http', 'https'], // 允许的协议
        ],
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.98 Safari/537.36',
            'Accept'     => 'application/json, text/plain, */*',
        ],
        'proxy' => null,            // 代理设置
        'cookies' => false,         // Cookie处理
        'debug' => false,           // 调试模式
        'max_retries' => 3,         // 最大重试次数
        'retry_delay' => 100,       // 重试延迟时间（毫秒）
        'resume' => false,          // 启用断点续传
        'stream' => false,          // 流式传输
        'savefile' => null,         // 保存文件路径，stream模式时 savefile和write_callback必须指定一个
        'write_callback' => null,   // 流式数据回调 function($chunk) : int ， stream模式时 savefile和write_callback必须指定一个
        'progress' => null,         // 进度回调函数
    ];

    /**
     * @var array 当前配置
     */
    private $config;

    /**
     * @var array 请求中间件
     */
    private $middleware = [];

    /**
     * @var \CurlHandle|resource|null cURL句柄（用于持久连接）
     */
    private $curlHandle = null;

    /**
     * @var array Cookie存储
     */
    private $cookies = [];

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaultConfig, $config);
        
        // 初始化Cookie
        if ($this->config['cookies']) {
            $this->loadCookies();
        }
        
        // 注册默认中间件
        $this->registerDefaultMiddleware();
    }
    
    public static function instance(array $config = [])
    {
        // 每次返回新实例（无静态单例），避免常驻进程下跨协程/跨请求复用导致配置/连接串号
        return new self($config);
    }
    
    /**
     * 发送HTTP请求
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     * @throws HttpException
     */
    public function request(string $method, string $uri, array $options = []): Response
    {
        // 合并配置
        $config = $this->mergeConfig($options);
        
        // 处理URI
        $url = $this->buildUrl($uri, $config);
        
        // 目录预处理
        $config = $this->prepareSavefile($config, $url);

        // 准备请求数据
        $requestData = $this->prepareRequest($method, $config);
        
        // 执行中间件
        $handler = $this->createHandlerStack();
        $promise = $handler($method, $url, $requestData);
        
        // 执行请求
        return $this->executeRequest($promise, $config);
    }

    /**
     * 发送请求（兼容Guzzle风格）
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function send(string $method, string $uri, array $options = []): Response
    {
        return $this->request($method, $uri, $options);
    }

    /**
     * 发送GET请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function get(string $uri, array $options = []): Response
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * 发送POST请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function post(string $uri, array $options = []): Response
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * 发送PUT请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function put(string $uri, array $options = []): Response
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * 发送DELETE请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function delete(string $uri, array $options = []): Response
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * 发送PATCH请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function patch(string $uri, array $options = []): Response
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * 发送HEAD请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function head(string $uri, array $options = []): Response
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * 发送OPTIONS请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function options(string $uri, array $options = []): Response
    {
        return $this->request('OPTIONS', $uri, $options);
    }

    /**
     * 发送异步请求
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function requestAsync(string $method, string $uri, array $options = []): Promise
    {
        return new Promise(function () use ($method, $uri, $options) {
            return $this->request($method, $uri, $options);
        });
    }

    /**
     * 发送异步请求（另一种语法风格）
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function sendAsync(string $method, string $uri, array $options = []): Promise
    {
        return $this->requestAsync($method, $uri, $options);
    }

    /**
     * 发送GET异步请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function getAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('GET', $uri, $options);
    }

    /**
     * 发送POST异步请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function postAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('POST', $uri, $options);
    }

    /**
     * 发送PUT异步请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function putAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('PUT', $uri, $options);
    }

    /**
     * 发送DELETE异步请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function deleteAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('DELETE', $uri, $options);
    }

    /**
     * 发送PATCH异步请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function patchAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('PATCH', $uri, $options);
    }

    /**
     * 发送HEAD请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Response
     */
    public function headAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('HEAD', $uri, $options);
    }

    /**
     * 发送OPTIONS异步请求
     *
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return Promise
     */
    public function optionsAsync(string $uri, array $options = []): Promise
    {
        return $this->requestAsync('OPTIONS', $uri, $options);
    }

    /**
     * 并发请求（异步）
     *
     * @param array $requests 请求数组
     * @param array $options 全局选项
     * @param int $concurrency 并发数
     * @return array
     */
    public function concurrent(array $requests, array $options = [], int $concurrency = 5): array
    {
        $results = [];
        
        // 分批处理请求
        $chunks = array_chunk($requests, $concurrency, true);
        
        foreach ($chunks as $chunk) {
            $batchPromises = [];
            
            foreach ($chunk as $key => $request) {
                if (!isset($request['method'], $request['uri'])) {
                    $results[$key] = [
                        'state' => 'rejected',
                        'reason' => new \Exception('请求缺少method或uri参数')
                    ];
                    continue;
                }
                
                $batchPromises[$key] = $this->requestAsync(
                    $request['method'],
                    $request['uri'],
                    array_merge($options, $request['options'] ?? [])
                );
            }
            
            // 等待当前批次完成
            $batchResults = Promise::settle($batchPromises)->wait();
            $results = array_merge($results, $batchResults);
        }
        
        return $results;
    }

    /**
     * 发送JSON请求
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $data JSON数据
     * @param array $options 请求选项
     * @return Response
     */
    public function json(string $method, string $uri, array $data, array $options = []): Response
    {
        $options['json'] = $data;
        return $this->request($method, $uri, $options);
    }

    /**
     * 发送请求并返回数组（简化调用）
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $options 请求选项
     * @return array|null
     */
    public function sendJson(string $method, string $uri, array $options = []): ?array
    {
        $response = $this->request($method, $uri, $options);
        return $response->getJson();
    }
    
    /**
     * 发送表单请求
     *
     * @param string $method 请求方法
     * @param string $uri 请求URI
     * @param array $data 表单数据
     * @param array $options 请求选项
     * @return Response
     */
    public function form(string $method, string $uri, array $data, array $options = []): Response
    {
        $options['form_params'] = $data;
        return $this->request($method, $uri, $options);
    }

    /**
     * 上传文件
     *
     * @param string $uri 请求URI
     * @param array $files 文件数组
     * @param array $data 其他数据
     * @param array $options 请求选项
     * @return Response
     */
    public function upload(string $uri, array $files, array $data = [], array $options = []): Response
    {
        $options['multipart'] = [];
        
        foreach ($data as $name => $value) {
            $options['multipart'][] = [
                'name' => $name,
                'contents' => $value,
            ];
        }
        
        foreach ($files as $name => $file) {
            if (is_array($file)) {
                foreach ($file as $index => $fileInfo) {
                    if (!isset($fileInfo['path']) || !file_exists($fileInfo['path'])) {
                        throw new HttpException("文件不存在: " . ($fileInfo['path'] ?? '未知路径'));
                    }

                    $options['multipart'][] = [
                        'name' => $name . '[' . $index . ']',
                        'contents' => fopen($fileInfo['path'], 'r'),
                        'filename' => $fileInfo['name'],
                    ];
                }
            } else {
                if (!file_exists($file)) {
                    throw new HttpException("文件不存在: " . $file);
                }

                $options['multipart'][] = [
                    'name' => $name,
                    'contents' => fopen($file, 'r'),
                    'filename' => basename($file),
                ];
            }
        }
        
        return $this->request('POST', $uri, $options);
    }

    /**
     * 设置基础URI
     *
     * @param string $baseUri 基础URI
     * @return self
     */
    public function setBaseUri(string $baseUri): self
    {
        $this->config['base_uri'] = rtrim($baseUri, '/');
        return $this;
    }

    /**
     * 设置默认Header
     *
     * @param string $name Header名称
     * @param string $value Header值
     * @return self
     */
    public function setDefaultHeader(string $name, string $value): self
    {
        $this->config['headers'][$name] = $value;
        return $this;
    }

    /**
     * 设置超时时间
     *
     * @param int $timeout 超时时间（秒）
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->config['timeout'] = $timeout;
        return $this;
    }

    /**
     * 设置代理
     *
     * @param string $proxy 代理地址
     * @return self
     */
    public function setProxy(string $proxy): self
    {
        $this->config['proxy'] = $proxy;
        return $this;
    }

    /**
     * 添加中间件
     *
     * @param callable $middleware 中间件函数
     * @param string $name 中间件名称
     * @return self
     */
    public function addMiddleware(callable $middleware, string $name = ''): self
    {
        if ($name) {
            $this->middleware[$name] = $middleware;
        } else {
            $this->middleware[] = $middleware;
        }
        return $this;
    }

    /**
     * 移除中间件
     *
     * @param string $name 中间件名称
     * @return self
     */
    public function removeMiddleware(string $name): self
    {
        unset($this->middleware[$name]);
        return $this;
    }

    /**
     * 获取Cookie
     *
     * @param string $name Cookie名称
     * @return string|null
     */
    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * 设置Cookie
     *
     * @param string $name Cookie名称
     * @param string $value Cookie值
     * @return self
     */
    public function setCookie(string $name, string $value): self
    {
        $this->cookies[$name] = $value;
        return $this;
    }

    /**
     * 清除Cookie
     *
     * @return self
     */
    public function clearCookies(): self
    {
        $this->cookies = [];
        return $this;
    }

    /**
     * 获取配置
     * @param string $key 配置键名
     * @return mixed
     */
    public function getConfig(?string $key = null): mixed
    {
        return $key ? ($this->config[$key] ?? null) : $this->config;
    }

    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->curlHandle) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();
        
        // 保存Cookie
        if ($this->config['cookies']) {
            $this->saveCookies();
        }
    }

    /**
     * 合并配置
     *
     * @param array $options 请求选项
     * @return array
     */
    private function mergeConfig(array $options): array
    {
        $config = $this->config;
        
        if (isset($options['headers'])) {
            $config['headers'] = array_merge($config['headers'], $options['headers']);
            unset($options['headers']);
        }
        
        return array_merge($config, $options);
    }

    /**
     * 构建URL
     *
     * @param string $uri 请求URI
     * @param array $config 配置
     * @return string
     */
    private function buildUrl(string $uri, array $config): string
    {
        // 处理基础URI
        if (!empty($config['base_uri']) && strpos($uri, '://') === false) {
            $base = rtrim($config['base_uri'], '/');
            $uri = '/' . ltrim($uri, '/');
            $url = $base . $uri;
        } else {
            $url = $uri;
        }
        
        // 处理查询参数
        if (!empty($config['query']) && is_array($config['query'])) {
            $url = $this->addQueryParamsToUrl($url, $config['query']);
        }
        
        return $url;
    }

    /**
     * 为URL添加查询参数（合并处理）
     *
     * @param string $url 原始URL
     * @param array $query 新查询参数
     * @return string
     */
    private function addQueryParamsToUrl(string $url, array $query): string
    {
        // 解析URL
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }
        
        // 获取现有查询参数
        $existingParams = [];
        isset($parsed['query']) && parse_str($parsed['query'], $existingParams);
        
        // 合并参数
        $mergedParams = array_merge($existingParams, $query);
        
        // 重建URL
        $result = '';
        
        // 基础部分
        isset($parsed['scheme']) && $result .= $parsed['scheme'] . '://';
        isset($parsed['host'])   && $result .= $parsed['host'];
        isset($parsed['port'])   && $result .= ':' . $parsed['port'];
        
        // 路径
        $result .= $parsed['path'] ?? '/';
        
        // 查询参数
        if (!empty($mergedParams)) {
            $result .= '?' . http_build_query($mergedParams, '', '&', PHP_QUERY_RFC3986);
        }
        
        // 片段
        isset($parsed['fragment']) && $result .= '#' . $parsed['fragment'];
        
        return $result;
    }

    /**
     * 预处理保存文件路径
     *
     * @param array $config 配置
     * @param string $url 请求URL
     * @return array
     */
    private function prepareSavefile(array $config, string $url): array
    {
        if ($config['stream'] && !empty($config['savefile']) && is_string($config['savefile'])) {
            $savefile = $config['savefile'];
            // 以分隔符结尾 或 已存在目录 → 视为目录模式
            if (substr($savefile, -1) === DIRECTORY_SEPARATOR || is_dir($savefile)) {
                $originalDir = rtrim($savefile, DIRECTORY_SEPARATOR);

                if (!is_dir($originalDir)) {
                    mkdir($originalDir, 0755, true);
                }

                $tempFilename = uniqid('httpclient_', true) . '.tmp';
                $tempPath = $originalDir . DIRECTORY_SEPARATOR . $tempFilename;
                $config['savefile'] = $tempPath;
                $config['_dir_mode'] = true;
                $config['_original_dir'] = $originalDir;
                $config['_original_url'] = $url;      // 用于降级提取文件名
                // 目录模式下不支持断点续传
                if (!empty($config['resume'])) {
                    $config['resume'] = false;
                }
            }
        }

        return $config;
    }

    /**
     * 准备请求数据
     *
     * @param string $method 请求方法
     * @param array $config 配置
     * @return array
     */
    private function prepareRequest(string $method, array $config): array
    {
        $requestData = [
            'method' => strtoupper($method),
            'headers' => $config['headers'],
        ];
        
        // 处理Cookie
        if ($config['cookies'] && !empty($this->cookies)) {
            $cookieString = '';
            foreach ($this->cookies as $name => $value) {
                $cookieString .= $name . '=' . $value . '; ';
            }
            $requestData['headers']['Cookie'] = rtrim($cookieString, '; ');
        }
        
        // 处理请求体
        if (isset($config['form_params'])) {
            $requestData['body'] = http_build_query($config['form_params']);
            $requestData['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (isset($config['json'])) {
            $requestData['body'] = json_encode($config['json'], JSON_UNESCAPED_UNICODE);
            $requestData['headers']['Content-Type'] = 'application/json';
        } elseif (isset($config['multipart'])) {
            // 文件上传处理
            $boundary = uniqid('----WebKitFormBoundary');
            $requestData['body'] = $this->buildMultipartBody($config['multipart'], $boundary);
            $requestData['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        } elseif (isset($config['body'])) {
            $requestData['body'] = $config['body'];
        }
        
        return $requestData;
    }

    /**
     * 构建multipart请求体
     *
     * @param array $multipart 多部分数据
     * @param string $boundary 边界
     * @return string
     */
    private function buildMultipartBody(array $multipart, string $boundary): string
    {
        $body = '';
        
        foreach ($multipart as $part) {
            $body .= "--{$boundary}\r\n";
            
            if (isset($part['filename'])) {
                $body .= "Content-Disposition: form-data; name=\"{$part['name']}\"; filename=\"{$part['filename']}\"\r\n";
                $body .= "Content-Type: " . ($part['content_type'] ?? 'application/octet-stream') . "\r\n\r\n";
                if (is_resource($part['contents'])) {
                    $contents = stream_get_contents($part['contents']);
                    $body .= $contents . "\r\n";
                    fclose($part['contents']);
                } else {
                    $body .= $part['contents'] . "\r\n";
                }
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$part['name']}\"\r\n\r\n";
                $body .= $part['contents'] . "\r\n";
            }
        }
        
        $body .= "--{$boundary}--\r\n";
        
        return $body;
    }

    /**
     * 创建处理器栈
     *
     * @return callable
     */
    private function createHandlerStack(): callable
    {
        $handler = function ($method, $url, $requestData) {
            return [$method, $url, $requestData];
        };
        
        // 应用中间件
        foreach (array_reverse($this->middleware) as $middleware) {
            $handler = function ($method, $url, $requestData) use ($middleware, $handler) {
                return $middleware($method, $url, $requestData, $handler);
            };
        }
        
        return $handler;
    }

    /**
     * 执行请求
     *
     * @param array $promise 请求承诺
     * @param array $config 配置
     * @return Response
     * @throws HttpException
     */
    private function executeRequest(array $promise, array $config) : Response
    {
        [$method, $url, $requestData] = $promise;
        
        // 创建cURL句柄
        $ch = $this->curlHandle ?: curl_init();
        $this->curlHandle = $ch;

        // 设置cURL选项
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $requestData['method'],
            CURLOPT_TIMEOUT        => $config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'],
            CURLOPT_SSL_VERIFYPEER => $config['verify'],
            CURLOPT_SSL_VERIFYHOST => $config['verify'] ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $config['allow_redirects']['max'] > 0,
            CURLOPT_MAXREDIRS      => $config['allow_redirects']['max'],
            CURLOPT_BUFFERSIZE     => $config['buffer_size'] ?? 4096,   // 缓冲区大小
        ];

        // 设置代理
        if ($config['proxy']) {
            $options[CURLOPT_PROXY] = $config['proxy'];
        }
        
        // 设置请求头
        if (!empty($requestData['headers'])) {
            $headers = [];
            foreach ($requestData['headers'] as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        
        // 设置请求体
        if (isset($requestData['body'])) {
            $options[CURLOPT_POSTFIELDS] = $requestData['body'];
        }
        
        // ====== 输出处理 ======
        $responseHeaders = [];  // 用于收集响应头
        $bodyBuffer = '';       // 非流式模式暂存主体

        // 响应头回调
        $options[CURLOPT_HEADERFUNCTION] = function($ch, $headerLine) use (&$responseHeaders) {
            $this->parseHeaderLine($responseHeaders, $headerLine);
            return strlen($headerLine);
        };

        // 响应体处理
        $isStreaming = $config['stream'] ?? false;
        $saveFile    = $config['savefile'] ?? null;
        $writeCb     = $config['write_callback'] && is_callable($config['write_callback']) ? $config['write_callback'] : null;

        $resumeFrom = 0;
        if ($isStreaming) {
            if ($saveFile) {
                // 保存到文件
                $fp = $this->createFileHandle($config, $resumeFrom);
                $options[CURLOPT_WRITEFUNCTION] = function($ch, $data) use ($fp) {
                    return fwrite($fp, $data);
                };

                // 配置断点续传
                if ($resumeFrom > 0) {
                    $options[CURLOPT_RESUME_FROM] = $resumeFrom;
                }
            } elseif ($writeCb) {
                // 用户自定义块处理
                $options[CURLOPT_WRITEFUNCTION] = function($ch, $data) use ($writeCb) {
                    return $writeCb($data);
                };
            } else {
                throw new \InvalidArgumentException(
                    '流式下载时必须提供 "savefile" 或 "write_callback" 选项'
                );
            }
        } else {
            // 非流式：将数据暂存到内存
            $options[CURLOPT_WRITEFUNCTION] = function($ch, $data) use (&$bodyBuffer) {
                $bodyBuffer .= $data;
                return strlen($data);
            };
        }

        // 配置下载进度
        if ($config['progress'] && is_callable($config['progress'])) {
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_PROGRESSFUNCTION] = function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($config, $resumeFrom) {
                // 调整总大小为原始大小
                if ($downloadTotal > 0) {
                    $originalTotal = $downloadTotal + $resumeFrom;
                    $totalDownloaded = $downloaded + $resumeFrom;
                    $config['progress']($originalTotal, $totalDownloaded);
                }
                return 0;
            };
        }
        
        curl_setopt_array($ch, $options);

        // 执行请求
        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $errno  = curl_errno($ch);
        $info   = curl_getinfo($ch);

        // 关闭文件句柄（如果是内部创建的）
        if ($isStreaming && $saveFile && is_string($config['savefile'])) {
            $this->closeFileHandle($fp, $config);
        }

        if ($result === false) {
            throw new HttpException("cURL错误: $error", $errno);
        }

        // 构造 Response 对象
        $statusCode = $info['http_code'] ?? 0;
        if ($isStreaming) {
            // 流式模式不保留主体数据
            $response = new Response($statusCode, $responseHeaders, null);
        } else {
            $response = new Response($statusCode, $responseHeaders, $bodyBuffer);
        }

        // 解析 Cookie（从 $responseHeaders 中提取 Set-Cookie）
        $this->parseCookies($responseHeaders);

        // 保存文件重命名逻辑
        $this->renameSavefile($config, $response, $statusCode, $responseHeaders);

        // 错误检查
        if ($config['http_errors'] && $statusCode >= 400) {
            throw new HttpException(
                "HTTP {$statusCode} 错误: " . ($isStreaming ? '' : $bodyBuffer) . json_encode($info),
                $statusCode
            );
        }

        return $response;
    }

    /**
     * 创建文件句柄
     *
     * @param array $config 配置数组
     * @param int &$resumeFrom 断点续传位置引用
     * @return resource|object 文件句柄
     * @throws HttpException
     */
    private function createFileHandle(array $config, int &$resumeFrom = 0)
    {
        if(is_string($config['savefile'])){
            $path = $config['savefile'];
            $resumeEnabled = $config['resume'] ?? false;
            $resumeFrom = 0;

            // 计算续传偏移量
            if ($resumeEnabled && file_exists($path)) {
                $resumeFrom = filesize($path);
            }

            // 确保目录存在
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // 根据偏移量选择打开模式
            if ($resumeFrom > 0) {
                $fp = fopen($path, 'cb');
                if ($fp) {
                    fseek($fp, $resumeFrom);
                }
            } else {
                // 覆盖写入
                $fp = fopen($path, 'wb');
            }

            if (!$fp) {
                throw new HttpException('无法创建文件句柄: ' . $path);
            }
            return $fp;
        }else if ((is_object($config['savefile']) && method_exists($config['savefile'], 'close')) || 
                (is_resource($config['savefile']) && get_resource_type($config['savefile']) === 'stream')) {
            $resumeFrom = 0;
            return $config['savefile'];
        }else{
            throw new HttpException('无法创建文件句柄: 不支持的文件类型');
        }
    }

    /**
     * 关闭文件句柄
     *
     * @param resource|object $fp 文件句柄
     */
    private function closeFileHandle($fp, array $config)
    {
        if (is_resource($fp) && get_resource_type($fp) === 'stream') {
            fclose($fp);
        } else if (is_object($fp) && method_exists($fp, 'close')) {
            $fp->close();
        }
    }

    /**
     * 重命名保存文件
     *
     * @param array $config 配置数组
     * @param Response $response 响应对象
     */
    private function renameSavefile(array $config, Response $response, int $statusCode, array $responseHeaders): void
    {
        if (!empty($config['_dir_mode']) && !empty($config['_original_dir']) && !empty($config['savefile']) && is_string($config['savefile'])) {
            $tempPath = $config['savefile'];
            $dir = $config['_original_dir'];
            $url = $config['_original_url'] ?? '';

            if ($statusCode >= 200 && $statusCode < 300) {
                $filename = $this->extractFilenameFromResponse($responseHeaders, $url);
                if (!$filename) {
                    $filename = $this->extractFilenameFromUrl($url);
                }

                if ($filename) {
                    $finalPath = $dir . DIRECTORY_SEPARATOR . $filename;
                    // 覆盖已存在文件
                    if (file_exists($finalPath)) {
                        unlink($finalPath);
                    }
                    if (rename($tempPath, $finalPath)) {
                        $response->setSavedPath($finalPath);
                    } else {
                        $response->setSavedPath($tempPath);
                    }
                } else {
                    // 无法提取文件名，使用默认名称
                    $finalPath = $dir . DIRECTORY_SEPARATOR . 'downloaded_file_' . time();
                    if (rename($tempPath, $finalPath)) {
                        $response->setSavedPath($finalPath);
                    } else {
                        $response->setSavedPath($tempPath);
                    }
                }
            } else {
                // 请求失败，保留临时文件以便排查
                $response->setSavedPath($tempPath);
            }
        }
    }

    /**
     * 从响应头中提取文件名
     *
     * @param array $headers 响应头数组
     * @param string $url 请求URL
     * @return string|null 提取到的文件名或null
     */
    private function extractFilenameFromResponse(array $headers, string $url): ?string
    {
        $cd = $headers['Content-Disposition'][0] ?? null;
        if ($cd) {
            // RFC 5987 格式：filename*=UTF-8''%E4%B8%AD%E6%96%87.txt
            if (preg_match('/filename\*=(?:UTF-8\'\')?([^;]+)/i', $cd, $matches)) {
                $filename = rawurldecode(trim($matches[1]));
                if ($filename) return $filename;
            }
            // 普通格式：filename="example.txt" 或 filename=example.txt
            if (preg_match('/filename=([^;]+)/i', $cd, $matches)) {
                $filename = trim($matches[1], " \"'");
                if ($filename) return $filename;
            }
        }
        return $this->extractFilenameFromUrl($url);
    }

    /**
     * 从URL中提取文件名
     *
     * @param string $url 请求URL
     * @return string|null 提取到的文件名或null
     */
    private function extractFilenameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) return null;
        $filename = basename($path);
        $filename = preg_replace('/\?.*$/', '', $filename);
        if (!$filename || $filename === '/' || $filename === '\\') {
            return uniqid('httpclient_download', true);
        }
        return rawurldecode($filename);
    }
    /**
     * 解析响应头行
     *
     * @param array &$headers 响应头数组引用
     * @param string $line 响应头行字符串
     */
    private function parseHeaderLine(array &$headers, string $line): void
    {
        $line = trim($line);
        if (empty($line)) return;

        if (strpos($line, ':') !== false) {
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $headers[$name][] = $value;  // 始终存储为数组，便于处理同名头
        } else {
            // HTTP 状态行，忽略或可单独存储
        }
    }

    /**
     * 解析Cookie
     *
     * @param array $headers 响应头
     */
    private function parseCookies(array $headers): void
    {
        $setCookie = $headers['Set-Cookie'] ?? [];
        if (empty($setCookie)) return;

        foreach ((array)$setCookie as $cookie) {
            $parts = explode(';', $cookie, 2);
            $cookiePart = trim($parts[0]);
            if (strpos($cookiePart, '=') !== false) {
                [$name, $value] = explode('=', $cookiePart, 2);
                $this->cookies[trim($name)] = trim($value);
            }
        }
    }

    /**
     * 加载Cookie
     */
    private function loadCookies(): void
    {
        $cookies = Cache::get('http_client_cookies', []);
        if (is_array($cookies)) {
            $this->cookies = $cookies;
        }
    }

    /**
     * 保存Cookie
     */
    private function saveCookies(): void
    {
        Cache::set('http_client_cookies', $this->cookies, 3600);
    }

    /**
     * 注册默认中间件
     */
    private function registerDefaultMiddleware(): void
    {
        // 日志中间件
        $this->addMiddleware(function ($method, $url, $requestData, $next) {
            $startTime = microtime(true);
            $result = $next($method, $url, $requestData);
            $endTime = microtime(true);
            
            if ($this->config['debug']) {
                ContextualLogger::info(sprintf(
                    'HTTP请求: %s %s 耗时: %.2fs',
                    $method,
                    $url,
                    $endTime - $startTime
                ));
            }
            
            return $result;
        }, 'log');
        
        // 重试中间件
        $this->addMiddleware(function ($method, $url, $requestData, $next) {
            $maxRetries = $this->config['max_retries'] ?? 3;
            $retryDelay = $this->config['retry_delay'] ?? 100;
            
            $lastException = null;

            for ($retry = 0; $retry <= $maxRetries; $retry++) {
                try {
                    return $next($method, $url, $requestData);
                } catch (\Throwable $e) {
                    $lastException = $e;

                    // 如果是最后一次重试，直接抛出异常
                    if ($retry === $maxRetries) {
                        throw $e;
                    }
                    
                    // 只有特定状态码才重试
                    if ($e instanceof HttpException && in_array($e->getCode(), [500, 502, 503, 504, 408])) {
                        usleep($retryDelay * 1000);
                        $retryDelay *= 2; // 指数退避
                        continue;
                    }
                    
                    // 其他异常不重试
                    throw $e;
                }
            }
            
            throw $lastException;
        }, 'retry');
    }
}

/**
 * HTTP响应类
 */
class Response
{
    /**
     * @var int 状态码
     */
    private $statusCode;
    
    /**
     * @var array 响应头
     */
    private $headers;
    
    /**
     * @var ?string 响应体
     */
    private $rawBody;
    
    /**
     * @var string 保存路径
     */
    private $savedPath;

    /**
     * 构造函数
     *
     * @param int $statusCode 状态码
     * @param array $headers 响应头
     * @param ?string $body 响应体
     */
    public function __construct(int $statusCode, array $headers, ?string $body)
    {
        $this->statusCode = $statusCode;
        $this->rawBody = $body;
        $this->headers = array_map(function($value) {
            return implode(';', $value);
        }, $headers);
    }
    
    /**
     * 获取状态码
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * 获取响应头
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * 获取指定响应头
     *
     * @param string $name 头名称
     * @return string|array|null
     */
    public function getHeader(string $name)
    {
        return $this->headers[$name] ?? null;
    }
    
    /**
     * 获取原始响应体
     *
     * @return ?string
     */
    public function getBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * 获取JSON格式的响应体
     *
     * @return array|null
     */
    public function getJson(): ?array
    {
        if(!$this->isText()){
            return null;
        }

        $json = json_decode($this->rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }
        return null;
    }
    
    /**
     * 获取XML格式的响应体
     *
     * @return \SimpleXMLElement|null
     */
    public function getXml(): ?\SimpleXMLElement
    {
        if(!$this->isText()){
            return null;
        }

        try {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($this->rawBody);
            if ($xml !== false) {
                return $xml;
            }
            libxml_clear_errors();
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取保存路径
     *
     * @return string|null
     */
    public function getSavedPath(): ?string
    {
        return $this->savedPath;
    }

    /**
     * 设置保存路径
     *
     * @param string $path 保存路径
     */
    public function setSavedPath(string $path): void
    {
        // 如果$body为null且是文本类型且文件存在，从文件读取内容
        // 当请求二进制实际返回文本时自动加载到 body 内
        if($this->rawBody == null && $this->isText() && file_exists($path)){
            $this->rawBody = file_get_contents($path) ?: '';
        }
        $this->savedPath = $path;
    }

    /**
     * 判断是否为二进制响应
     *
     * @return bool
     */
    public function isBinary(): bool
    {
        return !$this->isText();
    }
    
    /**
     * 判断是否为文本响应
     *
     * @return bool
     */
    public function isText(): bool
    {
        $contentType = $this->getHeader('Content-Type') ?? '';
        return strpos($contentType, 'text/') !== false ||
               strpos($contentType, 'application/json') !== false ||
               strpos($contentType, 'application/xml') !== false;
    }

    /**
     * 判断是否成功
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
    
    /**
     * 判断是否重定向
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }
    
    /**
     * 判断是否客户端错误
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
    
    /**
     * 判断是否服务器错误
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}

/**
 * 异步Promise类
 */
class Promise
{
    /**
     * @var callable 执行函数
     */
    private $executor;
    
    /**
     * @var mixed 结果
     */
    private $result;
    
    /**
     * @var \Throwable|null 异常
     */
    private $exception;
    
    /**
     * @var callable|null 完成回调
     */
    private $onFulfilled;
    
    /**
     * @var callable|null 拒绝回调
     */
    private $onRejected;
    
    /**
     * @var array 多个Promise
     */
    private static $promises = [];
    
    /**
     * @var bool 是否已执行
     */
    private $settled = false;

    /**
     * 构造函数
     *
     * @param callable $executor 执行函数
     */
    public function __construct(callable $executor)
    {
        $this->executor = $executor;
        $this->result = null;
        $this->exception = null;
    }

    /**
     * 等待结果
     *
     * @return mixed
     */
    public function wait()
    {
        if ($this->settled) {
            if ($this->exception !== null) {
                throw $this->exception;
            }
            return $this->result;
        }

        try {
            $executor = $this->executor;
            $this->result = $executor();
            $this->settled = true;

            if (is_callable($this->onFulfilled)) {
                $this->onFulfilled($this->result);
            }

            return $this->result;
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->settled = true;

            if (is_callable($this->onRejected)) {
                $this->onRejected($e);
            }

            throw $e;
        }
    }
    
    /**
     * 设置完成回调
     *
     * @param callable $onFulfilled 完成回调
     * @return self
     */
    public function then(callable $onFulfilled): self
    {
        $this->onFulfilled = $onFulfilled;
        return $this;
    }
    
    /**
     * 设置拒绝回调
     *
     * @param callable $onRejected 拒绝回调
     * @return self
     */
    public function catch(callable $onRejected): self
    {
        $this->onRejected = $onRejected;
        return $this;
    }
    
    /**
     * 等待多个Promise
     *
     * @param array $promises Promise数组
     * @return self
     */
    public static function settle(array $promises): self
    {
        return new self(function () use ($promises) {
            $results = [];
            
            foreach ($promises as $key => $promise) {
                try {
                    $results[$key] = [
                        'state' => 'fulfilled',
                        'value' => $promise->wait(),
                    ];
                } catch (\Throwable $e) {
                    $results[$key] = [
                        'state' => 'rejected',
                        'reason' => $e,
                    ];
                }
            }
            
            return $results;
        });
    }
}

/**
 * HTTP异常类
 */
class HttpException extends \Exception
{
    /**
     * @var array 额外数据
     */
    private $data;
    
    /**
     * 构造函数
     *
     * @param string $message 错误信息
     * @param int $code 错误码
     * @param array $data 额外数据
     */
    public function __construct(string $message = "", int $code = 0, array $data = [])
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
    
    /**
     * 获取额外数据
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }
}