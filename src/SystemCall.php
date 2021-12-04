<?php

declare(strict_types=1);

namespace Loner\Coroutine;

use Generator;
use InvalidArgumentException;

/**
 * 系统调用
 *
 * @package Loner\Coroutine
 */
class SystemCall
{
    /**
     * 回调
     *
     * @var callable
     */
    private $callback;

    /**
     * 返回系统调用：获取任务 ID
     *
     * @return SystemCall
     */
    public static function getTaskId(): self
    {
        return new self(
            function (Task $task, Scheduler $scheduler) {
                $task->send($task->getId());
                $scheduler->schedule($task);
            }
        );
    }

    /**
     * 返回系统调用：杀死任务
     *
     * @param string $tid
     * @return self
     */
    public static function killTask(string $tid): self
    {
        return new self(
            function (Task $task, Scheduler $scheduler) use ($tid) {
                if ($scheduler->killTask($tid)) {
                    $scheduler->schedule($task);
                } else {
                    throw new InvalidArgumentException('Invalid task ID!');
                }
            }
        );
    }

    /**
     * 返回系统调用：创建任务
     *
     * @param Generator $coroutine
     * @return self
     */
    public static function newTask(Generator $coroutine): self
    {
        return new self(
            function (Task $task, Scheduler $scheduler) use ($coroutine) {
                $task->send($scheduler->newTask($coroutine));
                $scheduler->schedule($task);
            }
        );
    }

    /**
     * 返回系统调用：添加套接字流的读监听任务
     *
     * @param resource $socket
     * @return self
     */
    public static function waitForRead($socket): self
    {
        return new self(fn(Task $task, Scheduler $scheduler) => $scheduler->waitForRead($socket, $task));
    }

    /**
     * 返回系统调用：添加套接字流的写监听任务
     *
     * @param resource $socket
     * @return self
     */
    public static function waitForWrite($socket): self
    {
        return new self(fn(Task $task, Scheduler $scheduler) => $scheduler->waitForWrite($socket, $task));
    }

    /**
     * 初始化回调
     *
     * @param callable $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * 执行回调
     *
     * @param Task $task
     * @param Scheduler $scheduler
     * @return mixed
     */
    public function __invoke(Task $task, Scheduler $scheduler): mixed
    {
        $callback = $this->callback;
        return $callback($task, $scheduler);
    }
}
