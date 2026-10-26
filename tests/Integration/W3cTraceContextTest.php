<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Monolog\Handler\TestHandler;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Msstc4Symfony\TracingBundle\Framework\Messenger\TraceStamp;
use Msstc4Symfony\TracingBundle\Framework\Sentry\TracingIntegration;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingGuzzleTransport;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\RecordingResponseFactory;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TracedMessage;
use Msstc4Symfony\TracingBundle\Test\Integration\Kernel\TracedMessageHandler;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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
 * W3C Trace Context and Baggage through the real kernel: incoming request -> outgoing HttpClient
 * call, dispatched -> consumed message, logs and Sentry. Always on, no configuration.
 */
final class W3cTraceContextTest extends KernelTestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const TRACE_PARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01';

    private const NAME_VARIABLES = ['APPLICATION_NAME', 'COMPONENT_NAME'];

    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        if (TestKernel::hasSentry()) {
            // The bound client holds this kernel's integration; later tests must not see it.
            SentrySdk::init();
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testIncomingTraceAndBaggageReachOutgoingHttpRequests(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/call', server: [
            'HTTP_TRACEPARENT' => self::TRACE_PARENT,
            'HTTP_TRACESTATE' => 'rojo=00f067aa0ba902b7',
            'HTTP_BAGGAGE' => 'msstc4symfony.caller=gateway%3Aweb,user=alice',
        ]));

        $sent = $this->sentHeaders();
        $outgoing = TraceParent::fromHeader($sent['traceparent'] ?? '');
        self::assertInstanceOf(TraceParent::class, $outgoing);
        self::assertSame(self::TRACE_ID, $outgoing->traceId);
        self::assertSame(TraceParent::FLAG_SAMPLED, $outgoing->flags);
        self::assertNotSame('00f067aa0ba902b7', $outgoing->spanId);
        self::assertSame('rojo=00f067aa0ba902b7', $sent['tracestate'] ?? null);
        self::assertSame('msstc4symfony.caller=unknown%3Aunknown,user=alice', $sent['baggage'] ?? null);
        self::assertSame($outgoing->toHeader(), $response->headers->get('traceresponse'));
        self::assertArrayNotHasKey('request-id', $sent);
    }

    public function testARatioOfZeroSendsNewTracesUnsampled(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_UNSAMPLED]);

        $kernel->handle(Request::create('/call'));

        $outgoing = TraceParent::fromHeader($this->sentHeaders()['traceparent'] ?? '');
        self::assertNotNull($outgoing);
        self::assertSame(TraceParent::FLAG_RANDOM, $outgoing->flags);
    }

    public function testANewTraceSampledBelowRatioOneSendsItsThreshold(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_NEARLY_ALWAYS_SAMPLED]);

        $kernel->handle(Request::create('/call'));

        $sent = $this->sentHeaders();
        $outgoing = TraceParent::fromHeader($sent['traceparent'] ?? '');
        self::assertNotNull($outgoing);
        self::assertSame(TraceParent::FLAG_SAMPLED | TraceParent::FLAG_RANDOM, $outgoing->flags);
        self::assertSame('ot=th:00000000000008', $sent['tracestate'] ?? null);
    }

    public function testANewTraceSendsTheRandomFlagAndNoTraceStateByDefault(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel();

        $kernel->handle(Request::create('/call'));

        $sent = $this->sentHeaders();
        self::assertSame(TraceParent::FLAG_SAMPLED | TraceParent::FLAG_RANDOM, TraceParent::fromHeader($sent['traceparent'] ?? '')?->flags);
        self::assertArrayNotHasKey('tracestate', $sent);
    }

    public function testARatioOfZeroKeepsTheCallersSampledFlag(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_UNSAMPLED]);

        $kernel->handle(Request::create('/call', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT]));

        $outgoing = TraceParent::fromHeader($this->sentHeaders()['traceparent'] ?? '');
        self::assertNotNull($outgoing);
        self::assertSame(TraceParent::FLAG_SAMPLED, $outgoing->flags);
    }

    public function testARatioOfZeroConsumesUnstampedMessagesUnsampled(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel(['environment' => TestKernel::ENV_UNSAMPLED]);

        $this->bus()->dispatch(new Envelope(new TracedMessage(), [new ReceivedStamp('async')]));

        self::assertSame(TraceParent::FLAG_RANDOM, $this->holder()->current()->traceParent->flags);
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
        $this->continueTrace(baggage: Baggage::fromHeaders(['user=alice']));

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $context = $this->holder()->current();
        self::assertNull($context->remoteTraceParent);
        self::assertNotSame(self::TRACE_ID, $context->traceParent->traceId);
        self::assertTrue($context->baggage->isEmpty());
    }

    public function testLogRecordsCarryTheW3cTrace(): void
    {
        $this->skipUnless(TestKernel::hasMonologBundle(), 'symfony/monolog-bundle');
        self::bootKernel();
        $this->continueTrace();

        $logger = self::getContainer()->get('test.logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('hello');

        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $extra = $handler->getRecords()[0]->extra;
        self::assertSame(self::TRACE_ID, $extra['trace_id'] ?? null);
        self::assertSame($this->holder()->current()->traceParent->spanId, $extra['span_id'] ?? null);
        self::assertSame('00f067aa0ba902b7', $extra['parent_span_id'] ?? null);
    }

    public function testSentryEventsAreTaggedWithTheW3cTrace(): void
    {
        $this->skipUnless(TestKernel::hasSentry(), 'sentry/sentry');
        self::bootKernel();
        $this->continueTrace();

        $tags = $this->sentryEventTags();

        self::assertSame(self::TRACE_ID, $tags['trace_id'] ?? null);
        self::assertSame($this->holder()->current()->traceParent->spanId, $tags['span_id'] ?? null);
    }

    public function testMessagesCarryTheTraceThroughTheSerializingTransportWithoutConfiguration(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel();
        $this->continueTrace(TraceState::fromHeaders(['rojo=1']), Baggage::fromHeaders(['user=alice']));

        $this->bus()->dispatch(new TracedMessage());

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $stamps = $sent[0]->all(TraceStamp::class);
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame('rojo=1', $stamp->traceState);
        self::assertSame('msstc4symfony.caller=unknown%3Aunknown,user=alice', $stamp->baggage);
        $stamped = TraceParent::fromHeader($stamp->traceParent);
        self::assertInstanceOf(TraceParent::class, $stamped);
        self::assertSame(self::TRACE_ID, $stamped->traceId);
        self::assertSame($this->holder()->current()->traceParent->spanId, $stamped->spanId);
    }

    public function testAMessageOfANewTraceSampledBelowRatioOneCarriesItsThreshold(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel(['environment' => TestKernel::ENV_NEARLY_ALWAYS_SAMPLED]);

        $this->bus()->dispatch(new TracedMessage());

        $stamp = $this->transport()->getSent()[0]?->last(TraceStamp::class);
        self::assertInstanceOf(TraceStamp::class, $stamp);
        self::assertSame('ot=th:00000000000008', $stamp->traceState);
    }

    public function testConsumerContinuesTheStampedTraceAndTheNextMessageStartsAnother(): void
    {
        $this->skipUnless(TestKernel::hasMessenger(), 'symfony/messenger');
        self::bootKernel();
        $this->continueTrace();
        $this->bus()->dispatch(new TracedMessage());
        $sent = $this->transport()->getSent()[0] ?? null;
        self::assertInstanceOf(Envelope::class, $sent);

        // As a worker consumes them: the sent message, then one from a sender without the stamp.
        $this->holder()->replace(TraceContext::start()->continuing(TraceParent::start(), null, Baggage::empty()));
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

    public function testCallerIsBuiltFromTheConfiguredNames(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_NAMED]);

        $kernel->handle(Request::create('/call'));

        self::assertSame('msstc4symfony.caller=shop%3Aapi', $this->sentHeaders()['baggage'] ?? null);
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

    public function testSendsTheBaggageToAListedHost(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_BAGGAGE_TO_EXAMPLE]);

        $kernel->handle(Request::create('/call', server: ['HTTP_BAGGAGE' => 'user=alice']));

        self::assertSame('msstc4symfony.caller=unknown%3Aunknown,user=alice', $this->sentHeaders()['baggage'] ?? null);
    }

    public function testSendsNoBaggageToAHostNotListed(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_BAGGAGE_TO_ORDERS]);

        $kernel->handle(Request::create('/call', server: ['HTTP_TRACEPARENT' => self::TRACE_PARENT, 'HTTP_BAGGAGE' => 'user=alice']));

        $sent = $this->sentHeaders();
        self::assertArrayNotHasKey('baggage', $sent);
        self::assertSame(self::TRACE_ID, TraceParent::fromHeader($sent['traceparent'] ?? '')?->traceId);
    }

    public function testARedirectToAHostNotListedGetsNoBaggage(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_BAGGAGE_TO_EXAMPLE]);
        $factory = self::getContainer()->get(RecordingResponseFactory::class);
        self::assertInstanceOf(RecordingResponseFactory::class, $factory);
        $factory->redirects['https://example.com/downstream'] = 'https://collector.test/x';

        $response = $kernel->handle(Request::create('/call', server: ['HTTP_BAGGAGE' => 'user=alice']));

        self::assertSame(200, $response->getStatusCode());
        self::assertContains('baggage: msstc4symfony.caller=unknown%3Aunknown,user=alice', $factory->headersByUrl['https://example.com/downstream'] ?? []);
        $redirected = $factory->headersByUrl['https://collector.test/x'] ?? self::fail('The redirect was not followed.');
        self::assertSame([], array_filter($redirected, static fn (string $line): bool => stripos($line, 'baggage:') === 0));
        self::assertNotSame([], array_filter($redirected, static fn (string $line): bool => stripos($line, 'traceparent:') === 0));
    }

    #[TestWith([TestKernel::ENV_BAGGAGE_TO_ORDERS, 'http://example.test/', false])]
    #[TestWith([TestKernel::ENV_BAGGAGE_TO_EXAMPLE, 'http://example.com/', true])]
    public function testGuzzleSendsTheBaggageToListedHostsOnly(string $environment, string $url, bool $sent): void
    {
        $this->skipUnless(TestKernel::hasGuzzle(), 'guzzlehttp/guzzle');
        self::bootKernel(['environment' => $environment]);
        $client = self::getContainer()->get(TestKernel::GUZZLE_CLIENT);
        self::assertInstanceOf(Client::class, $client);
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        $transport = new RecordingGuzzleTransport();
        $stack->setHandler($transport);

        $client->request('GET', $url);

        $request = $transport->lastRequest;
        self::assertNotNull($request);
        self::assertSame($sent, $request->hasHeader('baggage'));
        self::assertTrue($request->hasHeader('traceparent'));
    }

    public function testAnInvalidAllowedHostIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('msstc4symfony_tracing.baggage.allowed_hosts');

        self::bootKernel(['environment' => TestKernel::ENV_BAGGAGE_TO_INVALID]);
    }

    public function testCallerFallsBackToUnknownNames(): void
    {
        $this->skipUnless(TestKernel::hasHttpClient(), 'symfony/http-client');
        $saved = $this->unsetEnv(...self::NAME_VARIABLES);

        try {
            $kernel = self::bootKernel();
            $kernel->handle(Request::create('/call'));

            self::assertSame('msstc4symfony.caller=unknown%3Aunknown', $this->sentHeaders()['baggage'] ?? null);
        } finally {
            $this->restoreEnv($saved);
        }
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
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

        $event = (new Scope())->applyToEvent(Event::createEvent());
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

    private function holder(): TraceContextHolderInterface
    {
        $holder = self::getContainer()->get(TraceContextHolderInterface::class);
        self::assertInstanceOf(TraceContextHolderInterface::class, $holder);

        return $holder;
    }

    private function continueTrace(?TraceState $state = null, ?Baggage $baggage = null): void
    {
        $this->holder()->replace(TraceContext::start()->continuing($this->traceParent(), $state, $baggage ?? Baggage::empty()));
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
