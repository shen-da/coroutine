<?php

declare(strict_types=1);

namespace Loner\Coroutine;

use Exception;
use Generator;

/**
 * 调度器
 *
 * @package Loner\Coroutine
 */
class Scheduler
{
    /**
     * 任务 ID 最大值
     *
     * @var int
     */
    private int $maxTaskId = 0;

    /**
     * 任务 ID 对照表
     *
     * @var Task[] [$taskId => Task]
     */
    private array $taskMap = [];

    /**
     * 任务队列
     *
     * @var Task[] [$taskId => Task]
     */
    private array $taskQueue = [];

    /**
     * 待读取套接字列表
     *
     * @var resource[]
     */
    private array $readSockets = [];

    /**
     * 待写入套接字列表
     *
     * @var resource[]
     */
    private array $writeSockets = [];

    /**
     * 套接字读任务列表
     *
     * @var Task[]
     */
    private array $readTasks = [];

    /**
     * 套接字写任务列表
     *
     * @var Task[]
     */
    private array $writeTasks = [];

    /**
     * 新建任务
     *
     * @param Generator $coroutine
     * @return int
     * @throws Exception
     */
    public function newTask(Generator $coroutine): int
    {
        $taskId = ++$this->maxTaskId;
        $task = new Task($taskId, $coroutine);
        $this->taskMap[$taskId] = $task;
        $this->schedule($task);
        return $taskId;

    }

    /**
     * 杀死任务
     *
     * @param string $tid
     * @return bool
     */
    public function killTask(string $tid): bool
    {
        if (isset($this->taskMap[$tid])) {
            unset($this->taskMap[$tid], $this->taskQueue[$tid]);
            return true;
        }
        return false;
    }

    /**
     * 收录任务
     *
     * @param Task $task
     */
    public function schedule(Task $task): void
    {
        $this->taskQueue[$task->getId()] = $task;
    }

    /**
     * 执行调度程序
     */
    public function run(): void
    {
        while ($task = array_shift($this->taskQueue)) {
            $returnValue = $task->run();

            if ($returnValue instanceof SystemCall) {
                try {
                    $returnValue($task, $this);
                } catch (Exception $e) {
                    $task->throw($e);
                    $this->schedule($task);
                }
                continue;
            }
            if ($task->isFinished()) {
                unset($this->taskMap[$task->getId()]);
            } else {
                $this->schedule($task);
            }
        }
    }

    /**
     * 添加读套接字任务
     *
     * @param resource $socket
     * @param Task $task
     */
    public function waitForRead($socket, Task $task): void
    {
        $key = (int)$socket;
        $this->readSockets[$key] = $socket;
        $this->readTasks[$key] = $task;
    }

    /**
     * 添加写套接字任务
     *
     * @param resource $socket
     * @param Task $task
     */
    public function waitForWrite($socket, Task $task): void
    {
        $key = (int)$socket;
        $this->writeSockets[$key] = $socket;
        $this->writeTasks[$key] = $task;
    }

    /**
     * 添加 IO 轮询任务
     *
     * @return $this
     * @throws Exception
     */
    public function withIoPoll(): self
    {
        $this->newTask($this->ioPollTask());
        return $this;
    }

    /**
     * IO 轮询任务
     *
     * @return Generator
     */
    private function ioPollTask(): Generator
    {
        for (; ;) {
            empty($this->taskQueue) ? $this->ioPoll(null) : $this->ioPoll(0);
            yield;
        }
    }

    /**
     * @param int|null $timeout
     */
    private function ioPoll(?int $timeout): void
    {
        $rSocks = $this->readSockets;
        $wSocks = $this->writeSockets;

        if (!$rSocks && !$wSocks) {
            return;
        }

        $eSocks = [];

        if (!@stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
            return;
        }

        foreach ($rSocks as $socket) {
            $key = (int)$socket;
            $task = $this->readTasks[$key];
            unset($this->readSockets[$key], $this->readTasks[$key]);

            $this->schedule($task);
        }

        foreach ($wSocks as $socket) {
            $key = (int)$socket;
            $task = $this->writeTasks[$key];
            unset($this->writeSockets[$key], $this->writeTasks[$key]);

            $this->schedule($task);
        }
    }
}
