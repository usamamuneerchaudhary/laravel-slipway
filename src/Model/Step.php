<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * One unit of work inside a job.
 *
 * Secrets are referenced by NAME only (`$secrets`). Drivers expose them to the
 * step as environment variables of the same name, so commands simply use `$NAME`.
 */
final class Step
{
    /**
     * Placeholder replaced with the configured console command (`artisan` key, default "php artisan").
     * Lets library packages run the same steps through Testbench.
     */
    public const ARTISAN = '__ARTISAN__';

    /**
     * Placeholder for " --locked": replaced with that flag when the project commits a composer.lock
     * (the `lockfile` key, default true) and with nothing when it does not (typical for libraries).
     */
    public const LOCKED = '__LOCKED__';

    /**
     * @param array<string,string> $env      Non-secret environment variables.
     * @param list<string>         $secrets  Names of secrets this step needs exposed as env vars.
     * @param 'success'|'failure'|'always' $runOn
     */
    public function __construct(
        public readonly StepType $type,
        public readonly string $name,
        public readonly ?string $run = null,
        public readonly array $env = [],
        public readonly array $secrets = [],
        public readonly string $runOn = 'success',
    ) {
    }

    public static function run(string $name, string $command, array $env = [], array $secrets = [], string $runOn = 'success'): self
    {
        return new self(StepType::Run, $name, $command, $env, array_values(array_unique($secrets)), $runOn);
    }

    public static function artifactUpload(string $name = 'Upload build artifact'): self
    {
        return new self(StepType::ArtifactUpload, $name);
    }

    public static function artifactDownload(string $name = 'Download build artifact'): self
    {
        return new self(StepType::ArtifactDownload, $name);
    }
}
