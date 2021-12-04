<?php

declare(strict_types=1);

namespace Loner\Coroutine;

use Generator;

/**
 * 协程套接字
 *
 * @package Loner\Coroutine
 */
class Socket
{
    /**
     * 套接字流
     *
     * @var resource
     */
    private $socket;

    /**
     * 初始化套接字流
     *
     * @param resource $socket
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * 套接字接收客户端协程
     *
     * @return Generator
     */
    public function accept(): Generator
    {
        yield SystemCall::waitForRead($this->socket);
        $socket = stream_socket_accept($this->socket, 0);
        stream_set_blocking($socket, 0);
        // Compatible with hhvm
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($socket, 0);
        }
        yield new ReturnValue(new Socket($socket));
    }

    /**
     * 套接字读协程
     *
     * @param int $size
     * @return Generator
     */
    public function read(int $size): Generator
    {
        yield SystemCall::waitForRead($this->socket);
        yield new ReturnValue(fread($this->socket, $size));
    }

    /**
     * 套接字写协程
     *
     * @param string $string
     * @return Generator
     */
    public function write(string $string): Generator
    {
        yield SystemCall::waitForWrite($this->socket);
        yield new ReturnValue(fwrite($this->socket, $string));
    }

    /**
     * 关闭主套接字流
     */
    public function close(): void
    {
        @fclose($this->socket);
    }
}
