<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires an explicit semantic version for a manual release', function (): void {
    $dispatch = releaseWorkflowSection(releaseWorkflowSection(releaseWorkflow(), 'on'), 'workflow_dispatch');

    expect(releaseWorkflowSection($dispatch, 'inputs')['version'] ?? null)->toBe([
        'description' => 'Semantic version to tag and release',
        'required' => true,
        'type' => 'string',
    ]);
});

it('runs on pull requests and on pushes to main', function (): void {
    $triggers = releaseWorkflowSection(releaseWorkflow(), 'on');

    expect($triggers['push'] ?? null)->toBe(['branches' => ['main']])
        ->and(array_key_exists('pull_request', $triggers))->toBeTrue();
});

it('cancels superseded pull request runs but never main or release runs', function (): void {
    expect(releaseWorkflow()['concurrency'] ?? null)->toBe([
        'group' => "\${{ github.event_name == 'pull_request' && format('tests-{0}', github.ref) || format('tests-{0}', github.sha) }}",
        'cancel-in-progress' => "\${{ github.event_name == 'pull_request' }}",
    ]);
});

it('gates merges on a single aggregate CI check', function (): void {
    $ci = releaseWorkflowJob('ci');

    expect($ci['name'] ?? null)->toBe('CI')
        ->and($ci['if'] ?? null)->toBe('always()')
        ->and($ci['needs'] ?? null)->toBe(['compatibility', 'retained-job-redis', 'audit', 'tests'])
        ->and(releaseWorkflowRuns('ci'))->toContain('if [[ "$result" != "success" ]]; then');
});

it('audits dependencies in a dedicated job', function (): void {
    expect(releaseWorkflowRuns('audit'))
        ->toContain('composer audit --locked --no-interaction')
        ->toContain('bun audit')
        ->and(releaseWorkflowRuns('tests'))
        ->not->toContain('composer audit')
        ->not->toContain('bun audit');
});

it('runs the complete test suite in CI without test impact analysis', function (): void {
    $runs = releaseWorkflowRuns('tests')
        .releaseWorkflowRuns('compatibility')
        .releaseWorkflowRuns('retained-job-redis');

    expect($runs)->not->toContain('--tia');
});

it('releases only after CI passes on main', function (): void {
    $release = releaseWorkflowJob('release');

    expect($release['needs'] ?? null)->toBe(['ci'])
        ->and($release['if'] ?? null)
        ->toBe("github.ref == 'refs/heads/main' && (github.event_name == 'push' || github.event_name == 'workflow_dispatch')")
        ->and($release['permissions'] ?? null)->toBe(['contents' => 'write', 'pull-requests' => 'read']);
});

it('derives the next version from the release label of the merged pull request', function (): void {
    expect(releaseStep('Resolve release version')['run'] ?? null)->toBeString()
        ->toContain('commits/${GITHUB_SHA}/pulls')
        ->toContain('for candidate in major minor patch; do')
        ->toContain('major) version="$((major + 1)).0.0" ;;')
        ->toContain('minor) version="${major}.$((minor + 1)).0" ;;')
        ->toContain('patch) version="${major}.${minor}.$((patch + 1))" ;;')
        ->toContain('echo "release=false" >> "$GITHUB_OUTPUT"');
});

it('can resume a release only when the existing tag belongs to the released commit', function (): void {
    $validation = releaseStep('Validate release version');
    $tagCreation = releaseStep('Create and push tag');
    $releaseCreation = releaseStep('Create GitHub release');

    expect($validation['run'] ?? null)->toBeString()
        ->toContain('tag_commit="$(git rev-list -n 1 "refs/tags/$RELEASE_VERSION")"')
        ->toContain('[[ "$tag_commit" != "$GITHUB_SHA" ]]')
        ->and($tagCreation['run'] ?? null)->toBeString()
        ->toContain('if git rev-parse --verify --quiet "refs/tags/$RELEASE_VERSION"; then')
        ->toContain('exit 0')
        ->and($releaseCreation['run'] ?? null)->toBeString()
        ->toContain('if gh release view "$RELEASE_VERSION" >/dev/null 2>&1; then')
        ->toContain('exit 0');
});

it('publishes the Pest TIA baseline from main', function (): void {
    $triggers = releaseWorkflowSection(releaseWorkflow('tia-baseline.yml'), 'on');
    $upload = [];

    foreach (releaseWorkflowSteps('baseline', 'tia-baseline.yml') as $step) {
        if (($step['name'] ?? null) === 'Upload the baseline') {
            $upload = $step;
        }
    }

    expect($triggers['push'] ?? null)->toBe(['branches' => ['main']])
        ->and(releaseWorkflowRuns('baseline', 'tia-baseline.yml'))->toContain('--tia --fresh')
        ->and($upload['with'] ?? null)->toMatchArray([
            'name' => 'pest-tia-baseline',
            'include-hidden-files' => true,
        ]);
});

it('pins every external action to a full commit sha', function (string $file): void {
    $references = [];

    foreach (array_keys(releaseWorkflowSection(releaseWorkflow($file), 'jobs')) as $job) {
        foreach (releaseWorkflowSteps($job, $file) as $step) {
            $uses = $step['uses'] ?? null;

            if (is_string($uses)) {
                $references[] = $uses;
            }
        }
    }

    expect($references)->not->toBeEmpty();

    foreach ($references as $reference) {
        expect($reference)->toMatch('/\A[^@]+@[a-f0-9]{40}\z/');
    }
})->with(['tests.yml', 'tia-baseline.yml']);

/** @return array<string, mixed> */
function releaseWorkflow(string $file = 'tests.yml'): array
{
    $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/'.$file);

    if (! is_array($workflow)) {
        throw new RuntimeException("The {$file} workflow must contain a YAML mapping.");
    }

    return releaseWorkflowMapping($workflow);
}

/**
 * @param  array<mixed>  $value
 * @return array<string, mixed>
 */
function releaseWorkflowMapping(array $value): array
{
    $mapping = [];

    foreach ($value as $key => $item) {
        $mapping[(string) $key] = $item;
    }

    return $mapping;
}

/**
 * @param  array<string, mixed>  $mapping
 * @return array<string, mixed>
 */
function releaseWorkflowSection(array $mapping, string $key): array
{
    $section = $mapping[$key] ?? null;

    if (! is_array($section)) {
        throw new RuntimeException("The workflow section [{$key}] must be a mapping.");
    }

    return releaseWorkflowMapping($section);
}

/** @return array<string, mixed> */
function releaseWorkflowJob(string $job, string $file = 'tests.yml'): array
{
    return releaseWorkflowSection(releaseWorkflowSection(releaseWorkflow($file), 'jobs'), $job);
}

/** @return list<array<string, mixed>> */
function releaseWorkflowSteps(string $job, string $file = 'tests.yml'): array
{
    $steps = releaseWorkflowJob($job, $file)['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException("The {$job} job must define steps.");
    }

    $normalized = [];

    foreach ($steps as $step) {
        if (is_array($step)) {
            $normalized[] = releaseWorkflowMapping($step);
        }
    }

    return $normalized;
}

function releaseWorkflowRuns(string $job, string $file = 'tests.yml'): string
{
    $runs = [];

    foreach (releaseWorkflowSteps($job, $file) as $step) {
        $run = $step['run'] ?? null;

        if (is_string($run)) {
            $runs[] = $run;
        }
    }

    return implode("\n", $runs);
}

/** @return array<string, mixed> */
function releaseStep(string $name): array
{
    foreach (releaseWorkflowSteps('release') as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    throw new RuntimeException("The {$name} release step is missing.");
}
