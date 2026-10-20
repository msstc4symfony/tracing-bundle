<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
final class SourceTest extends TestCase
{
    /**
     * 2.0 propagates W3C Trace Context and Baggage only.
     */
    public function testNoRequestIdIsLeftInTheSource(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            self::assertDoesNotMatchRegularExpression('/request[-_]?(id|from)/i', (string) file_get_contents($file->getPathname()), $file->getPathname());
        }
    }
}
