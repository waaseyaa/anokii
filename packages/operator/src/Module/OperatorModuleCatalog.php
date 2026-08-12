<?php

declare(strict_types=1);

namespace Anokii\Operator\Module;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** Resolves one deterministic, duplicate-free module catalogue per principal. */
final readonly class OperatorModuleCatalog
{
    /** @param iterable<OperatorModuleProviderInterface> $providers */
    public function __construct(private iterable $providers) {}

    /** @return list<OperatorModule> */
    public function forPrincipal(AuthorizationPrincipalInterface $principal): array
    {
        $modules = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->modules($principal) as $module) {
                if (!$module instanceof OperatorModule) {
                    throw new \LogicException('Operator module providers may return only OperatorModule values.');
                }
                if (isset($modules[$module->id])) {
                    throw new \LogicException("Duplicate Anokii operator module id: {$module->id}");
                }
                $modules[$module->id] = $module;
            }
        }

        return array_values($modules);
    }

    public function find(AuthorizationPrincipalInterface $principal, string $id): ?OperatorModule
    {
        foreach ($this->forPrincipal($principal) as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        return null;
    }
}
