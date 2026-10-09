<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * Provider-neutral step kinds. Drivers decide how each kind is rendered.
 *
 * Environment setup (PHP, Node, caches, services, checkout) is NOT a step in the
 * neutral model: it lives on the Job so every driver can render it natively.
 */
enum StepType: string
{
    /** A shell command (POSIX sh compatible). */
    case Run = 'run';

    /** Upload the job's declared artifact paths for later jobs (build once, promote). */
    case ArtifactUpload = 'artifact-upload';

    /** Fetch the build artifact produced by an earlier job. */
    case ArtifactDownload = 'artifact-download';
}
