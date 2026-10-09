<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use InvalidArgumentException;

/**
 * A small, deterministic YAML emitter.
 *
 * Slipway only ever *writes* YAML, so a dedicated emitter keeps the core free of
 * dependencies and guarantees byte-stable output (important for the drift guard).
 * Supports maps, lists, scalars, and multi-line strings (as literal blocks).
 */
final class Yaml
{
    public static function dump(array $data, int $indent = 2): string
    {
        if ($data === []) {
            return "{}\n";
        }

        $lines = self::emitNode($data, 0, $indent);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private static function emitNode(array $node, int $level, int $step): array
    {
        $pad = str_repeat(' ', $level * $step);
        $lines = [];

        if (array_is_list($node)) {
            foreach ($node as $item) {
                $lines = array_merge($lines, self::emitListItem($item, $level, $step));
            }

            return $lines;
        }

        foreach ($node as $key => $value) {
            $lines = array_merge($lines, self::emitMapEntry((string) $key, $value, $level, $step, $pad));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function emitMapEntry(string $key, mixed $value, int $level, int $step, string $pad): array
    {
        $k = self::formatKey($key);

        if (is_array($value)) {
            if ($value === []) {
                return [$pad . $k . ': ' . (array_is_list($value) ? '[]' : '{}')];
            }

            return array_merge(
                [$pad . $k . ':'],
                self::emitNode($value, $level + 1, $step)
            );
        }

        if (is_string($value) && str_contains($value, "\n")) {
            return array_merge(
                [$pad . $k . ': ' . self::blockHeader($value)],
                self::blockBody($value, ($level + 1) * $step)
            );
        }

        return [$pad . $k . ': ' . self::formatScalar($value)];
    }

    /**
     * @return list<string>
     */
    private static function emitListItem(mixed $item, int $level, int $step): array
    {
        $pad = str_repeat(' ', $level * $step);

        if (is_array($item)) {
            if ($item === []) {
                return [$pad . '- ' . (array_is_list($item) ? '[]' : '{}')];
            }

            // Render the nested node one level deeper, then fold its first line onto the dash.
            $inner = self::emitNode($item, $level + 1, $step);
            $firstPad = str_repeat(' ', ($level + 1) * $step);
            $inner[0] = $pad . '- ' . substr($inner[0], strlen($firstPad));

            return $inner;
        }

        if (is_string($item) && str_contains($item, "\n")) {
            return array_merge(
                [$pad . '- ' . self::blockHeader($item)],
                self::blockBody($item, ($level + 1) * $step)
            );
        }

        return [$pad . '- ' . self::formatScalar($item)];
    }

    private static function blockHeader(string $value): string
    {
        $firstLine = explode("\n", $value, 2)[0];
        $indicator = ($firstLine !== '' && $firstLine[0] === ' ') ? '2' : '';

        if (! str_ends_with($value, "\n")) {
            return '|' . $indicator . '-';
        }

        if (str_ends_with($value, "\n\n")) {
            return '|' . $indicator . '+';
        }

        return '|' . $indicator;
    }

    /**
     * @return list<string>
     */
    private static function blockBody(string $value, int $spaces): array
    {
        $pad = str_repeat(' ', $spaces);
        $body = $value;

        if (str_ends_with($body, "\n\n")) {
            $body = substr($body, 0, -1); // keep-chomping retains the extra blank lines
        } elseif (str_ends_with($body, "\n")) {
            $body = substr($body, 0, -1);
        }

        $out = [];
        foreach (explode("\n", $body) as $line) {
            $out[] = $line === '' ? '' : $pad . $line;
        }

        return $out;
    }

    private static function formatKey(string $key): string
    {
        if ($key !== '' && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\/\-\$\{\} ]*$/D', $key) === 1
            && ! str_ends_with($key, ' ') && ! str_contains($key, '  ')) {
            // "on" is intentionally emitted bare: GitHub Actions and YAML 1.2 treat it as a plain key.
            return self::needsQuotes($key) && $key !== 'on' ? self::quote($key) : $key;
        }

        return self::quote($key);
    }

    private static function formatScalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::formatFloat($value),
            is_string($value) => self::needsQuotes($value) ? self::quote($value) : $value,
            default => throw new InvalidArgumentException(
                'Cannot emit value of type ' . get_debug_type($value) . ' as YAML.'
            ),
        };
    }

    private static function formatFloat(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new InvalidArgumentException('Cannot emit NaN or infinite floats as YAML.');
        }

        $s = (string) $value;

        return str_contains($s, '.') || str_contains($s, 'E') || str_contains($s, 'e') ? $s : $s . '.0';
    }

    private static function needsQuotes(string $s): bool
    {
        if ($s === '') {
            return true;
        }

        // Leading / trailing whitespace is significant.
        if ($s !== trim($s)) {
            return true;
        }

        // Would be parsed as a non-string scalar (YAML 1.1 + 1.2 booleans, null, numbers).
        if (preg_match('/^(true|false|yes|no|on|off|y|n|null|~)$/iD', $s) === 1) {
            return true;
        }

        if (is_numeric($s) || preg_match('/^[-+]?(0x[0-9a-f]+|0o?[0-7]+|\.\d+|\d[\d_]*(\.\d*)?([eE][-+]?\d+)?)$/iD', $s) === 1) {
            return true;
        }

        // Date-like and sexagesimal values (YAML 1.1) such as 2026-10-09 or 1:30.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s) === 1 || preg_match('/^\d+(:\d+)+$/D', $s) === 1) {
            return true;
        }

        // Indicator characters that cannot start a plain scalar.
        if (preg_match('/^[\-\?:,\[\]\{\}#&\*!\|>\'"%@`]/', $s) === 1) {
            // "- foo" / "? foo" / ": foo" are indicators only when followed by a space or end,
            // but quoting is always safe, so be conservative.
            return true;
        }

        // Sequences that terminate or break a plain scalar.
        if (str_contains($s, ': ') || str_contains($s, ' #') || str_ends_with($s, ':')) {
            return true;
        }

        // Control characters, tabs and non-printables.
        if (preg_match('/[\x00-\x1f\x7f]/', $s) === 1) {
            return true;
        }

        return false;
    }

    private static function quote(string $s): string
    {
        $escaped = strtr($s, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\0" => '\\0',
        ]);

        $escaped = preg_replace_callback(
            '/[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/',
            static fn (array $m): string => sprintf('\\x%02x', ord($m[0])),
            $escaped
        ) ?? $escaped;

        return '"' . $escaped . '"';
    }
}
