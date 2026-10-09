<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

/** Helpers for generating POSIX sh safely. */
final class Shell
{
    /** Quote a value as a single POSIX shell word. */
    public static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /**
     * Indent every non-empty line (used when nesting scripts).
     */
    public static function indent(string $script, int $spaces = 2): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $pad . $line,
            explode("\n", $script)
        ));
    }
}
