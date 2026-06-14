<?php

declare(strict_types=1);

namespace Anokii\Config;

/**
 * The Anokii distribution tenancy tier.
 *
 * Selects the posture the whole install runs in. This sits above the framework's
 * per-entity tenancy primitive (EntityType tenancy: ['scope' => 'community'] plus
 * the CommunityScope storage driver). The framework isolates entities; this enum
 * names which of the two Anokii tiers the deployment is operating as.
 *
 * Sovereign is the safe-by-default value: an absent or unrecognised mode resolves
 * to Sovereign, the most sovereign-protective posture (charter DIR-A005).
 *
 * @api
 */
enum TenancyMode: string
{
    /**
     * A single Anokii install, owned and hosted by one Nation, serving only that
     * Nation. Data is nation-owned end to end and cross-tenant reads do not exist
     * because there is one tenant (FNPI, Intersnipe, each pilot Nation).
     */
    case Sovereign = 'sovereign';

    /**
     * One install serving many communities as vantage views over a shared public
     * graph. Only public-sourced data lives in the shared layer; community and
     * nation-restricted tiers remain Forbidden across tenants via the framework
     * FieldAccessPolicyInterface (an OIATC-style multi-community install).
     */
    case SharedGraph = 'shared-graph';

    /**
     * Resolve a raw string to a mode, falling back to the most protective tier.
     *
     * Unknown or null input yields Sovereign rather than throwing, so a malformed
     * config can never silently widen data exposure to the shared-graph posture.
     */
    public static function fromStringOrSovereign(?string $value): self
    {
        if ($value === null) {
            return self::Sovereign;
        }

        return self::tryFrom($value) ?? self::Sovereign;
    }
}
