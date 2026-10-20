<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ServicesConfigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /**
     * Class names in the example configs must survive moves: a stale one fails only in the user's app.
     */
    public function testEveryClassNamedInTheExampleConfigsExists(): void
    {
        $names = [];
        $files = glob(self::ROOT . '/doc/*.yaml');
        foreach ($files === false ? [] : $files as $file) {
            preg_match_all('/Msstc4Symfony\\\\TracingBundle\\\\[\\\\A-Za-z0-9]+/', (string) file_get_contents($file), $matches);
            $names = [...$names, ...$matches[0]];
        }

        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertTrue(class_exists($name), $name);
        }
    }
}
