<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

/**
 * POSIX sh snippets that post a pipeline status message to Slack or Microsoft Teams.
 *
 * Expects the driver-provided variables SLIPWAY_REPO, SLIPWAY_REF, SLIPWAY_RUN_URL and the
 * step-provided SLIPWAY_JOB, SLIPWAY_STATUS, SLIPWAY_PIPELINE. Every value is JSON-escaped
 * because git branch names may contain quotes and backslashes.
 */
final class NotificationScript
{
    public static function render(string $channel, string $secretName): string
    {
        $head = <<<'SH'
json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
MESSAGE="$(json_escape "$SLIPWAY_PIPELINE"): $(json_escape "$SLIPWAY_JOB") $SLIPWAY_STATUS on $(json_escape "$SLIPWAY_REF") ($(json_escape "$SLIPWAY_REPO")) - $(json_escape "$SLIPWAY_RUN_URL")"
SH;

        $payload = match ($channel) {
            'slack' => <<<'SH'
printf '{"text":"%s"}' "$MESSAGE" > slipway-notification.json
SH,
            'teams' => <<<'SH'
printf '{"type":"message","attachments":[{"contentType":"application/vnd.microsoft.card.adaptive","content":{"$schema":"http://adaptivecards.io/schemas/adaptive-card.json","type":"AdaptiveCard","version":"1.4","body":[{"type":"TextBlock","wrap":true,"text":"%s"}]}}]}' "$MESSAGE" > slipway-notification.json
SH,
            default => throw new \InvalidArgumentException("Unknown notification channel [{$channel}]."),
        };

        return $head . "\n" . $payload . "\n"
            . 'curl -fsS --max-time 20 -X POST -H \'Content-Type: application/json\' --data @slipway-notification.json "$' . $secretName . '" || echo "Notification failed (ignored)." >&2';
    }
}
