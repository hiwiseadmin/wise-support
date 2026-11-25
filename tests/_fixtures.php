<?php
declare(strict_types=1);

interface TestInterface
{
    public function execute(): string;
}

class TestImpl implements TestInterface
{
    public function execute(): string { return 'ok'; }
}

class BadImpl
{
}

class WechatPay
{
}

class Alipay
{
}
