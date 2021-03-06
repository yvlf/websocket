<?php

namespace Amp\Websocket\Test;

use Aerys\Bootable;
use Aerys\Host;
use Aerys\Server;
use Aerys\ServerObserver;
use Aerys\Websocket;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Success;
use Amp\Websocket\Connection;
use Amp\Websocket\Message;
use Amp\Websocket\Test\Helper\WebsocketAdapter;
use Amp\Websocket\WebSocketException;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use function Aerys\initServer;
use function Aerys\websocket;
use function Amp\call;
use function Amp\Promise\wait;
use function Amp\Websocket\connect;

class WebSocketTest extends TestCase {
    /** @var Server[] */
    private $servers = [];

    protected function tearDown() {
        foreach ($this->servers as $server) {
            wait($server->stop());
        }

        parent::tearDown();
    }

    /**
     * This method creates a new server that listens on a randomly assigned port and returns the used port.
     *
     * The server will automatically shut down after a test case ends.
     *
     * @param Websocket $websocket
     *
     * @return Promise<int> Resolves to the used port number.
     */
    public function createServer(Websocket $websocket): Promise {
        $context = \stream_context_create([
            'socket' => [
                'so_reuseport' => true,
            ],
        ]);

        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
        $portReserveSocket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND, $context);

        if (!$portReserveSocket || $errno) {
            throw new \Exception(\sprintf('Could not create server 127.0.0.1:0 [Error: #%d] %s', $errno, $errstr), $errno);
        }

        $address = \stream_socket_get_name($portReserveSocket, false);
        $port = (int) \explode(':', $address, 2)[1];

        $host = (new Host)
            ->expose('127.0.0.1', $port)
            ->name('localhost')
            ->use(websocket($websocket))
            ->use(new class($portReserveSocket) implements Bootable, ServerObserver {
                private $socket;

                public function __construct($socket) {
                    $this->socket = $socket;
                }

                public function boot(Server $server, PsrLogger $logger) {
                    $server->attach($this);
                }

                public function update(Server $server): Promise {
                    if ($server->state() === Server::STARTED) {
                        \fclose($this->socket);
                    }

                    return new Success;
                }
            });

        $this->servers[] = $server = initServer(new NullLogger, [$host]);

        return call(function () use ($server, $port) {
            yield $server->start();

            return $port;
        });
    }

    public function testSimpleBinaryEcho() {
        wait(call(function () {
            $port = yield $this->createServer(new class extends WebsocketAdapter {
                public function onData(int $clientId, Websocket\Message $msg) {
                    if ($msg->isBinary()) {
                        return $this->endpoint->sendBinary(yield $msg, $clientId);
                    }

                    return $this->endpoint->send(yield $msg, $clientId);
                }
            });

            /** @var Connection $client */
            $client = yield connect('ws://localhost:' . $port . '/');
            $client->sendBinary('Hey!');

            /** @var Message $message */
            $message = yield $client->receive();

            $this->assertInstanceOf(Message::class, $message);
            $this->assertTrue($message->isBinary());
            $this->assertSame('Hey!', yield $message->buffer());

            $promise = $client->receive();
            $client->close();

            $this->assertNull(yield $promise);
        }));
    }

    public function testSimpleTextEcho() {
        wait(call(function () {
            $port = yield $this->createServer(new class extends WebsocketAdapter {
                public function onData(int $clientId, Websocket\Message $msg) {
                    if ($msg->isBinary()) {
                        return $this->endpoint->sendBinary(yield $msg, $clientId);
                    }

                    return $this->endpoint->send(yield $msg, $clientId);
                }
            });

            /** @var Connection $client */
            $client = yield connect('ws://localhost:' . $port . '/');
            $client->send('Hey!');

            /** @var Message $message */
            $message = yield $client->receive();

            $this->assertInstanceOf(Message::class, $message);
            $this->assertFalse($message->isBinary());
            $this->assertSame('Hey!', yield $message->buffer());

            $promise = $client->receive();
            $client->close();

            $this->assertNull(yield $promise);
        }));
    }

    public function testUnconsumedMessage() {
        wait(call(function () {
            $port = yield $this->createServer(new class extends WebsocketAdapter {
                public function onOpen(int $clientId, $handshakeData) {
                    yield $this->endpoint->send(\str_repeat('.', 1024 * 1024 * 1), $clientId);
                    yield $this->endpoint->send('Message', $clientId);
                }
            });

            /** @var Connection $client */
            $client = yield connect('ws://localhost:' . $port . '/');

            /** @var Message $message */
            $message = yield $client->receive();

            $this->assertInstanceOf(Message::class, $message);
            // Do not consume the bytes from the first message.

            $message = yield $client->receive();
            $this->assertFalse($message->isBinary());
            $this->assertSame('Message', yield $message->buffer());

            $this->assertInstanceOf(Message::class, $message);

            $promise = $client->receive();
            $client->close();

            $this->assertNull(yield $promise);
        }));
    }

    public function testVeryLongMessage() {
        wait(call(function () {
            $port = yield $this->createServer(new class extends WebsocketAdapter {
                public function onOpen(int $clientId, $handshakeData) {
                    $payload = \str_repeat('.', 1024 * 1024 * 10); // 10 MiB
                    yield $this->endpoint->sendBinary($payload, $clientId);
                }
            });

            /** @var Connection $client */
            $client = yield connect('ws://localhost:' . $port . '/');

            /** @var Message $message */
            $message = yield $client->receive();
            $this->assertSame(\str_repeat('.', 1024 * 1024 * 10), yield $message->buffer());
        }));
    }

    public function testTooLongMessage() {
        wait(call(function () {
            $port = yield $this->createServer(new class extends WebsocketAdapter {
                public function onOpen(int $clientId, $handshakeData) {
                    $payload = \str_repeat('.', 1024 * 1024 * 10 + 1); // 10 MiB
                    yield $this->endpoint->sendBinary($payload, $clientId);
                }
            });

            /** @var Connection $client */
            $client = yield connect('ws://localhost:' . $port . '/');

            /** @var Message $message */
            $message = yield $client->receive();

            $this->expectException(WebSocketException::class);
            $this->expectExceptionMessage('The connection was closed: Received payload exceeds maximum allowable size');
            yield $message->buffer();
        }));
    }
}
