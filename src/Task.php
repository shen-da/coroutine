<?php

declare(strict_types=1);

namespace Loner\Coroutine;

use Exception;
use Generator;
use SplStack;

/**
 * 协程任务
 *
 * @package Loner\Coroutine
 */
class Task
{
    /**
     * 协程
     *
     * @var Generator
     */
    private Generator $coroutine;

    /**
     * 是否初始迭代之前
     *
     * @var bool
     */
    private bool $beforeFirstYield = true;

    /**
     * 即将向协程传入的值
     *
     * @var mixed
     */
    private mixed $sendValue = null;

    /**
     * 即将向协程抛入的异常
     *
     * @var Exception|null
     */
    private ?Exception $throwException = null;

    /**
     * 记录协程 ID，协程简化（复合协程摊开堆栈化）
     *
     * @param int $id
     * @param Generator $coroutine
     * @throws Exception
     */
    public function __construct(private int $id, Generator $coroutine)
    {
        $this->coroutine = self::normalize($coroutine);
    }

    /**
     * 返回协程 ID
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * 协程运行：
     *
     * @return mixed
     */
    public function run(): mixed
    {
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current();
        } else {
            if ($this->throwException) {
                $returnValue = $this->coroutine->throw($this->throwException);
                $this->throwException = null;
            } else {
                $returnValue = $this->coroutine->send($this->sendValue);
                $this->sendValue = null;
            }
            return $returnValue;
        }
    }

    /**
     * 设置传入值
     *
     * @param mixed $value
     */
    public function send(mixed $value): void
    {
        $this->sendValue = $value;
    }

    /**
     * 设置抛入异常
     *
     * @param Exception $exception
     */
    public function throw(Exception $exception): void
    {
        $this->throwException = $exception;
    }

    /**
     * 协程是否已执行完毕
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return !$this->coroutine->valid();
    }

    /**
     * 标准化协程
     *  - 递归，堆栈存放当前迭代协程的祖、父级（若存在）
     *  - 通过堆栈，实现所有迭代操作有序（递归迭代）
     *  - 子协程相当于封装模块，使用更加灵活
     *
     * @param Generator $coroutine
     * @return Generator
     * @throws Exception
     */
    private static function normalize(Generator $coroutine): Generator
    {
        $stack = new SplStack;
        $exception = null;

        // for (;;) 不需要判断条件；while (true) 需要判断条件
        for (; ;) {
            try {
                if ($exception) {
                    // 子协程迭代异常，父级抛出
                    $coroutine->throw($exception);
                    $exception = null;
                    continue;
                }

                $current = $coroutine->current();

                if ($current instanceof Generator) {
                    $stack->push($coroutine);
                    $coroutine = $current;
                    continue;
                }

                $isReturnValue = $current instanceof ReturnValue;

                if (!$coroutine->valid() || $isReturnValue) {
                    // 当前为最上层协程：被关闭或值为子协程返回值类型，则结束迭代
                    if ($stack->isEmpty()) {
                        break;
                    }

                    // 子协程结束，回到父协程
                    $coroutine = $stack->pop();
                    // 子协程返回值：有返回值，否则null
                    $coroutine->send($isReturnValue ? $current->getValue() : null);
                    continue;
                }

                try {
                    // 新协程代理返回值，无改则原值
                    $sendValue = (yield $coroutine->key() => $current);
                } catch (Exception $e) {
                    // 新协程迭代异常，同步原协程
                    $coroutine->throw($e);
                    continue;
                }

                // 新协程 next，同步返回值，驱动原协程 next
                $coroutine->send($sendValue);
            } catch (Exception $e) {
                // 若为最上层协程的异常，直接抛出
                if ($stack->isEmpty()) {
                    throw $e;
                }

                // 切回父协程，等待父级处理
                $coroutine = $stack->pop();
                $exception = $e;
            }
        }
    }
}
