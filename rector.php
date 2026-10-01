<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\Symfony\Configs\Rector\Closure\FromServicePublicToDefaultsPublicRector;
use Rector\Symfony\Configs\Rector\Closure\ServiceSettersToSettersAutodiscoveryRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\CoversAnnotationWithValueToAttributeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withoutParallel()
    ->withPhpSets(php84: true)
    ->withComposerBased(doctrine: true, phpunit: true, symfony: true)
    ->withAttributesSets(symfony: true, doctrine: true, mongoDb: true, phpunit: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        carbon: false,
        rectorPreset: true,
        phpunitCodeQuality: false,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withSkip(
        [
            // Bundle service config registers optional integrations explicitly behind
            // interface_exists() guards: no public defaults, no class autodiscovery.
            FromServicePublicToDefaultsPublicRector::class,
            ServiceSettersToSettersAutodiscoveryRector::class,
            ClassPropertyAssignToConstructorPromotionRector::class,
            PostIncDecToPreIncDecRector::class,
            NewlineAfterStatementRector::class,
            CatchExceptionNameMatchingTypeRector::class,
            CoversAnnotationWithValueToAttributeRector::class,
        ],
    )
;
