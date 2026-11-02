<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

/**
 * Starts tests/Support/echo-server.php on a free local port for the duration of a test class.
 */
final class EchoServer
{
    /** @var resource|null */
    private static $process = null;

    private static ?string $origin = null;

    public static function start(): string
    {
        if (self::$origin !== null) {
            return self::$origin;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Cannot allocate a port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/echo-server.php'];
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start the echo server.');
        }
        self::$process = $process;
        register_shutdown_function(static function (): void {
            if (self::$process !== null) {
                proc_terminate(self::$process);
            }
        });

        for ($i = 0; $i < 50; ++$i) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return self::$origin = 'http://127.0.0.1:' . $port;
            }
            usleep(100_000);
        }

        throw new \RuntimeException('The echo server did not start.');
    }
}
