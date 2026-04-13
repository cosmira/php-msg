<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Privatization\Rector\MethodCall\PrivatizeLocalGetterToPropertyRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;

return RectorConfig::configure()
    ->withPaths([
        'src/',
        'tests/',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        codingStyle: true,
        instanceOf: true,
    )
    ->withSets([
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::PHPUNIT_110
    ])
    ->withSkip([
        AddArrowFunctionReturnTypeRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        PrivatizeLocalGetterToPropertyRector::class,
        ClosureReturnTypeRector::class,
        ClosureToArrowFunctionRector::class,
        // SodaInitFileEmitter uses string FQCNs intentionally (::class would add Ce=33)
        // UselessVariableAnalyser uses strings intentionally (::class would add Ce)
        StringClassNameToClassConstantRector::class => [
            'src/Config/SodaInitFileEmitter.php',
            'src/Plugins/Rules/UselessVariable/UselessVariableAnalyser.php',
        ],
        // UselessVariableAnalyser: early-return conversion raises LCF; FQN ::class adds Ce
        ReturnBinaryOrToEarlyReturnRector::class => [
            'src/Plugins/Rules/UselessVariable/UselessVariableAnalyser.php',
        ],
        ClassOnObjectRector::class => [
            'src/Plugins/Rules/UselessVariable/UselessVariableAnalyser.php',
        ],
        AddArrayFunctionClosureParamTypeRector::class => [
            'src/Plugins/Rules/UselessVariable/UselessVariableAnalyser.php',
        ],
    ])
    ->withMemoryLimit('3G')
    ->withPhpSets(php83: true);
