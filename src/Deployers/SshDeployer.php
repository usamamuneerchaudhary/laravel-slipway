<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;

/**
 * Zero-downtime SSH deployment of the CI build artifact.
 *
 * Layout on the server (create `shared/.env` and `shared/storage` once, by hand):
 *
 *   <path>/releases/<timestamp>/   one directory per release (uploaded with rsync)
 *   <path>/shared/.env, storage/   persisted between releases, symlinked into each release
 *   <path>/current                 symlink to the live release, switched atomically
 *
 * Host keys are verified (StrictHostKeyChecking=yes) against the SSH_KNOWN_HOSTS secret,
 * so a man-in-the-middle cannot impersonate your server.
 *
 * Options:
 *   host, user, path     required
 *   port                 default 22
 *   keep_releases        default 5 (min 2, so rollback always has a target)
 *   migrate              default true  -> php artisan migrate --force
 *   optimize             default true  -> php artisan optimize
 *   storage_link         default true  -> php artisan storage:link --force
 *   post_deploy          list of shell commands run in the new release before it goes live
 *   key_secret           default SSH_PRIVATE_KEY
 *   known_hosts_secret   default SSH_KNOWN_HOSTS
 *
 * Requirements on the server: GNU coreutils (`mv -T`), PHP, and a one-time `<path>/shared/.env`.
 * Release directories are named by UTC timestamp, so they sort chronologically.
 *
 * Rollback re-points `current` at the release that was live before this deploy. It does NOT
 * undo database migrations: keep migrations backwards compatible. PHP-FPM with OPcache may need
 * a reload to notice the switch; add it to `post_deploy` (e.g. "sudo -n systemctl reload php8.3-fpm").
 */
final class SshDeployer extends AbstractDeployer
{
    private const KNOWN = ['host', 'user', 'path', 'port', 'keep_releases', 'migrate', 'optimize', 'storage_link', 'post_deploy', 'key_secret', 'known_hosts_secret'];

    public function validate(Environment $environment): array
    {
        $o = $environment->options;
        $errors = $this->unknownOptions($o, self::KNOWN);

        $host = $o['host'] ?? null;
        if (! is_string($host) || preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/D', $host) !== 1) {
            $errors[] = 'option [host] is required and must be a hostname or IPv4 address';
        }

        $user = $o['user'] ?? null;
        if (! is_string($user) || preg_match('/^[a-z_][a-z0-9_-]*$/D', $user) !== 1) {
            $errors[] = 'option [user] is required and must be a plain unix username';
        }

        $path = $o['path'] ?? null;
        if (! is_string($path) || preg_match('#^/[A-Za-z0-9_./-]+$#D', $path) !== 1 || str_contains($path, '..')) {
            $errors[] = 'option [path] is required and must be an absolute path such as /var/www/app';
        }

        if (isset($o['port']) && (! is_int($o['port']) || $o['port'] < 1 || $o['port'] > 65535)) {
            $errors[] = 'option [port] must be an integer from 1 to 65535';
        }

        if (isset($o['keep_releases']) && (! is_int($o['keep_releases']) || $o['keep_releases'] < 2 || $o['keep_releases'] > 50)) {
            $errors[] = 'option [keep_releases] must be an integer from 2 to 50';
        }

        foreach (['migrate', 'optimize', 'storage_link'] as $flag) {
            if (isset($o[$flag]) && ! is_bool($o[$flag])) {
                $errors[] = "option [{$flag}] must be true or false";
            }
        }

        if (isset($o['post_deploy'])) {
            if (! is_array($o['post_deploy']) || ! array_is_list($o['post_deploy'])) {
                $errors[] = 'option [post_deploy] must be a list of single-line commands';
            } else {
                foreach ($o['post_deploy'] as $i => $cmd) {
                    if (! is_string($cmd) || trim($cmd) === '' || preg_match('/[\x00\r\n]/', $cmd) === 1 || str_contains($cmd, 'SLIPWAY_REMOTE_EOF')) {
                        $errors[] = "option [post_deploy.{$i}] must be a non-empty single-line command";
                    }
                }
            }
        }

        foreach ([['key_secret', 'SSH_PRIVATE_KEY'], ['known_hosts_secret', 'SSH_KNOWN_HOSTS']] as [$key, $default]) {
            $errors = array_merge($errors, $this->validSecretName($environment, $key, $default));
        }

        return $errors;
    }

    public function requiredSecrets(Environment $environment): array
    {
        return [
            $this->secretOption($environment, 'key_secret', 'SSH_PRIVATE_KEY'),
            $this->secretOption($environment, 'known_hosts_secret', 'SSH_KNOWN_HOSTS'),
        ];
    }

    public function tools(Environment $environment): array
    {
        return ['ssh', 'rsync'];
    }

    public function usesArtifact(Environment $environment): bool
    {
        return true;
    }

    public function supportsRollback(Environment $environment): bool
    {
        return true;
    }

    public function deployCommands(Environment $environment): string
    {
        $o = $environment->options;

        $post = '';
        foreach ($o['post_deploy'] ?? [] as $command) {
            $post .= $command . "\n";
        }

        $template = <<<'SH'
umask 077
mkdir -p "$HOME/.ssh"
trap 'rm -f "$HOME/.ssh/slipway_deploy_key"' EXIT
printf '%s\n' "$__KEY__" > "$HOME/.ssh/slipway_deploy_key"
printf '%s\n' "$__KNOWN__" > "$HOME/.ssh/known_hosts"
SSH_OPTS="-i $HOME/.ssh/slipway_deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=30 -o ServerAliveCountMax=4 -p __PORT__"
TARGET=__USER__@__HOST__
BASE=__PATH__
RELEASE=$(date -u +%Y%m%d%H%M%S)

PREVIOUS_RELEASE=$(ssh $SSH_OPTS "$TARGET" "readlink '$BASE/current' 2>/dev/null || true")
case "$PREVIOUS_RELEASE" in
  *[!A-Za-z0-9_./-]*) echo "Unexpected characters in the previous release path; aborting." >&2; exit 1 ;;
esac

echo "Uploading release $RELEASE to $TARGET:$BASE"
ssh $SSH_OPTS "$TARGET" "mkdir -p '$BASE/releases/$RELEASE' '$BASE/shared'"
rsync -az --delete -e "ssh $SSH_OPTS" ./ "$TARGET:$BASE/releases/$RELEASE/"

ssh $SSH_OPTS "$TARGET" "sh -s -- '$BASE' '$RELEASE' '__KEEP__' '$PREVIOUS_RELEASE'" <<'SLIPWAY_REMOTE_EOF'
set -eu
BASE="$1"; RELEASE="$2"; KEEP="$3"; PREVIOUS="$4"
if [ ! -f "$BASE/shared/.env" ]; then
  echo "Missing $BASE/shared/.env - create it once on the server before the first deploy." >&2
  exit 1
fi
mkdir -p "$BASE/shared/storage/app/public" "$BASE/shared/storage/framework/cache/data" \
  "$BASE/shared/storage/framework/sessions" "$BASE/shared/storage/framework/testing" \
  "$BASE/shared/storage/framework/views" "$BASE/shared/storage/logs"
cd "$BASE/releases/$RELEASE"
ln -sfn "$BASE/shared/.env" .env
rm -rf storage
ln -sfn "$BASE/shared/storage" storage
__STORAGE_LINK____MIGRATE____OPTIMIZE____POST__ln -sfn "$BASE/releases/$RELEASE" "$BASE/current.tmp"
mv -Tf "$BASE/current.tmp" "$BASE/current"
cd "$BASE/releases"
# Pruning is housekeeping: the release is already live, so a failure here must not fail the
# deploy (which would skip verification and rollback).
ls -1 | sort -r | tail -n +$((KEEP + 1)) | while read -r old; do
  if [ -n "$old" ] && [ "$BASE/releases/$old" != "$PREVIOUS" ] && [ "$old" != "$RELEASE" ]; then
    rm -rf -- "$BASE/releases/$old" || echo "Warning: could not remove old release $old" >&2
  fi
done || true
echo "Release $RELEASE is live"
SLIPWAY_REMOTE_EOF
SH;

        return strtr($template, [
            '__KEY__' => $this->secretOption($environment, 'key_secret', 'SSH_PRIVATE_KEY'),
            '__KNOWN__' => $this->secretOption($environment, 'known_hosts_secret', 'SSH_KNOWN_HOSTS'),
            '__PORT__' => (string) ($o['port'] ?? 22),
            '__USER__' => (string) $o['user'],
            '__HOST__' => (string) $o['host'],
            '__PATH__' => (string) $o['path'],
            '__KEEP__' => (string) ($o['keep_releases'] ?? 5),
            '__STORAGE_LINK__' => ($o['storage_link'] ?? true) ? "php artisan storage:link --force\n" : '',
            '__MIGRATE__' => ($o['migrate'] ?? true) ? "php artisan migrate --force\n" : '',
            '__OPTIMIZE__' => ($o['optimize'] ?? true) ? "php artisan optimize\n" : '',
            '__POST__' => $post,
        ]);
    }

    public function rollbackCommands(Environment $environment): ?string
    {
        return <<<'SH'
if [ -z "$PREVIOUS_RELEASE" ]; then
  echo "No previous release to roll back to (this was the first deploy)." >&2
else
  echo "Rolling back $TARGET:$BASE to $PREVIOUS_RELEASE"
  ssh $SSH_OPTS "$TARGET" "sh -s -- '$BASE' '$PREVIOUS_RELEASE'" <<'SLIPWAY_REMOTE_EOF'
set -eu
BASE="$1"; PREVIOUS="$2"
if [ ! -d "$PREVIOUS" ]; then
  echo "Previous release $PREVIOUS no longer exists." >&2
  exit 1
fi
ln -sfn "$PREVIOUS" "$BASE/current.tmp"
mv -Tf "$BASE/current.tmp" "$BASE/current"
echo "Rolled back to $PREVIOUS"
SLIPWAY_REMOTE_EOF
fi
SH;
    }
}
