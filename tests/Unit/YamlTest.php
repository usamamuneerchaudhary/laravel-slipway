<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Support\Yaml;

final class YamlTest extends TestCase
{
    public function test_it_emits_nested_maps_and_lists(): void
    {
        $yaml = Yaml::dump(['a' => ['b' => ['c', 'd'], 'e' => 1]]);

        $this->assertSame("a:\n  b:\n    - c\n    - d\n  e: 1\n", $yaml);
    }

    public function test_it_quotes_values_that_would_change_type(): void
    {
        $yaml = Yaml::dump([
            'v1' => '8.3', 'v2' => 'true', 'v3' => 'null', 'v4' => '123', 'v5' => '',
            'v6' => '2026-10-09', 'v7' => 'yes', 'v8' => '0o17', 'v9' => '1:30',
        ]);

        foreach (['v1: "8.3"', 'v2: "true"', 'v3: "null"', 'v4: "123"', 'v5: ""', 'v6: "2026-10-09"', 'v7: "yes"', 'v8: "0o17"', 'v9: "1:30"'] as $line) {
            $this->assertStringContainsString($line, $yaml);
        }
    }

    public function test_real_booleans_numbers_and_null_are_not_quoted(): void
    {
        $this->assertSame("a: true\nb: 7\nc: 1.5\nd: null\n", Yaml::dump(['a' => true, 'b' => 7, 'c' => 1.5, 'd' => null]));
    }

    public function test_it_quotes_values_with_yaml_indicators(): void
    {
        $yaml = Yaml::dump(['a' => '- dash', 'b' => 'x: y', 'c' => 'a #b', 'd' => '*star', 'e' => '@at', 'f' => '%pct', 'g' => '{x}']);

        foreach (['a: "- dash"', 'b: "x: y"', 'c: "a #b"', 'd: "*star"', 'e: "@at"', 'f: "%pct"', 'g: "{x}"'] as $line) {
            $this->assertStringContainsString($line, $yaml);
        }
    }

    public function test_it_escapes_quotes_backslashes_and_control_characters(): void
    {
        $this->assertSame("a: \"say \\\"hi\\\" \\\\ \\t\"\n", Yaml::dump(['a' => "say \"hi\" \\ \t"]));
    }

    public function test_plain_strings_stay_plain(): void
    {
        $yaml = Yaml::dump(['uses' => 'actions/checkout@v4', 'if' => "github.ref == 'refs/heads/main'", 'g' => '${{ secrets.TOKEN }}']);

        $this->assertStringContainsString('uses: actions/checkout@v4', $yaml);
        $this->assertStringContainsString("if: github.ref == 'refs/heads/main'", $yaml);
        $this->assertStringContainsString('g: ${{ secrets.TOKEN }}', $yaml);
    }

    public function test_multiline_strings_become_literal_blocks(): void
    {
        $this->assertSame("run: |\n  set -e\n  echo hi\n", Yaml::dump(['run' => "set -e\necho hi\n"]));
    }

    public function test_literal_block_chomping_follows_trailing_newlines(): void
    {
        $this->assertStringContainsString('run: |-', Yaml::dump(['run' => "a\nb"]));
        $this->assertStringContainsString('run: |+', Yaml::dump(['run' => "a\nb\n\n"]));
    }

    public function test_blank_lines_inside_blocks_stay_empty(): void
    {
        $this->assertSame("run: |\n  a\n\n  b\n", Yaml::dump(['run' => "a\n\nb\n"]));
    }

    public function test_leading_indentation_in_a_block_gets_an_indentation_indicator(): void
    {
        $this->assertStringContainsString('run: |2', Yaml::dump(['run' => "  indented\nnext\n"]));
    }

    public function test_lists_of_maps_fold_the_first_key_onto_the_dash(): void
    {
        $yaml = Yaml::dump(['steps' => [['name' => 'a', 'run' => 'x'], ['uses' => 'actions/checkout@v4']]]);

        $this->assertSame("steps:\n  - name: a\n    run: x\n  - uses: actions/checkout@v4\n", $yaml);
    }

    public function test_empty_containers(): void
    {
        $this->assertSame("a: []\nb: []\n", Yaml::dump(['a' => [], 'b' => []]));
        $this->assertSame("{}\n", Yaml::dump([]));
    }

    public function test_keys_that_resemble_booleans_are_quoted_except_on(): void
    {
        $yaml = Yaml::dump(['on' => ['push' => []], 'y' => 1, 'n' => 2]);

        $this->assertStringContainsString("on:\n", $yaml);
        $this->assertStringContainsString('"y": 1', $yaml);
        $this->assertStringContainsString('"n": 2', $yaml);
    }

    public function test_single_letter_booleans_are_quoted_as_values(): void
    {
        $this->assertSame("- \"y\"\n- \"n\"\n- ok\n", Yaml::dump(['y', 'n', 'ok']));
    }

    public function test_output_is_deterministic(): void
    {
        $data = ['z' => 1, 'a' => ['x' => [1, 2, ['k' => 'v']]]];

        $this->assertSame(Yaml::dump($data), Yaml::dump($data));
    }

    public function test_unsupported_types_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Yaml::dump(['a' => new \stdClass()]);
    }

    public function test_non_finite_floats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Yaml::dump(['a' => NAN]);
    }
}
