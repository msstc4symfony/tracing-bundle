<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Integration\Kernel;

use GuzzleHttp\Client;

final class GuzzleClientFactory
{
    public static function create(): Client
    {
        return new Client();
    }
}
