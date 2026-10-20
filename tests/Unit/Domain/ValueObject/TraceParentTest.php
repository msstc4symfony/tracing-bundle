<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Domain\ValueObject;

use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vectors follow https://www.w3.org/TR/trace-context/#traceparent-header.
 */
#[CoversClass(TraceParent::class)]
final class TraceParentTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const SPAN_ID = '00f067aa0ba902b7';

    public function testParsesTheSpecExample(): void
    {
        $traceParent = TraceParent::fromHeader('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');

        self::assertInstanceOf(TraceParent::class, $traceParent);
        self::assertSame(self::TRACE_ID, $traceParent->traceId);
        self::assertSame(self::SPAN_ID, $traceParent->spanId);
        self::assertSame(1, $traceParent->flags);
        self::assertSame('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01', $traceParent->toHeader());
    }

    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function provideValidHeaders(): iterable
    {
        yield 'not sampled' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00', '0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', 0];
        yield 'sampled and random' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-03', self::TRACE_ID, self::SPAN_ID, 3];
        yield 'reserved flags are cleared' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-ff', self::TRACE_ID, self::SPAN_ID, 3];
        yield 'reserved flags alone' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-08', self::TRACE_ID, self::SPAN_ID, 0];
        yield 'surrounding whitespace' => [" \t00-" . self::TRACE_ID . '-' . self::SPAN_ID . "-01 \t", self::TRACE_ID, self::SPAN_ID, 1];
        yield 'future version' => ['cc-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01', self::TRACE_ID, self::SPAN_ID, 1];
        yield 'future version with more fields' => ['01-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01-what-the-future-will-bring', self::TRACE_ID, self::SPAN_ID, 1];
    }

    #[DataProvider('provideValidHeaders')]
    public function testParsesValidHeaders(string $header, string $traceId, string $spanId, int $flags): void
    {
        $traceParent = TraceParent::fromHeader($header);

        self::assertInstanceOf(TraceParent::class, $traceParent);
        self::assertSame([$traceId, $spanId, $flags], [$traceParent->traceId, $traceParent->spanId, $traceParent->flags]);
    }

    public function testFutureVersionIsPropagatedAsVersion00(): void
    {
        $traceParent = TraceParent::fromHeader('01-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01-extra');

        self::assertSame('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01', $traceParent?->toHeader());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'version ff' => ['ff-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01'];
        yield 'version 00 with more fields' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01-extra'];
        yield 'future version glued suffix' => ['01-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01x'];
        yield 'uppercase hex' => ['00-' . strtoupper(self::TRACE_ID) . '-' . self::SPAN_ID . '-01'];
        yield 'all-zero trace id' => ['00-00000000000000000000000000000000-' . self::SPAN_ID . '-01'];
        yield 'all-zero parent id' => ['00-' . self::TRACE_ID . '-0000000000000000-01'];
        yield 'short trace id' => ['00-' . substr(self::TRACE_ID, 1) . '-' . self::SPAN_ID . '-01'];
        yield 'short parent id' => ['00-' . self::TRACE_ID . '-' . substr(self::SPAN_ID, 1) . '-01'];
        yield 'missing flags' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID];
        yield 'non-hex flags' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-0g'];
        yield 'non-hex version' => ['0x-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01'];
        yield 'wrong delimiter' => ['00_' . self::TRACE_ID . '_' . self::SPAN_ID . '_01'];
        yield 'line break' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . "-01\n"];
        yield 'uuid instead of trace id' => ['00-4bf92f35-77b3-4da6-a3ce-929d0e0e4736-' . self::SPAN_ID . '-01'];
    }

    #[DataProvider('provideInvalidHeaders')]
    public function testRejectsInvalidHeaders(string $header): void
    {
        self::assertNull(TraceParent::fromHeader($header));
    }

    public function testStartsASampledTraceWithRandomIds(): void
    {
        $first = TraceParent::start();
        $second = TraceParent::start();

        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $first->toHeader());
        self::assertNotSame($first->traceId, $second->traceId);
        self::assertNotSame($first->spanId, $second->spanId);
        self::assertEquals($first, TraceParent::fromHeader($first->toHeader()));
    }

    public function testChildKeepsTheTraceAndFlagsWithANewSpanId(): void
    {
        $parent = TraceParent::fromHeader('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-00');
        self::assertInstanceOf(TraceParent::class, $parent);

        $child = $parent->child();

        self::assertSame(self::TRACE_ID, $child->traceId);
        self::assertSame(0, $child->flags);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $child->spanId);
        self::assertNotSame(self::SPAN_ID, $child->spanId);
        self::assertNotSame($child->spanId, $parent->child()->spanId);
    }

    public function testRecognisesW3cSpanIds(): void
    {
        self::assertTrue(TraceParent::isSpanId(self::SPAN_ID));
        self::assertTrue(TraceParent::isSpanId(TraceParent::start()->spanId));
        foreach (['', '0000000000000000', 'B7AD6B7169203331', 'b7ad6b716920333', 'b7ad6b71692033310', 'b7ad6b716920333g', "b7ad6b7169203331\n", "\nb7ad6b7169203331"] as $invalid) {
            self::assertFalse(TraceParent::isSpanId($invalid), $invalid);
        }
    }

    public function testHeaderNames(): void
    {
        self::assertSame('traceparent', TraceParent::HEADER);
        self::assertSame('traceresponse', TraceParent::RESPONSE_HEADER);
        self::assertSame('tracestate', TraceState::HEADER);
    }
}
