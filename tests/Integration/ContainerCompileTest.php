<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use IteratorAggregate;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Msstc4Symfony\TracingBundle\Application\Port\OutgoingSpanReservationInterface;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Framework\Messenger\WorkerTraceSubscriber;
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
use Symfony\Component\HttpFoundation\Response;
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
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const TRACE_PARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01';

    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testTraceResponseNamesTheServerSpanOfTheIncomingTrace(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/ping', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        $traceResponse = TraceParent::fromHeader((string) $response->headers->get('traceresponse'));
        self::assertInstanceOf(TraceParent::class, $traceResponse);
        self::assertSame(self::TRACE_ID, $traceResponse->traceId);
        self::assertNotSame('00f067aa0ba902b7', $traceResponse->spanId);
        self::assertFalse($response->headers->has('request-id'));
    }

    // Contract of the decoration registry: the decorator sits outside FrameworkBundle's mock_response_factory.
    public function testOutgoingHttpRequestCarriesTheTrace(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');

        self::bootKernel();
        $container = self::getContainer();
        $known = $this->knownTrace();

        $client = $container->get('test.http_client');
        self::assertInstanceOf(HttpClientInterface::class, $client);
        $client->request('GET', 'https://example.com')->getStatusCode();

        $factory = $container->get(RecordingResponseFactory::class);
        self::assertInstanceOf(RecordingResponseFactory::class, $factory);
        self::assertContains('traceparent: ' . $known->traceParent->toHeader(), $factory->lastHeaders);
        self::assertContains('baggage: msstc4symfony.caller=unknown%3Aunknown', $factory->lastHeaders);
    }

    public function testOutgoingHttpRequestCarriesTheReservedSpan(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');

        self::bootKernel();
        $container = self::getContainer();
        $reservation = $container->get(OutgoingSpanReservationInterface::class);
        self::assertInstanceOf(OutgoingSpanReservationInterface::class, $reservation);
        $reserved = $reservation->reserve();

        $client = $container->get('test.http_client');
        self::assertInstanceOf(HttpClientInterface::class, $client);
        $client->request('GET', 'https://example.com')->getStatusCode();

        $factory = $container->get(RecordingResponseFactory::class);
        self::assertInstanceOf(RecordingResponseFactory::class, $factory);
        self::assertContains('traceparent: ' . $reserved->traceParent->toHeader(), $factory->lastHeaders);
    }

    public function testGuzzleClientsSendTheReservedSpan(): void
    {
        $this->skipUnless(TestKernel::hasGuzzle(), 'guzzlehttp/guzzle');

        self::bootKernel();
        $reservation = self::getContainer()->get(OutgoingSpanReservationInterface::class);
        self::assertInstanceOf(OutgoingSpanReservationInterface::class, $reservation);
        $reserved = $reservation->reserve();
        $client = self::getContainer()->get(TestKernel::GUZZLE_CLIENT);
        self::assertInstanceOf(Client::class, $client);
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        $transport = new RecordingGuzzleTransport();
        $stack->setHandler($transport);

        $client->request('GET', 'https://example.com');

        self::assertSame([$reserved->traceParent->toHeader()], $transport->lastRequest?->getHeader('traceparent'));
    }

    public function testKernelResetDropsTheReservation(): void
    {
        self::bootKernel();
        $reservation = self::getContainer()->get(OutgoingSpanReservationInterface::class);
        self::assertInstanceOf(OutgoingSpanReservationInterface::class, $reservation);
        $reservation->reserve();
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);

        $resetter->reset();

        self::assertNull($reservation->take());
    }

    public function testLogRecordsCarryTheTrace(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');

        self::bootKernel();
        $container = self::getContainer();
        $known = $this->knownTrace();

        $logger = $container->get('test.logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('hello');

        $handler = $container->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $extra = $handler->getRecords()[0]->extra;
        self::assertSame(self::TRACE_ID, $extra['trace_id'] ?? null);
        self::assertSame($known->traceParent->spanId, $extra['span_id'] ?? null);
        self::assertSame('00f067aa0ba902b7', $extra['parent_span_id'] ?? null);
        self::assertSame('gateway:web', $extra['caller'] ?? null);
    }

    public function testLogsBeforeTheTracingListenerShareTheRequestTrace(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');

        $kernel = self::bootKernel();

        $this->assertEarlyLogSharesTheTrace($kernel->handle(Request::create('/ping')));
    }

    public function testLogsBeforeTheTracingListenerShareTheTraceOfTheNextRequest(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');

        $kernel = self::bootKernel();
        $first = $kernel->handle(Request::create('/ping'));
        // The second handle() runs kernel.reset first, which also clears the log handler.
        $second = $kernel->handle(Request::create('/ping'));

        self::assertNotSame($first->headers->get('traceresponse'), $second->headers->get('traceresponse'));
        $this->assertEarlyLogSharesTheTrace($second);
    }

    public function testKernelResetForgetsTheTrace(): void
    {
        self::bootKernel();
        $known = $this->knownTrace();

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertNotSame($known->runtimeId, $this->holder()->current()->runtimeId);
        self::assertNotSame(self::TRACE_ID, $this->holder()->current()->traceParent->traceId);
    }

    #[TestWith([TestKernel::GUZZLE_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_CHILD_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_CONFIGURED_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_STACK_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_FACTORY_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_FACTORY_CONFIGURED_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_POOLED_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_POOLED_CONFIGURED_CLIENT])]
    #[TestWith([TestKernel::GUZZLE_NON_MULTIPLEXING_CLIENT])]
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
    #[TestWith([TestKernel::GUZZLE_POOLED_CONFIGURED_CLIENT])]
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
        $middleware = (new ReflectionProperty($subscriber, 'middleware'))->getValue($subscriber);
        foreach (['test.bus.commands', 'test.bus.events'] as $busId) {
            self::assertContains($middleware, $this->middlewareOf($busId), $busId);
        }
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
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
        $aggregate = (new ReflectionProperty($bus, 'middlewareAggregate'))->getValue($bus);
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
        $known = $this->knownTrace();

        $client->request('GET', 'http://example.test/');

        self::assertSame([$known->traceParent->toHeader()], $transport->lastRequest?->getHeader('traceparent'));
    }

    private function holder(): TraceContextHolderInterface
    {
        $holder = self::getContainer()->get(TraceContextHolderInterface::class);
        self::assertInstanceOf(TraceContextHolderInterface::class, $holder);

        return $holder;
    }

    /**
     * Puts a trace received from "gateway:web" into the holder.
     */
    private function knownTrace(): TraceContext
    {
        $received = TraceParent::fromHeader(self::TRACE_PARENT);
        self::assertInstanceOf(TraceParent::class, $received);
        $context = TraceContext::start()->continuing($received, null, Baggage::fromHeaders(['msstc4symfony.caller=gateway%3Aweb']));
        $this->holder()->replace($context);

        return $context;
    }

    private function assertEarlyLogSharesTheTrace(Response $response): void
    {
        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $records = $handler->getRecords();
        $early = array_find($records, static fn (LogRecord $record): bool => $record->message === EarlyLogListener::MESSAGE);
        self::assertInstanceOf(LogRecord::class, $early);
        self::assertSame(TraceParent::fromHeader((string) $response->headers->get('traceresponse'))?->traceId, $early->extra['trace_id'] ?? null);
        $last = $records[array_key_last($records)];
        self::assertNotSame($early, $last);
        foreach (['runtime_id', 'trace_id', 'span_id'] as $key) {
            self::assertSame($last->extra[$key] ?? null, $early->extra[$key] ?? null, $key);
        }
    }
}
