<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/** How well a driver supports a Feature. */
enum Support: string
{
    /** Rendered natively. */
    case Full = 'full';

    /** Rendered, but via a workaround or with caveats (see the driver's notes). */
    case Partial = 'partial';

    /** Not available on this provider. */
    case None = 'none';

    public function symbol(): string
    {
        return match ($this) {
            self::Full => 'yes',
            self::Partial => 'partial',
            self::None => 'no',
        };
    }
}
