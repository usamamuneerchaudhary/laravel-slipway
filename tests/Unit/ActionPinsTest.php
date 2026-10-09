<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Usamamuneerchaudhary\Slipway\Support\ActionPins;

final class ActionPinsTest extends TestCase
{
    private const WORKFLOW = <<<'YAML'
jobs:
  test:
    steps:
      - name: Check out code
        uses: actions/checkout@v4
        with:
          persist-credentials: false
      - name: Set up PHP
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962
      - name: Check out again
        uses: actions/checkout@v4
      - name: Cache
        uses: actions/cache@v4
      - run: echo "uses: not/an-action@v1"
YAML;

    public function test_it_lists_the_actions_a_workflow_uses(): void
    {
        $this->assertSame([
            'actions/cache' => 'v4',
            'actions/checkout' => 'v4',
            'shivammathur/setup-php' => 'eb7c497e18156a6bbabfef1d3a82760b9eda3962',
        ], ActionPins::used(self::WORKFLOW));
    }

    public function test_actions_already_pinned_to_a_commit_are_not_reported(): void
    {
        $this->assertSame(['actions/cache' => 'v4', 'actions/checkout' => 'v4'], ActionPins::unpinned(self::WORKFLOW));
    }

    public function test_only_full_length_lowercase_hex_counts_as_a_commit_sha(): void
    {
        $this->assertTrue(ActionPins::isCommitSha(str_repeat('ab', 20)));
        $this->assertFalse(ActionPins::isCommitSha('v4'));
        $this->assertFalse(ActionPins::isCommitSha('abc123'));
        $this->assertFalse(ActionPins::isCommitSha(str_repeat('AB', 20)));
        $this->assertFalse(ActionPins::isCommitSha(str_repeat('ab', 20) . "\n"));
    }

    public function test_it_resolves_through_the_lookup(): void
    {
        $sha = str_repeat('d4', 20);
        $pins = new ActionPins(static fn (string $action, string $ref): ?string => "{$action}@{$ref}" === 'actions/checkout@v4' ? $sha : null);

        $this->assertSame($sha, $pins->resolve('actions/checkout', 'v4'));
    }

    public function test_an_unknown_tag_or_a_malformed_answer_is_an_error(): void
    {
        $missing = new ActionPins(static fn (): ?string => null);
        $malformed = new ActionPins(static fn (): ?string => 'not-a-sha');

        foreach ([$missing, $malformed] as $pins) {
            try {
                $pins->resolve('actions/checkout', 'v99');
                $this->fail('expected a RuntimeException');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Could not resolve actions/checkout@v99', $e->getMessage());
            }
        }
    }

    public function test_action_and_tag_names_are_validated_before_anything_runs(): void
    {
        $pins = new ActionPins(function (): ?string {
            $this->fail('the lookup must not be reached');
        });

        foreach ([['actions/checkout; id', 'v4'], ['actions', 'v4'], ['actions/checkout', 'v4 --upload-pack=x'], ['actions/checkout', "v4\n"]] as [$action, $ref]) {
            try {
                $pins->resolve($action, $ref);
                $this->fail("expected [{$action}@{$ref}] to be rejected");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('plain', $e->getMessage());
            }
        }
    }

    public function test_ls_remote_output_prefers_the_peeled_commit_of_an_annotated_tag(): void
    {
        $tagObject = str_repeat('1a', 20);
        $commit = str_repeat('2b', 20);

        $this->assertSame($commit, ActionPins::parseLsRemote("{$tagObject}\trefs/tags/v4\n{$commit}\trefs/tags/v4^{}\n", 'v4'));
        $this->assertSame($tagObject, ActionPins::parseLsRemote("{$tagObject}\trefs/tags/v4\n", 'v4'), 'a lightweight tag points at the commit directly');
        $this->assertNull(ActionPins::parseLsRemote('', 'v4'));
        $this->assertNull(ActionPins::parseLsRemote("{$tagObject}\trefs/tags/v40\n", 'v4'), 'a different tag must not match');
    }
}
