<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Deployers\DeployScript;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;

/**
 * Always on. Every secret a step reads must be declared in config('slipway.secrets'), so the
 * list is a complete, reviewable inventory of what the pipeline can touch.
 */
final class DeclaredSecrets extends AbstractPolicy
{
    public function name(): string
    {
        return 'declared-secrets';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $violations = [];
        $declared = array_flip($pipeline->declaredSecrets);
        $used = $pipeline->referencedSecrets();

        foreach ($used as $secret) {
            if (! isset($declared[$secret])) {
                $violations[] = new Violation(
                    $this->name(),
                    "Secret [{$secret}] is used by the pipeline but not declared. Add it to the \"secrets\" list in config/slipway.php."
                );
            }
        }

        foreach ($pipeline->declaredSecrets as $secret) {
            if (! in_array($secret, $used, true)) {
                $violations[] = new Violation(
                    $this->name(),
                    "Secret [{$secret}] is declared but never used. Remove it to keep the inventory accurate.",
                    Violation::WARNING
                );
            }
        }

        return $violations;
    }
}
