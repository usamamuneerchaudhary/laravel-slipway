<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Contracts;

use Usamamuneerchaudhary\Slipway\Model\Environment;

/**
 * PUBLIC API. Turns an environment definition into POSIX `sh` deploy commands.
 *
 * Scripts must be POSIX sh (not bash): they run unchanged on GitHub runners, and in
 * minimal Alpine-based containers on Bitbucket and GitLab. Secrets are read from
 * environment variables named in requiredSecrets(); never inline a secret value.
 *
 * Every deploy job also receives these provider-neutral variables from the driver:
 * SLIPWAY_SHA, SLIPWAY_REF, SLIPWAY_REPO, SLIPWAY_RUN_URL.
 */
interface Deployer
{
    /**
     * Validate the environment's `options` at compile time.
     *
     * @return list<string> Error messages (empty when valid).
     */
    public function validate(Environment $environment): array;

    /** @return list<string> Secret names that must be declared in config('slipway.secrets'). */
    public function requiredSecrets(Environment $environment): array;

    /** @return list<string> CLI tools the deploy job needs: curl, ssh, rsync, git. */
    public function tools(Environment $environment): array;

    /** True if the deploy job needs PHP + Composer dependencies installed (e.g. Vapor CLI). */
    public function needsPhp(Environment $environment): bool;

    /** True if this deployer ships the build artifact, so the job downloads it instead of building. */
    public function usesArtifact(Environment $environment): bool;

    public function deployCommands(Environment $environment): string;

    public function supportsRollback(Environment $environment): bool;

    /** Commands that restore the previous release, or null when unsupported. */
    public function rollbackCommands(Environment $environment): ?string;
}
