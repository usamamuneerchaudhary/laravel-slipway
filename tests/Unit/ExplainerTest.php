<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\Support\CapabilityMatrix;
use Usamamuneerchaudhary\Slipway\Support\Explainer;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class ExplainerTest extends TestCase
{
    private function text(array $config): string
    {
        return implode("\n", Explainer::explain(Fixture::compile($config)));
    }

    public function test_it_describes_triggers_stages_and_environments_in_plain_english(): void
    {
        $text = $this->text(Fixture::full());

        $this->assertStringContainsString('Pipeline "Acme CI"', $text);
        $this->assertStringContainsString('Runs on: pushes to main; tags matching v*; pull requests; schedule (0 3 * * 1 UTC); manual runs', $text);
        $this->assertStringContainsString('Stage 1: quality', $text);
        $this->assertStringContainsString('[PHP 8.2, 8.3; with mysql, redis]', $text);
        $this->assertStringContainsString('production: after staging succeeds, deploys via ssh, needs manual approval', $text);
        $this->assertStringContainsString('rolls back automatically if verification fails', $text);
    }

    public function test_it_lists_secret_names_but_never_values(): void
    {
        $text = $this->text(Fixture::full());

        $this->assertStringContainsString('Secrets the pipeline reads (names only): ', $text);
        $this->assertStringContainsString('SSH_PRIVATE_KEY', $text);
    }

    public function test_it_includes_provider_warnings(): void
    {
        $this->assertStringContainsString('Warnings', $this->text(Fixture::full()));
    }

    public function test_it_says_when_there_is_no_verification_or_rollback(): void
    {
        $config = Fixture::full(['policies' => []]);
        unset($config['environments']['staging']['verify']);
        $config['environments']['staging']['rollback'] = false;

        $text = $this->text($config);

        $this->assertStringContainsString('no post-deploy verification', $text);
        $this->assertStringContainsString('no automatic rollback', $text);
    }

    public function test_capability_matrix_lists_every_feature_for_every_driver(): void
    {
        $matrix = CapabilityMatrix::build(DriverManager::withDefaults());

        $this->assertSame(['Feature', 'Bitbucket Pipelines', 'GitHub Actions', 'GitLab CI/CD'], $matrix['headers']);
        $this->assertCount(count(\Usamamuneerchaudhary\Slipway\Model\Feature::cases()), $matrix['rows']);
        foreach ($matrix['rows'] as $row) {
            $this->assertCount(4, $row);
            foreach (array_slice($row, 1) as $cell) {
                $this->assertContains($cell, ['yes', 'partial', 'no']);
            }
        }
        $this->assertNotEmpty($matrix['notes']);
    }

    public function test_capability_matrix_renders_markdown(): void
    {
        $md = CapabilityMatrix::markdown(DriverManager::withDefaults());

        $this->assertStringStartsWith('| Feature | Bitbucket Pipelines | GitHub Actions | GitLab CI/CD |', $md);
        $this->assertStringContainsString('|---|---|---|---|', $md);
    }
}
