<?php

declare(strict_types=1);

namespace Anokii\Operator\Module;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** Host-owned source of modules already filtered for the signed-in principal. */
interface OperatorModuleProviderInterface
{
    /** @return iterable<OperatorModule> */
    public function modules(AuthorizationPrincipalInterface $principal): iterable;
}
