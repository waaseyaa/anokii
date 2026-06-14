<?php

declare(strict_types=1);

namespace Anokii\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Typed reader for the Anokii distribution switch (config/anokii.yaml).
 *
 * This is the first piece of Anokii product code. It loads the distribution-level
 * configuration and exposes it as typed accessors with safe-by-default behavior:
 * every ambiguous or missing value resolves to the most sovereign-protective
 * reading (charter DIR-A005), never the most permissive one.
 *
 * Resolution rules:
 *   - missing tenancy_mode          -> TenancyMode::Sovereign
 *   - unknown tenancy_mode string   -> TenancyMode::Sovereign
 *   - missing data_residency.*      -> nation-owned, nation-restricted default,
 *                                      cross_tenant_reads false
 *   - module not in either list     -> disabled (moduleEnabled returns false)
 *   - module in both lists          -> preview wins (the conservative reading)
 *
 * This class is a distribution-level posture selector. It does NOT replace the
 * framework's per-entity CommunityScope isolation or FieldAccessPolicyInterface;
 * it chooses which posture the install wires those primitives into.
 *
 * Status: DRAFT (WP04). The shape is the design contract; production wiring of
 * the surfaces this gates lands in a later increment.
 *
 * @api
 */
final class DistributionConfig
{
    private const DEFAULT_CLASSIFICATION = 'nation-restricted';

    /**
     * @param array<string, mixed> $raw Parsed top-level config document.
     */
    private function __construct(private readonly array $raw)
    {
    }

    /**
     * Build from a parsed array (the canonical constructor for tests and callers
     * that already hold a decoded document).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    /**
     * Build from a YAML file on disk.
     *
     * A missing file yields an empty config, which resolves to the fully
     * safe-by-default sovereign posture. A present-but-unreadable or malformed
     * file is a real operator error and is allowed to surface as an exception
     * from the YAML parser, because silently defaulting a corrupt sovereign
     * config would hide a misconfiguration.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $parsed = Yaml::parseFile($path);

        return new self(is_array($parsed) ? $parsed : []);
    }

    /**
     * The selected tenancy tier. Sovereign when absent or unrecognised.
     */
    public function tenancyMode(): TenancyMode
    {
        $value = $this->raw['tenancy_mode'] ?? null;

        return TenancyMode::fromStringOrSovereign(is_string($value) ? $value : null);
    }

    /**
     * The data-residency posture, always returned as a fully populated array with
     * the three documented keys, derived safely when the config omits them.
     *
     * Explicit config values win. When a key is absent, the value is derived from
     * the tenancy mode using the most protective reading:
     *   - ownership: "shared" only when shared-graph AND not overridden, else "nation"
     *   - default_classification: "public" for shared-graph, else "nation-restricted"
     *   - cross_tenant_reads: true only for shared-graph, else false
     *
     * @return array{ownership: string, default_classification: string, cross_tenant_reads: bool}
     */
    public function dataResidency(): array
    {
        $mode = $this->tenancyMode();
        $block = $this->raw['data_residency'] ?? null;
        $block = is_array($block) ? $block : [];

        $isShared = $mode === TenancyMode::SharedGraph;

        $ownership = $block['ownership'] ?? null;
        $ownership = is_string($ownership) ? $ownership : ($isShared ? 'shared' : 'nation');

        $classification = $block['default_classification'] ?? null;
        $classification = is_string($classification)
            ? $classification
            : ($isShared ? 'public' : self::DEFAULT_CLASSIFICATION);

        // cross_tenant_reads is true only when explicitly enabled AND the mode
        // permits it. Sovereign can never read across tenants regardless of the
        // file, because in sovereign mode there is only one tenant.
        $crossRaw = $block['cross_tenant_reads'] ?? null;
        $crossRequested = is_bool($crossRaw) ? $crossRaw : $isShared;
        $crossTenantReads = $isShared && $crossRequested;

        return [
            'ownership' => $ownership,
            'default_classification' => $classification,
            'cross_tenant_reads' => $crossTenantReads,
        ];
    }

    /**
     * Whether a WP04 surface is wired as production-ready.
     *
     * Safe-by-default: a module not named in the enabled list returns false.
     * A module listed in both enabled and preview is treated as preview, so it is
     * NOT enabled for production.
     */
    public function moduleEnabled(string $module): bool
    {
        if ($this->modulePreview($module)) {
            return false;
        }

        return in_array($module, $this->moduleList('enabled'), true);
    }

    /**
     * Whether a WP04 surface is visible but flagged not-for-production.
     *
     * Safe-by-default: a module not named in the preview list returns false.
     */
    public function modulePreview(string $module): bool
    {
        return in_array($module, $this->moduleList('preview'), true);
    }

    /**
     * The raw string values of one module list, filtered to strings.
     *
     * @return list<string>
     */
    private function moduleList(string $key): array
    {
        $modules = $this->raw['modules'] ?? null;
        if (!is_array($modules)) {
            return [];
        }

        $list = $modules[$key] ?? null;
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }
}
