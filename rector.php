<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Foreach_\UnusedForeachValueToArrayKeysRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchMethodCallReturnTypeRector;
use Rector\Removing\Rector\FuncCall\RemoveFuncCallRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
                ->withPaths([
                    __DIR__.'/src',
                    __DIR__.'/config',
                    __DIR__.'/templates',
                ])
                ->withSkip([
                    __DIR__.'/src/Twig',
                    // __DIR__.'/src/Command',
                    // InlineClassRoutePrefixRector::class,
                    // NewMethodCallWithoutParenthesesRector::class,
                    UnusedForeachValueToArrayKeysRector::class,
                    RemoveUnusedForeachKeyRector::class,
                    RenameVariableToMatchMethodCallReturnTypeRector::class,
                    RenameParamToMatchTypeRector::class,
                    RenamePropertyToMatchTypeRector::class,
                    RenameForeachValueVariableToMatchMethodCallReturnTypeRector::class,
                    // RemoveUselessParamTagRector::class,
                    // RemoveUselessReturnTagRector::class
                    // SimplifyUselessVariableRector::class
                    RemoveFuncCallRector::class,
                    RenameVariableToMatchNewTypeRector::class,
                    NewlineBetweenClassLikeStmtsRector::class,
                    NewlineAfterStatementRector::class,
                    RenameForeachValueVariableToMatchExprVariableRector::class,
                ])
                ->withPreparedSets(
                    // deadCode: true,
                    // codeQuality: true,
                    codingStyle: true,
                    naming: true,
                    privatization: true,
                    // typeDeclarations: true,
                    // rectorPreset: true
                )
                ->withPhpSets(php85: true)
                ->withPhpVersion(PhpVersion::PHP_85)
                ->withAttributesSets(symfony: true, doctrine: true)
                ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
                ->withSets(
                    [
                        LevelSetList::UP_TO_PHP_85,
                    ]
                )
                ->withRules(
                    [
                        // ExplicitNullableParamTypeRector::class,
                        // AddOverrideAttributeToOverriddenMethodsRector::class,
                        // ReturnTypeFromStrictNativeCallRector::class
                    ]
                )
                ->withTypeCoverageLevel(50)
                ->withDeadCodeLevel(50)
                ->withCodeQualityLevel(50)
;
