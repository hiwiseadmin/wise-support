<?php
declare(strict_types=1);

namespace wise\support\traits;

use think\facade\Log;
use think\facade\Config;
use think\exception\HttpException;
use think\exception\ValidateException;
use wise\support\exception\WiseException;

/**
 * 错误处理 Trait
 *
 * 提供统一的错误收集、异常处理和日志记录能力。
 *
 * 与 JsonResponse trait 配合使用时，handleException() 会自动返回
 * 统一的 JSON 错误响应；单独使用时返回数组。
 *
 * 使用方式：
 *   class MyController
 *   {
 *       use ErrorHandler;
 *       use JsonResponse;  // 可选：搭配使用自动返回 JSON 错误
 *
 *       public function doSomething()
 *       {
 *           try {
 *               // ...
 *           } catch (\Throwable $e) {
 *               return $this->handleException($e);
 *           }
 *       }
 *   }
 *
 * @package wise\support\traits
 */
trait ErrorHandler
{
    /**
     * 错误信息集合
     * @var array
     */
    protected array $errors = [];

    /**
     * 添加错误信息
     *
     * @param string $message 错误信息
     * @param int    $code    错误码
     * @param string $field   字段名（用于表单验证错误）
     */
    protected function addError(string $message, int $code = 0, string $field = ''): self
    {
        if ($field) {
            $this->errors[$field] = ['code' => $code, 'msg' => $message];
        } else {
            $this->errors[] = ['code' => $code, 'msg' => $message];
        }
        return $this;
    }

    /**
     * 获取所有错误信息
     */
    protected function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 是否有错误
     */
    protected function hasError(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 清空错误信息
     */
    protected function clearErrors(): self
    {
        $this->errors = [];
        return $this;
    }

    /**
     * 获取最后一个错误
     */
    protected function getLastError(): ?array
    {
        if ($this->errors === []) {
            return null;
        }
        $last = $this->errors[array_key_last($this->errors)] ?? null;
        return is_array($last) ? $last : null;
    }

    /**
     * 统一异常处理
     *
     * 自动识别 ValidateException / HttpException / 通用异常，
     * 生产环境隐藏详细错误。
     *
     * 如果当前类同时使用了 JsonResponse trait，则自动返回 JSON 响应。
     *
     * @param \Throwable $e      异常对象
     * @param bool       $return 是否返回数组（false 时配合 JsonResponse 自动输出 JSON）
     */
    protected function handleException(\Throwable $e, bool $return = false)
    {
        $this->logException($e);

        if ($e instanceof ValidateException) {
            $code    = 400;
            $message = $e->getMessage();
            $data    = $e->getError();
        } elseif ($e instanceof WiseException) {
            $code    = $e->getErrorCode();
            $message = $e->getMessage();
            $data    = $e->getContext();
        } elseif ($e instanceof HttpException) {
            $code    = $e->getStatusCode();
            $message = $e->getMessage() ?: $this->getHttpStatusText($code);
            $data    = null;
        } else {
            $code    = $e->getCode() ?: 500;
            $message = Config::get('app.debug') ? $e->getMessage() : '服务器内部错误';
            $data    = Config::get('app.debug') ? [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ] : null;
        }

        // 与 JsonResponse trait 配合：自动输出 JSON 错误响应
        if (method_exists($this, 'error') && !$return) {
            return $this->error($message, $data, $code);
        }

        return [
            'code'      => $code,
            'msg'       => $message,
            'data'      => $data,
            'timestamp' => time(),
        ];
    }

    /**
     * 记录异常日志
     *
     * @param \Throwable $e     异常对象
     * @param string     $level 日志级别
     */
    protected function logException(\Throwable $e, string $level = 'error'): void
    {
        if ($e instanceof HttpException && $e->getStatusCode() < 500) {
            $level = 'warning';
        }

        $logData = json_encode([
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($logData === false) {
            $traceFunc = $e->getTrace()[0]['function'] ?? 'unknown';
            $logData = json_encode([
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => '[serialization failed: ' . $traceFunc . ']',
            ], JSON_UNESCAPED_UNICODE);
        }

        Log::record($logData, $level);
    }

    /**
     * HTTP 状态码 → 文本描述
     */
    protected function getHttpStatusText(int $code): string
    {
        $statusTexts = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return $statusTexts[$code] ?? 'Unknown Error';
    }
}
