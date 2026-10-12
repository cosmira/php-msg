<?php

declare(strict_types=1);

use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Reporting\Violation;
use Cosmira\Soda\Rules\Check;

require dirname(__DIR__).'/.tools/soda/vendor/autoload.php';
require __DIR__.'/SodaPolicyCheck.php';

$exception = [
    'rule'          => 'fixture',
    'file'          => '.github/test-soda-policy.php',
    'class'         => 'Fixture',
    'method'        => 'Fixture::existing',
    'maximum'       => 3,
    'messagePrefix' => 'Reviewed contract',
    'reason'        => 'Exercise exact, bounded filtering of a reviewed finding.',
];
$path = __FILE__;
$allowed = new Violation('fixture', $path, 3, 2, 'Fixture::existing', 'Fixture', message: 'Reviewed contract');
$grown = new Violation('fixture', $path, 4, 2, 'Fixture::existing', 'Fixture', message: 'Reviewed contract');
$otherMethod = new Violation('fixture', $path, 3, 2, 'Fixture::newMethod', 'Fixture', message: 'Reviewed contract');
$otherClass = new Violation('fixture', $path, 3, 2, 'Fixture::existing', 'Another', message: 'Reviewed contract');
$otherFile = new Violation('fixture', $path.'.new', 3, 2, 'Fixture::existing', 'Fixture', message: 'Reviewed contract');
$otherMessage = new Violation('fixture', $path, 3, 2, 'Fixture::existing', 'Fixture', message: 'Unreviewed contract');

$check = new class([$allowed, $allowed, $grown, $otherMethod, $otherClass, $otherFile, $otherMessage]) extends Check
{
    public function __construct(private readonly array $findings) {}

    public function id(): string
    {
        return 'fixture';
    }

    public function requiredAnalyses(): array
    {
        return ['classes'];
    }

    public function checkProject(ProjectFacts $project): iterable
    {
        yield from $this->findings;
    }
};
$policy = new SodaPolicyCheck($check, [$exception]);
$remaining = iterator_to_array($policy->checkProject(new ProjectFacts));
if ($remaining !== [$allowed, $grown, $otherMethod, $otherClass, $otherFile, $otherMessage]) {
    throw new RuntimeException('Soda policy failed to retain new, grown or unrelated findings.');
}
if ($policy->id() !== 'fixture' || $policy->requiredAnalyses() !== ['classes']) {
    throw new RuntimeException('Soda policy failed to delegate rule identity and required collectors.');
}

$exceptions = json_decode(file_get_contents(__DIR__.'/soda-exceptions.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($exceptions as $entry) {
    if (! is_string($entry['reason'] ?? null) || trim($entry['reason']) === ''
        || ! is_int($entry['maximum'] ?? null) || $entry['maximum'] < 1
        || ! is_file(dirname(__DIR__).'/'.$entry['file'])) {
        throw new RuntimeException('Every Soda exception must name an existing file, a bound and a reason.');
    }
}
echo "Soda exception policy checks passed.\n";
