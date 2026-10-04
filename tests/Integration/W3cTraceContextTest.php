<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\TracingBundle\Messenger\Stamp\TraceStamp;
use Msstc4Symfony\TracingBundle\Sentry\Integration\TracingIntegration;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingResponseFactory;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TracedMessage;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TracedMessageHandler;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Service\ResetInterface;

/**
 * W3C Trace Context through the real kernel: incoming request -> outgoing HttpClient call,
 * dispatched -> consumed message, logs and Sentry. Always on, no configuration.
 */
final class W3cTraceContextTest extends KernelTestCase
{
    private const string TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const string TRACE_PARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01';

    private const array NAME_VARIABLES = ['APPLICATION_NAME', 'COMPONENT_NAME'];

    #[Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        if (TestKernel::hasSentry()) {
            // The bound client holds this kernel's integration; later tests must not see it.
            SentrySdk::init();
        }

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
        $storage = $this->storage();
        $storage->continueTrace($this->traceParent());

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertNull($storage->getRemoteTraceParent());
        self::assertNotSame(self::TRACE_ID, $storage->getTraceParent()->traceId);
    }

    public function testLogRecordsCarryTheW3cTrace(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');
        self::bootKernel();
        $this->storage()->continueTrace($this->traceParent());

        $logger = self::getContainer()->get('test.logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('hello');

        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $extra = $handler->getRecords()[0]->extra;
        self::assertSame(self::TRACE_ID, $extra['trace_id'] ?? null);
        self::assertSame($this->storage()->getTraceParent()->parentId, $extra['span_id'] ?? null);
    }

    public function testSentryEventsAreTaggedWithTheW3cTrace(): void
    {
        $this->skipUnless(TestKernel::hasSentry(), 'sentry/sentry');
        self::bootKernel();
        $this->storage()->continueTrace($this->traceParent());

        $tags = $this->sentryEventTags();

        self::assertSame(self::TRACE_ID, $tags['trace_id'] ?? null);
        self::assertSame($this->storage()->getTraceParent()->parentId, $tags['span_id'] ?? null);
    }

    public function testMessagesCarryTheTraceThroughTheSerializingTransportWithoutConfiguration(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel();
        $storage = $this->storage();
        $storage->setRequestId('abc')->continueTrace($this->traceParent(), TraceState::fromHeaders(['rojo=1']));

        $this->bus()->dispatch(new TracedMessage());

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $stamps = $sent[0]->all(TraceStamp::class);
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame('abc', $stamp->requestId);
        self::assertSame($storage->getCurrentRequestFrom(), $stamp->requestFrom);
        self::assertSame('rojo=1', $stamp->traceState);
        $stamped = TraceParent::fromHeader($stamp->traceParent);
        self::assertInstanceOf(TraceParent::class, $stamped);
        self::assertSame(self::TRACE_ID, $stamped->traceId);
        self::assertNotSame($storage->getTraceParent()->parentId, $stamped->parentId);
    }

    public function testConsumerContinuesTheStampedTraceAndTheNextMessageStartsAnother(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel();
        $storage = $this->storage();
        $storage->continueTrace($this->traceParent());
        $this->bus()->dispatch(new TracedMessage());
        $sent = $this->transport()->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $sent);

        // As a worker consumes them: the sent message, then one from a sender without the stamp.
        $storage->continueTrace(TraceParent::start());
        $this->bus()->dispatch($sent->with(new ReceivedStamp('async')));
        $this->bus()->dispatch(new Envelope(new TracedMessage(), [new ReceivedStamp('async')]));

        $handler = self::getContainer()->get(TracedMessageHandler::class);
        self::assertInstanceOf(TracedMessageHandler::class, $handler);
        self::assertSame(self::TRACE_ID, $handler->traceIds[0] ?? null);
        self::assertNotSame(self::TRACE_ID, $handler->traceIds[1] ?? null);
    }

    public function testTheRemovedW3cSwitchIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('w3c_trace_context');

        self::bootKernel(['environment' => TestKernel::ENV_LEGACY_W3C]);
    }

    public function testRequestFromIsBuiltFromTheConfiguredNames(): void
    {
        self::bootKernel(['environment' => TestKernel::ENV_NAMED]);

        self::assertSame('shop:api', $this->storage()->getCurrentRequestFrom());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonStringNameEnvironments(): iterable
    {
        yield 'bool application_name' => [TestKernel::ENV_BOOL_NAME];
        yield 'int component_name' => [TestKernel::ENV_INT_NAME];
    }

    #[DataProvider('provideNonStringNameEnvironments')]
    public function testNonStringNamesAreRejected(string $environment): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::bootKernel(['environment' => $environment]);
    }

    public function testRequestFromFallsBackToUnknownNames(): void
    {
        $saved = $this->unsetEnv(...self::NAME_VARIABLES);

        try {
            self::bootKernel();

            self::assertSame('unknown:unknown', $this->storage()->getCurrentRequestFrom());
        } finally {
            $this->restoreEnv($saved);
        }
    }

    /**
     * @param non-empty-string ...$names
     *
     * @return array<non-empty-string, array{env: mixed, server: mixed, getenv: string|false}>
     */
    private function unsetEnv(string ...$names): array
    {
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'getenv' => getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        return $saved;
    }

    /**
     * @param array<non-empty-string, array{env: mixed, server: mixed, getenv: string|false}> $saved
     */
    private function restoreEnv(array $saved): void
    {
        foreach ($saved as $name => $values) {
            if ($values['env'] !== null) {
                $_ENV[$name] = $values['env'];
            }
            if ($values['server'] !== null) {
                $_SERVER[$name] = $values['server'];
            }
            if ($values['getenv'] !== false) {
                putenv($name . '=' . $values['getenv']);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function sentryEventTags(): array
    {
        $integration = self::getContainer()->get(TestKernel::SENTRY_INTEGRATION);
        self::assertInstanceOf(TracingIntegration::class, $integration);
        SentrySdk::init()->bindClient(
            ClientBuilder::create(['integrations' => [$integration], 'default_integrations' => false])->getClient(),
        );

        $event = new Scope()->applyToEvent(Event::createEvent());
        self::assertInstanceOf(Event::class, $event);

        return $event->getTags();
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

    private function storage(): RequestIdServiceInterface
    {
        $storage = self::getContainer()->get(RequestIdServiceInterface::class);
        self::assertInstanceOf(RequestIdServiceInterface::class, $storage);

        return $storage;
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
