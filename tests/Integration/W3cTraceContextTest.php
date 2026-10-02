<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceContextStamp;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3cTraceContextInterface;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingResponseFactory;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TracedMessage;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TracedMessageHandler;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Service\ResetInterface;

/**
 * W3C Trace Context through the real kernel: incoming request -> outgoing HttpClient call,
 * dispatched -> consumed message, logs, and the configuration switches.
 */
final class W3cTraceContextTest extends KernelTestCase
{
    private const string TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const string TRACE_PARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01';

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

    public function testRequestIdIsDerivedFromAnIncomingTraceParent(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/ping', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $response->headers->get('request-id'));
        self::assertFalse($response->headers->has('traceresponse'));
    }

    public function testIncomingTraceReachesOutgoingHttpRequests(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/call', server: [
            'HTTP_REQUEST_ID' => 'abc',
            'HTTP_TRACEPARENT' => self::TRACE_PARENT,
            'HTTP_TRACESTATE' => 'rojo=00f067aa0ba902b7',
        ]));

        self::assertSame('abc', $response->headers->get('request-id'));
        $sent = $this->sentHeaders();
        self::assertSame('abc', $sent['request-id'] ?? null);
        $outgoing = TraceParent::fromHeader($sent['traceparent'] ?? '');
        self::assertInstanceOf(TraceParent::class, $outgoing);
        self::assertSame(self::TRACE_ID, $outgoing->traceId);
        self::assertSame(TraceParent::FLAG_SAMPLED, $outgoing->flags);
        self::assertNotSame('00f067aa0ba902b7', $outgoing->parentId);
        self::assertSame('rojo=00f067aa0ba902b7', $sent['tracestate'] ?? null);
    }

    public function testNextRequestDoesNotInheritTheTrace(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel();

        $kernel->handle(Request::create('/call', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT, 'HTTP_TRACESTATE' => 'rojo=1']));
        $kernel->handle(Request::create('/call'));

        $sent = $this->sentHeaders();
        $outgoing = TraceParent::fromHeader($sent['traceparent'] ?? '');
        self::assertInstanceOf(TraceParent::class, $outgoing);
        self::assertNotSame(self::TRACE_ID, $outgoing->traceId);
        self::assertArrayNotHasKey('tracestate', $sent);
    }

    public function testKernelResetForgetsTheW3cTrace(): void
    {
        self::bootKernel();
        $w3c = $this->w3c();
        $w3c->continueTrace($this->traceParent());

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertNull($w3c->getRemoteTraceParent());
        self::assertNotSame(self::TRACE_ID, $w3c->getTraceParent()->traceId);
    }

    public function testLogRecordsCarryTheW3cTrace(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');
        self::bootKernel();
        $this->w3c()->continueTrace($this->traceParent());

        $logger = self::getContainer()->get('test.logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('hello');

        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $extra = $handler->getRecords()[0]->extra;
        self::assertSame(self::TRACE_ID, $extra['trace_id'] ?? null);
        self::assertSame($this->w3c()->getTraceParent()->parentId, $extra['span_id'] ?? null);
    }

    public function testMessagesAreNotStampedByDefault(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel();
        $this->w3c()->continueTrace($this->traceParent());

        $this->bus()->dispatch(new TracedMessage());

        $sent = $this->transport()->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $sent);
        self::assertNull($sent->last(TraceContextStamp::class));
    }

    public function testMessagesCarryTheTraceToTheirConsumerAndTheNextMessageStartsAnother(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel(['environment' => TestKernel::ENV_W3C_MESSENGER]);
        $w3c = $this->w3c();
        $w3c->continueTrace($this->traceParent());

        $this->bus()->dispatch(new TracedMessage());
        $sent = $this->transport()->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $sent);
        $stamp = $sent->last(TraceContextStamp::class);
        self::assertInstanceOf(TraceContextStamp::class, $stamp);
        $stamped = TraceParent::fromHeader($stamp->traceParent);
        self::assertInstanceOf(TraceParent::class, $stamped);
        self::assertSame(self::TRACE_ID, $stamped->traceId);
        self::assertNotSame($w3c->getTraceParent()->parentId, $stamped->parentId);

        // As a worker consumes them: the sent message, then one from a sender without W3C.
        $w3c->continueTrace(TraceParent::start());
        $this->bus()->dispatch($sent->with(new ReceivedStamp('async')));
        $this->bus()->dispatch(new Envelope(new TracedMessage(), [new ReceivedStamp('async')]));

        $handler = self::getContainer()->get(TracedMessageHandler::class);
        self::assertInstanceOf(TracedMessageHandler::class, $handler);
        self::assertSame(self::TRACE_ID, $handler->traceIds[0] ?? null);
        self::assertNotSame(self::TRACE_ID, $handler->traceIds[1] ?? null);
    }

    public function testStampedMessagesAreReadByDefault(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel();

        $this->bus()->dispatch(new Envelope(new TracedMessage(), [new ReceivedStamp('async'), new TraceContextStamp(self::TRACE_PARENT)]));

        $handler = self::getContainer()->get(TracedMessageHandler::class);
        self::assertInstanceOf(TracedMessageHandler::class, $handler);
        self::assertSame([self::TRACE_ID], $handler->traceIds);
    }

    public function testTrueEnablesW3cWithoutStampingMessages(): void
    {
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_W3C_TRUE]);

        $response = $kernel->handle(Request::create('/ping', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $response->headers->get('request-id'));
        if (TestKernel::hasMessenger()) {
            $this->bus()->dispatch(new TracedMessage());
            self::assertNull($this->transport()->getSent()[0]?->last(TraceContextStamp::class));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDisabledEnvironments(): iterable
    {
        yield 'false' => [TestKernel::ENV_W3C_OFF];
        yield 'enabled: false wins over messenger: true' => [TestKernel::ENV_W3C_DISABLED_MAP];
    }

    #[DataProvider('provideDisabledEnvironments')]
    public function testW3cCanBeSwitchedOff(string $environment): void
    {
        $kernel = self::bootKernel(['environment' => $environment]);

        $response = $kernel->handle(Request::create('/ping', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        self::assertNotSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $response->headers->get('request-id'));
        self::assertFalse(self::getContainer()->has(W3cTraceContextInterface::class));
        $this->assertNoTraceParentIsSent($kernel);
        if (TestKernel::hasMessenger()) {
            $this->bus()->dispatch(new TracedMessage());
            self::assertNull($this->transport()->getSent()[0]?->last(TraceContextStamp::class));
        }
    }

    private function assertNoTraceParentIsSent(KernelInterface $kernel): void
    {
        if (!TestKernel::hasHttpClient()) {
            return;
        }

        $kernel->handle(Request::create('/call', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        $sent = $this->sentHeaders();
        self::assertArrayHasKey('request-id', $sent);
        self::assertArrayNotHasKey('traceparent', $sent);
    }

    private function skipUnless(bool $installed, string $package): void
    {
        if (!$installed) {
            self::markTestSkipped($package . ' is not installed');
        }
    }

    /**
     * @return array<string, string> lowercase name => value of the last outgoing request
     */
    private function sentHeaders(): array
    {
        $factory = self::getContainer()->get(RecordingResponseFactory::class);
        self::assertInstanceOf(RecordingResponseFactory::class, $factory);

        $headers = [];
        foreach ($factory->lastHeaders as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    private function traceParent(): TraceParent
    {
        $traceParent = TraceParent::fromHeader(self::TRACE_PARENT);
        self::assertInstanceOf(TraceParent::class, $traceParent);

        return $traceParent;
    }

    private function w3c(): W3cTraceContextInterface
    {
        $w3c = self::getContainer()->get(W3cTraceContextInterface::class);
        self::assertInstanceOf(W3cTraceContextInterface::class, $w3c);

        return $w3c;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('test.bus.commands');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('test.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
