<?php

use Rector\CodeQuality\Rector\Attribute\ExplicitAttributeNamedArgsRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return RectorConfig::configure()
                ->withPaths([
                    __DIR__ . '/src',
                ])
                ->withSkip([
                    // InlineClassRoutePrefixRector::class,
                    NewMethodCallWithoutParenthesesRector::class,
                    RemoveUnusedForeachKeyRector::class,
                    RemoveUselessParamTagRector::class,
                    RemoveUselessReturnTagRector::class,
                    NewlineBetweenClassLikeStmtsRector::class,
                    // gli attributi di PHPUnit sono @no-named-arguments: PHPStan rifiuta gli argomenti nominati
                    ExplicitAttributeNamedArgsRector::class => [__DIR__ . '/tests'],
                ])
                ->withCache(__DIR__ . '/.rector.cache')
                ->withPreparedSets(
                    deadCode: true,
                    codeQuality: true,
                    codingStyle: true,
                    typeDeclarations: true,
                    typeDeclarationDocblocks: true,
                    privatization: true,
                    namedArgs: true,
                    phpunitCodeQuality: true,
                    phpunitNarrowAsserts: true,
                    phpunitMockToStub: true,
                    doctrineCodeQuality: true,
                    symfonyCodeQuality: true,
                    symfonyConfigs: true,
                    rectorPreset: true,
                )
                ->withImportNames(importShortClasses: false, removeUnusedImports: true)
                ->withAttributesSets(phpunit: true)
                ->withAttributesSets(symfony: true, doctrine: true)
                ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
                ->withPhpSets()
;
