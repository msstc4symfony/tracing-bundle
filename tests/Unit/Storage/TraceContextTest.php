<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Storage;

use InvalidArgumentException;
use LogicException;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(TraceState::class)]
final class TraceContextTest extends TestCase
{
    public function testAcceptsAW3cSpanIdOrNoParent(): void
    {
        self::assertSame('b7ad6b7169203331', $this->context('b7ad6b7169203331')->parentSpanId);
        self::assertNull($this->context(null)->parentSpanId);
        self::assertNull((new TraceContext('runtime', null, null, null, null, null))->parentSpanId);
    }

    /**
     * @param non-empty-string $parentSpanId
     */
    #[DataProvider('invalidSpanIds')]
    public function testRejectsAParentThatIsNotAW3cSpanId(string $parentSpanId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Parent span id "%s" is not a W3C span id.', $parentSpanId));

        $this->context($parentSpanId);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidSpanIds(): iterable
    {
        yield 'all zero' => ['0000000000000000'];
        yield 'upper case' => ['B7AD6B7169203331'];
        yield 'too short' => ['b7ad6b716920333'];
        yield 'too long' => ['b7ad6b71692033310'];
        yield 'trailing newline' => ["b7ad6b7169203331\n"];
        yield 'leading newline' => ["\nb7ad6b7169203331"];
        yield 'not hex' => ['b7ad6b716920333g'];
    }

    public function testAChildSpanKeepsTheTraceAndHasThisSpanAsParent(): void
    {
        $span = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $remote = TraceParent::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-b7ad6b7169203331-01');
        self::assertInstanceOf(TraceParent::class, $span);
        $state = TraceState::fromHeaders(['rojo=1']);
        $context = new TraceContext('runtime', 'abc', 'gateway:api', $span, $remote, $state, 'b7ad6b7169203331');

        $child = $context->withChildSpan();

        self::assertInstanceOf(TraceParent::class, $child->traceParent);
        self::assertSame([$span->traceId, $span->flags], [$child->traceParent->traceId, $child->traceParent->flags]);
        self::assertNotSame($span->parentId, $child->traceParent->parentId);
        self::assertSame(
            ['runtime', 'abc', 'gateway:api', $remote, $state, '00f067aa0ba902b7'],
            [$child->runtimeId, $child->requestId, $child->requestFrom, $child->remoteTraceParent, $child->traceState, $child->parentSpanId],
        );
        self::assertSame($span, $context->traceParent);
    }

    public function testAChildSpanNeedsATrace(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A child span needs a traceParent; this context has none.');

        $this->context(null)->withChildSpan();
    }

    /**
     * @param non-empty-string|null $parentSpanId
     */
    private function context(?string $parentSpanId): TraceContext
    {
        return new TraceContext('runtime', null, null, null, null, null, $parentSpanId);
    }
}
