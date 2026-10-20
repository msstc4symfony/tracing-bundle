<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Application;

use InvalidArgumentException;
use Msstc4Symfony\TracingBundle\Application\CallerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallerName::class)]
final class CallerNameTest extends TestCase
{
    public function testJoinsApplicationAndComponent(): void
    {
        self::assertSame('shop:web', (new CallerName('shop', 'web'))->value);
    }

    public function testRejectsAnEmptyHalf(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CallerName('shop', '');
    }
}
