<?php

declare(strict_types=1);

namespace Test\MultiFlexi;

use MultiFlexi\CredentialProtoType\Realpad;
use MultiFlexi\CredentialState;
use PHPUnit\Framework\TestCase;

final class RealpadCheckTest extends TestCase
{
    private static $server;
    private static string $dir;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/realpad-check-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0o700);
        file_put_contents(self::$dir.'/router.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/request.json', json_encode([
    'uri' => $_SERVER['REQUEST_URI'], 'method' => $_SERVER['REQUEST_METHOD'],
    'post' => $_POST, 'basic' => isset($_SERVER['PHP_AUTH_USER']),
]));
http_response_code((int) file_get_contents(__DIR__.'/code'));
if (file_get_contents(__DIR__.'/code') === '429') { header('Retry-After: 77'); }
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$dir.'/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        for ($i = 0; $i < 50; ++$i) {
            $probe = @fsockopen('127.0.0.1', self::$port);

            if ($probe) {
                fclose($probe);

                return;
            }

            usleep(100000);
        }

        self::fail('Stub server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        array_map('unlink', glob(self::$dir.'/*'));
        rmdir(self::$dir);
    }

    /**
     * @return array<string, array{int, CredentialState, int}>
     */
    public static function codes(): array
    {
        return [
            '200' => [200, CredentialState::Available, 300],
            '302' => [302, CredentialState::Unavailable, 60],
            '400' => [400, CredentialState::Misconfigured, 300],
            '401' => [401, CredentialState::Misconfigured, 300],
            '403' => [403, CredentialState::Misconfigured, 300],
            '429' => [429, CredentialState::Degraded, 77],
            '500' => [500, CredentialState::Unavailable, 60],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codes')]
    public function testHttpCodeMapping(int $code, CredentialState $state, int $ttl): void
    {
        file_put_contents(self::$dir.'/code', (string) $code);
        $result = $this->realpad('user', '@p&ss=ř')->checkAvailability();

        $this->assertSame($state, $result->state);
        $this->assertSame($ttl, $result->ttl);
        $request = json_decode(file_get_contents(self::$dir.'/request.json'), true);
        $this->assertSame('/ws/v10/list-resources', $request['uri']);
        $this->assertSame('POST', $request['method']);
        $this->assertSame(['login' => 'user', 'password' => '@p&ss=ř'], $request['post']);
        $this->assertFalse($request['basic']);
    }

    public function testEmptyCredentialsSendNoRequest(): void
    {
        @unlink(self::$dir.'/request.json');
        $this->assertSame(CredentialState::Misconfigured, $this->realpad('', '')->checkAvailability()->state);
        $this->assertFileDoesNotExist(self::$dir.'/request.json');
    }

    private function realpad(string $login, string $password): Realpad
    {
        $realpad = new class(self::$port) extends Realpad {
            public function __construct(int $port)
            {
                parent::__construct();
                $this->baseEndpoint = 'http://127.0.0.1:'.$port.'/';
            }

            public function fill(string $login, string $password): void
            {
                $this->configFieldsProvided->getFieldByCode('REALPAD_USERNAME')->setValue($login);
                $this->configFieldsProvided->getFieldByCode('REALPAD_PASSWORD')->setValue($password);
            }
        };
        $realpad->fill($login, $password);

        return $realpad;
    }
}
