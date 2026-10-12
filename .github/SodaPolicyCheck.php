<?php

declare(strict_types=1);

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Reporting\Violation;
use Cosmira\Soda\Rules\Check;

/**
 * Keep standard checks active while allowing reviewed, bounded compatibility exceptions.
 *
 * This adapter belongs to CI tooling and is never autoloaded by the library.
 */
final class SodaPolicyCheck extends Check
{
    /** @param list<array<string, mixed>> $exceptions */
    public function __construct(private readonly Check $check, private readonly array $exceptions) {}

    public function id(): string
    {
        return $this->check->id();
    }

    public function requiredAnalyses(): ?array
    {
        return $this->check->requiredAnalyses();
    }

    public function checkFile(FileFacts $file): iterable
    {
        yield from $this->review($this->check->checkFile($file));
    }

    public function checkProject(ProjectFacts $project): iterable
    {
        yield from $this->review($this->check->checkProject($project));
    }

    /** @param iterable<Violation> $violations
     * @return iterable<Violation>
     */
    private function review(iterable $violations): iterable
    {
        $uses = [];
        foreach ($violations as $violation) {
            $accepted = false;
            foreach ($this->exceptions as $index => $exception) {
                if (! $this->matches($violation, $exception)) {
                    continue;
                }
                $uses[$index] = ($uses[$index] ?? 0) + 1;
                if ($uses[$index] <= ($exception['occurrences'] ?? 1)) {
                    $accepted = true;
                    break;
                }
            }
            if (! $accepted) {
                yield $violation;
            }
        }
    }

    /** @param array<string, mixed> $exception */
    private function matches(Violation $violation, array $exception): bool
    {
        $relative = substr($violation->file, strlen(dirname(__DIR__).'/'));

        $source = ! isset($exception['sourceContains']) || $violation->line === null
            ? '' : (file($violation->file)[$violation->line - 1] ?? '');

        return $violation->rule === $exception['rule']
            && $relative === $exception['file']
            && $violation->class === ($exception['class'] ?? null)
            && $violation->method === ($exception['method'] ?? null)
            && $violation->value <= $exception['maximum']
            && (! isset($exception['message']) || $violation->message === $exception['message'])
            && (! isset($exception['messagePrefix'])
                || str_starts_with($violation->message ?? '', $exception['messagePrefix']))
            && (! isset($exception['sourceContains'])
                || str_contains($source, $exception['sourceContains']));
    }
}
