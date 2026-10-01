<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Monolog\Handler\TestHandler;
use Msstc4Symfony\TracingBundle\GuzzleHttp\RequestIdGuzzleHandler;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingResponseFactory;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Boots Framework + Monolog + Tracing: catches wiring the unit tests cannot see.
 */
final class ContainerCompileTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testIncomingTraceIsReturnedInTheResponse(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/ping', server: ['HTTP_REQUEST_ID' => 'abc', 'HTTP_REQUEST_FROM' => 'billing:api']));

        self::assertSame('abc', $response->headers->get('request-id'));
    }

    public function testOutgoingHttpRequestCarriesTheTrace(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->storage()->setRequestId('abc');

        $client = $container->get('test.http_client');
        self::assertInstanceOf(HttpClientInterface::class, $client);
        $client->request('GET', 'https://example.com')->getStatusCode();

        $factory = $container->get(RecordingResponseFactory::class);
        self::assertInstanceOf(RecordingResponseFactory::class, $factory);
        self::assertContains('request-id: abc', $factory->lastHeaders);
    }

    public function testLogRecordsCarryTheTrace(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->storage()->setRequestId('abc');

        $logger = $container->get('test.logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('hello');

        $handler = $container->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertSame('abc', $handler->getRecords()[0]->extra['request_id'] ?? null);
    }

    public function testKernelResetForgetsTheTrace(): void
    {
        self::bootKernel();
        $storage = $this->storage()->setRequestId('abc');

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertNotSame('abc', $storage->getRequestId());
    }

    #[TestWith([TestKernel::GUZZLE_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_CHILD_CLIENT])]
    public function testGuzzleClientsGetTheTracingMiddleware(string $id): void
    {
        self::bootKernel();

        $client = self::getContainer()->get($id);
        self::assertInstanceOf(Client::class, $client);
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        self::assertStringContainsString(RequestIdGuzzleHandler::MIDDLEWARE_NAME, (string) $stack);
    }

    private function storage(): RequestIdServiceInterface
    {
        $storage = self::getContainer()->get(RequestIdServiceInterface::class);
        self::assertInstanceOf(RequestIdServiceInterface::class, $storage);

        return $storage;
    }
}
