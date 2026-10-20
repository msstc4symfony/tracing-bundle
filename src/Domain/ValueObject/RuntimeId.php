<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Runtime id of a unit of work: a version 4 UUID, as in 1.x logs.
 *
 * @internal
 */
final class RuntimeId
{
    /**
     * @return non-empty-string
     */
    public static function generate(): string
    {
        return self::fromBytes(random_bytes(16));
    }

    /**
     * @return non-empty-string
     *
     * @throws InvalidArgumentException when $bytes is not 16 bytes long
     */
    public static function fromBytes(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new InvalidArgumentException(sprintf('A runtime id needs 16 bytes, %d given.', strlen($bytes)));
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
