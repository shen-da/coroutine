<?php

declare(strict_types=1);

namespace Loner\Coroutine;

/**
 * 子协程返回值
 *
 * @package Loner\Coroutine
 */
class ReturnValue
{
    /**
     * 初始化返回值
     *
     * @param mixed $value
     */
    public function __construct(private mixed $value)
    {
    }

    /**
     * 获取返回值
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return $this->value;
    }
}
