<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires an explicit semantic version for a manual release', function (): void {
    $workflow = releaseWorkflow();

    expect($workflow['on']['workflow_dispatch']['inputs']['version'] ?? null)
        ->toBe([
            'description' => 'Semantic version to tag and release',
            'required' => true,
            'type' => 'string',
        ]);
});

it('releases only after both gates pass on an explicit main branch dispatch', function (): void {
    $workflow = releaseWorkflow();
    $jobs = $workflow['jobs'] ?? null;

    expect($jobs)->toBeArray();

    if (! is_array($jobs)) {
        return;
    }

    $release = $jobs['release'] ?? null;

    expect($release)->toBeArray()
        ->and(array_key_exists('auto-release', $jobs))->toBeFalse();

    if (! is_array($release)) {
        return;
    }

    expect($release['needs'] ?? null)->toBe([
        'compatibility',
        'retained-job-redis',
        'tests',
    ])
        ->and($release['if'] ?? null)
        ->toBe("github.ref == 'refs/heads/main' && github.event_name == 'workflow_dispatch'")
        ->and($release['permissions'] ?? null)->toBe(['contents' => 'write']);
});

it('keeps manual releases isolated while allowing push runs to cancel', function (): void {
    $workflow = releaseWorkflow();

    expect($workflow['concurrency'] ?? null)->toBe([
        'group' => "\${{ github.event_name == 'workflow_dispatch' && 'release' || format('tests-{0}', github.ref) }}",
        'cancel-in-progress' => "\${{ github.event_name != 'workflow_dispatch' }}",
    ]);
});

it('can resume a release only when the existing tag belongs to the dispatched commit', function (): void {
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

it('pins every external action to a full commit sha', function (): void {
    $workflow = releaseWorkflow();
    $jobs = $workflow['jobs'] ?? [];
    $actionReferences = [];

    if (is_array($jobs)) {
        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            foreach ($job['steps'] ?? [] as $step) {
                if (is_array($step) && is_string($step['uses'] ?? null)) {
                    $actionReferences[] = $step['uses'];
                }
            }
        }
    }

    expect($actionReferences)->not->toBeEmpty();

    foreach ($actionReferences as $actionReference) {
        expect($actionReference)->toMatch('/\A[^@]+@[a-f0-9]{40}\z/');
    }
});

/** @return array<string, mixed> */
function releaseWorkflow(): array
{
    $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/tests.yml');

    if (! is_array($workflow)) {
        throw new RuntimeException('The release workflow must contain a YAML mapping.');
    }

    return $workflow;
}

/** @return array<string, mixed> */
function releaseStep(string $name): array
{
    $steps = releaseWorkflow()['jobs']['release']['steps'] ?? [];

    if (is_array($steps)) {
        foreach ($steps as $step) {
            if (is_array($step) && ($step['name'] ?? null) === $name) {
                return $step;
            }
        }
    }

    throw new RuntimeException("The {$name} release step is missing.");
}
