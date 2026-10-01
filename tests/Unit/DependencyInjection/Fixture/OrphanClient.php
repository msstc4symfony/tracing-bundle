<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBundle\Test\Unit\DependencyInjection\Fixture;

use Not\Installed\BaseClient;

/**
 * A class whose parent comes from a package that is not installed: autoloading it is fatal.
 */
final class OrphanClient extends BaseClient
{
}
