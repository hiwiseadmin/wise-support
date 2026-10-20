<?php

declare(strict_types=1);

namespace wise\support\traits;

use think\facade\Config;

/**
 * WiseAdmin 包 Service 提供者共用 Trait
 *
 * 提供配置自动合并功能，宿主配置优先覆盖包默认配置。
 * 所有 wise-* 包统一引用此 Trait，不再各自维护副本。
 */
trait ServiceTrait
{
    /**
     * 合并包默认配置到全局配置
     *
     * @param string $configName 配置名（不含 .php 后缀）
     * @return array 合并后的配置
     */
    protected function mergeConfig(string $configName): array
    {
        $defaultConfig = $this->loadPackageDefaultConfig($configName);
        if ($defaultConfig === null) {
            return [];
        }

        $appConfig    = Config::get($configName, []);
        $mergedConfig = $this->arrayMergeRecursive($defaultConfig, $appConfig);
        Config::set($mergedConfig, $configName);
        return $mergedConfig;
    }

    /**
     * 加载包默认配置文件。
     *
     * @throws \RuntimeException 配置文件加载失败或返回非数组时抛出
     */
    protected function loadPackageDefaultConfig(string $configName): ?array
    {
        // 安全加固：配置名仅允许字母数字下划线点横线，拒绝路径穿越
        if (str_contains($configName, '..') || !\preg_match('/^[A-Za-z0-9_.\-]+$/', $configName)) {
            throw new \InvalidArgumentException('非法的配置名: ' . $configName);
        }
        $filePath = $this->getPackageConfigFilePath($configName);
        if (!\file_exists($filePath)) {
            return null;
        }
        try {
            $config = require $filePath;
            return \is_array($config) ? $config : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                \sprintf('Failed to load package config "%s": %s', $configName, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * 获取包内配置文件的绝对路径
     *
     * 默认规则：{包根目录}/config/{configName}.php
     * 包根目录通过 ReflectionClass 自动定位（向上两级）。
     */
    protected function getPackageConfigFilePath(string $configName): string
    {
        $reflection  = new \ReflectionClass($this);
        $packageRoot = dirname($reflection->getFileName(), 2);
        return $packageRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
    }

    /**
     * 递归合并数组（宿主配置优先）。
     *
     * 关联数组按键递归合并；列表（连续整数键）整体替换，
     * 既避免包默认值与宿主配置按下标错位拼接残留脏值，
     * 也允许宿主用 [] 显式清空包默认列表。
     */
    protected function arrayMergeRecursive(array $base, array $override): array
    {
        if (\array_is_list($base) && \array_is_list($override)) {
            return $override;
        }

        $result = $base;
        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($result[$key]) && \is_array($result[$key])) {
                $result[$key] = $this->arrayMergeRecursive($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
