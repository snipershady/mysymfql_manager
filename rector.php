<?php

use Rector\CodeQuality\Rector\Class_\ConvertStaticToSelfRector;
use Rector\CodeQuality\Rector\New_\NewStaticToNewSelfRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
                ->withPaths([
                    __DIR__ . '/src/Entity',
                    __DIR__ . '/src/Enum',
                    __DIR__ . '/src/EventSubscriber',
                    __DIR__ . '/src/Form',
                    __DIR__ . '/src/Repository',
                    __DIR__ . '/src/Service',
                    __DIR__ . '/src/Controller',
                    // __DIR__ . '/config',
                    // __DIR__ . '/templates',
                ])
                ->withSkip([
                    __DIR__ . '/src/Command',
                    NewlineBeforeNewAssignSetRector::class,
                    NewMethodCallWithoutParenthesesRector::class,
                    RemoveUnusedForeachKeyRector::class,
                    RemoveUselessParamTagRector::class,
                    RemoveUselessReturnTagRector::class,
                    RenameVariableToMatchMethodCallReturnTypeRector::class,
                    RenameParamToMatchTypeRector::class,
                    RenameVariableToMatchNewTypeRector::class,
                    NewlineBetweenClassLikeStmtsRector::class,
                    NewlineAfterStatementRector::class,
                    RenamePropertyToMatchTypeRector::class,
                    RenameForeachValueVariableToMatchExprVariableRector::class,
                    // SimplifyUselessVariableRector::class
                    // Entity classes are Doctrine-mapped and may be relied upon for identity/inheritance semantics;
                    // keep them out of the treatClassesAsFinal-driven rewrites below.
                    ConvertStaticToSelfRector::class => [__DIR__ . '/src/Entity'],
                    NewStaticToNewSelfRector::class => [__DIR__ . '/src/Entity'],
                    RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/src/Entity'],
                    // ControllerMethodInjectionToConstructorRector::class, // injection nei controller per ora la disabilitiamo
                ])
                ->withCache(__DIR__ . '/.rector.cache')
                ->withPreparedSets(
                    deadCode: true,
                    codeQuality: true,
                    codingStyle: true,
                    typeDeclarations: true,
                    typeDeclarationDocblocks: true,
                    privatization: true,
                    naming: true,
                    namedArgs: true,
                    instanceOf: true,
                    earlyReturn: true,
                    phpunitCodeQuality: true,
                    phpunitNarrowAsserts: true,
                    phpunitMockToStub: true,
                )
                ->withSets([
                    SymfonySetList::SYMFONY_CODE_QUALITY,
                    DoctrineSetList::DOCTRINE_CODE_QUALITY,
                ])
                ->withAttributesSets(phpunit: true)
                ->withAttributesSets(symfony: true, doctrine: true)
                ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
                ->withSets(
                    [
                        LevelSetList::UP_TO_PHP_84,                    ]
                )
;
