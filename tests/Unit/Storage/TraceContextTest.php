<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Storage;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContext::class)]
#[UsesClass(TraceParent::class)]
final class TraceContextTest extends TestCase
{
    public function testAcceptsAW3cSpanIdOrNoParent(): void
    {
        self::assertSame('b7ad6b7169203331', $this->context('b7ad6b7169203331')->parentSpanId);
        self::assertNull($this->context(null)->parentSpanId);
        self::assertNull(new TraceContext('runtime', null, null, null, null, null)->parentSpanId);
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

    /**
     * @param non-empty-string|null $parentSpanId
     */
    private function context(?string $parentSpanId): TraceContext
    {
        return new TraceContext('runtime', null, null, null, null, null, $parentSpanId);
    }
}
