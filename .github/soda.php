<?php

declare(strict_types=1);

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoDependencyCycles;

require __DIR__.'/SodaPolicyCheck.php';
$exceptions = json_decode(file_get_contents(__DIR__.'/soda-exceptions.json'), true, flags: JSON_THROW_ON_ERROR);
// Writer's internal allocation and encoding helpers belong to the same capability.
// Other namespace boundaries retain the standard rule's original granularity.
$modules = [
    'Cosmira\\OutlookMessage',
    'Cosmira\\OutlookMessage\\Exception',
    'Cosmira\\OutlookMessage\\CompoundFile',
    'Cosmira\\OutlookMessage\\CompoundFile\\Directory',
    'Cosmira\\OutlookMessage\\Mapi',
    'Cosmira\\OutlookMessage\\Mapi\\Decoding',
    'Cosmira\\OutlookMessage\\Rtf',
    'Cosmira\\OutlookMessage\\Rtf\\Decompressor',
    'Cosmira\\OutlookMessage\\Support',
    'Cosmira\\OutlookMessage\\Writer',
];
$checks = array_map(static function ($check) use ($exceptions, $modules) {
    $configured = $check instanceof NoDependencyCycles ? new NoDependencyCycles($modules) : $check;

    return new SodaPolicyCheck($configured, $exceptions);
}, RuleCatalog::standard());

return Soda::configure()
    ->withPaths([dirname(__DIR__).'/src'])
    // This Soda revision already includes the three PHPDoc rules in the standard catalog.
    ->with($checks);
