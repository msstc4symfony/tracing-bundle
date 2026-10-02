<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use IteratorAggregate;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Messenger\EventListener\WorkerTraceSubscriber;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\AppGuzzleConfigurator;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\EarlyLogListener;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\GuzzleConsumer;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingGuzzleTransport;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingResponseFactory;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Boots Framework + Tracing with whichever optional integrations are installed: catches
 * wiring the unit tests cannot see.
 */
final class ContainerCompileTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
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
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');

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
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');

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

    public function testLogsBeforeTheTracingListenerShareTheRequestTrace(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');

        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/ping'));

        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $records = $handler->getRecords();
        $early = array_find($records, static fn (LogRecord $record): bool => $record->message === EarlyLogListener::MESSAGE);
        self::assertInstanceOf(LogRecord::class, $early);
        self::assertSame($response->headers->get('request-id'), $early->extra['request_id'] ?? null);
        $last = $records[array_key_last($records)];
        self::assertNotSame($early, $last);
        foreach (['runtime_id', 'request_id', 'trace_id'] as $key) {
            self::assertSame($last->extra[$key] ?? null, $early->extra[$key] ?? null, $key);
        }
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
    #[TestWith([TestKernel::GUZZLE_CONFIGURED_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_STACK_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_FACTORY_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_FACTORY_CONFIGURED_CLIENT])]
    public function testGuzzleClientsSendTheTrace(string $id): void
    {
        $this->skipUnless(TestKernel::hasGuzzle(), 'guzzlehttp/guzzle');

        self::bootKernel();
        $client = self::getContainer()->get($id);
        self::assertInstanceOf(Client::class, $client);

        $this->assertSendsTheTrace($client);
    }

    public function testInlinedGuzzleClientSendsTheTrace(): void
    {
        $this->skipUnless(TestKernel::hasGuzzle(), 'guzzlehttp/guzzle');

        self::bootKernel();
        $consumer = self::getContainer()->get(TestKernel::GUZZLE_CONSUMER);
        self::assertInstanceOf(GuzzleConsumer::class, $consumer);
        self::assertInstanceOf(Client::class, $consumer->client);

        $this->assertSendsTheTrace($consumer->client);
    }

    #[TestWith([TestKernel::GUZZLE_CONFIGURED_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_FACTORY_CONFIGURED_CLIENT])]
    public function testGuzzleClientsKeepTheirOwnConfigurator(string $id): void
    {
        $this->skipUnless(TestKernel::hasGuzzle(), 'guzzlehttp/guzzle');

        self::bootKernel();
        $client = self::getContainer()->get($id);

        $configurator = self::getContainer()->get(AppGuzzleConfigurator::class);
        self::assertInstanceOf(AppGuzzleConfigurator::class, $configurator);
        self::assertSame([$client], $configurator->configured);
    }

    public function testGuzzleClientKeepsItsOwnConfig(): void
    {
        $this->skipUnless(TestKernel::hasGuzzle(), 'guzzlehttp/guzzle');

        self::bootKernel();
        $client = self::getContainer()->get(TestKernel::GUZZLE_STACK_CLIENT);
        self::assertInstanceOf(Client::class, $client);

        self::assertSame(3, $client->getConfig('timeout'));
        self::assertSame(self::getContainer()->get('test.guzzle.stack.public'), $client->getConfig('handler'));
    }

    public function testWorkerSubscriberSharesTheMiddlewareOfEveryBus(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');

        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $subscriber = null;
        foreach ([WorkerMessageReceivedEvent::class, WorkerRunningEvent::class, WorkerStoppedEvent::class] as $event) {
            $found = array_values(array_filter(
                $dispatcher->getListeners($event),
                static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof WorkerTraceSubscriber,
            ));
            self::assertCount(1, $found, $event);
            $subscriber = $found[0][0];
        }

        self::assertInstanceOf(WorkerTraceSubscriber::class, $subscriber);
        $middleware = new ReflectionProperty($subscriber, 'middleware')->getValue($subscriber);
        foreach (['test.bus.commands', 'test.bus.events'] as $busId) {
            self::assertContains($middleware, $this->middlewareOf($busId), $busId);
        }
    }

    private function skipUnless(bool $installed, string $package): void
    {
        if (!$installed) {
            self::markTestSkipped($package . ' is not installed');
        }
    }

    /**
     * @return list<object>
     */
    private function middlewareOf(string $busId): array
    {
        $bus = self::getContainer()->get($busId);
        self::assertInstanceOf(MessageBus::class, $bus);
        $aggregate = new ReflectionProperty($bus, 'middlewareAggregate')->getValue($bus);
        self::assertInstanceOf(IteratorAggregate::class, $aggregate);

        $middleware = [];
        foreach ($aggregate as $item) {
            self::assertIsObject($item);
            $middleware[] = $item;
        }

        return $middleware;
    }

    // Swaps the transport under the client's stack, so the request goes through every middleware.
    private function assertSendsTheTrace(Client $client): void
    {
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        $transport = new RecordingGuzzleTransport();
        $stack->setHandler($transport);
        $this->storage()->setRequestId('abc');

        $client->request('GET', 'http://example.test/');

        self::assertSame(['abc'], $transport->lastRequest?->getHeader('request-id'));
    }

    private function storage(): RequestIdServiceInterface
    {
        $storage = self::getContainer()->get(RequestIdServiceInterface::class);
        self::assertInstanceOf(RequestIdServiceInterface::class, $storage);

        return $storage;
    }
}
