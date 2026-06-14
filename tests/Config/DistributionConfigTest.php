<?php

declare(strict_types=1);

namespace Anokii\Tests\Config;

use Anokii\Config\DistributionConfig;
use Anokii\Config\TenancyMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the safe-by-default resolution rules of the distribution switch.
 *
 * These tests author the contract now and run once Anokii has a vendor/ (this
 * environment cannot composer install). They assert that the most
 * sovereign-protective reading is always the fallback (charter DIR-A005).
 */
#[CoversClass(DistributionConfig::class)]
#[CoversClass(TenancyMode::class)]
final class DistributionConfigTest extends TestCase
{
    #[Test]
    public function emptyConfigDefaultsToTheMostProtectiveSovereignPosture(): void
    {
        $config = DistributionConfig::fromArray([]);

        self::assertSame(TenancyMode::Sovereign, $config->tenancyMode());

        $residency = $config->dataResidency();
        self::assertSame('nation', $residency['ownership']);
        self::assertSame('nation-restricted', $residency['default_classification']);
        self::assertFalse($residency['cross_tenant_reads']);
    }

    #[Test]
    public function unknownTenancyModeFallsBackToSovereign(): void
    {
        $config = DistributionConfig::fromArray(['tenancy_mode' => 'not-a-real-mode']);

        self::assertSame(TenancyMode::Sovereign, $config->tenancyMode());
        self::assertFalse($config->dataResidency()['cross_tenant_reads']);
    }

    #[Test]
    public function sovereignModeNeverEnablesCrossTenantReadsEvenWhenRequested(): void
    {
        // A sovereign config that mistakenly asks for cross-tenant reads must NOT
        // get them: in sovereign mode there is only one tenant.
        $config = DistributionConfig::fromArray([
            'tenancy_mode' => 'sovereign',
            'data_residency' => ['cross_tenant_reads' => true],
        ]);

        self::assertFalse($config->dataResidency()['cross_tenant_reads']);
    }

    #[Test]
    public function sharedGraphModeDerivesPublicResidencyDefaults(): void
    {
        $config = DistributionConfig::fromArray(['tenancy_mode' => 'shared-graph']);

        self::assertSame(TenancyMode::SharedGraph, $config->tenancyMode());

        $residency = $config->dataResidency();
        self::assertSame('shared', $residency['ownership']);
        self::assertSame('public', $residency['default_classification']);
        self::assertTrue($residency['cross_tenant_reads']);
    }

    #[Test]
    public function sharedGraphCanExplicitlyDisableCrossTenantReads(): void
    {
        $config = DistributionConfig::fromArray([
            'tenancy_mode' => 'shared-graph',
            'data_residency' => ['cross_tenant_reads' => false],
        ]);

        self::assertFalse($config->dataResidency()['cross_tenant_reads']);
    }

    #[Test]
    public function explicitResidencyValuesWinOverDerivedDefaults(): void
    {
        $config = DistributionConfig::fromArray([
            'tenancy_mode' => 'sovereign',
            'data_residency' => [
                'ownership' => 'nation',
                'default_classification' => 'community',
                'cross_tenant_reads' => false,
            ],
        ]);

        self::assertSame('community', $config->dataResidency()['default_classification']);
    }

    #[Test]
    public function unknownModuleIsDisabledByDefault(): void
    {
        $config = DistributionConfig::fromArray([]);

        self::assertFalse($config->moduleEnabled('governed-drive'));
        self::assertFalse($config->modulePreview('governed-drive'));
    }

    #[Test]
    public function enabledModuleReportsEnabledAndNotPreview(): void
    {
        $config = DistributionConfig::fromArray([
            'modules' => ['enabled' => ['forms', 'tasks']],
        ]);

        self::assertTrue($config->moduleEnabled('forms'));
        self::assertFalse($config->modulePreview('forms'));
        self::assertFalse($config->moduleEnabled('docs'));
    }

    #[Test]
    public function previewModuleIsVisibleButNotEnabled(): void
    {
        $config = DistributionConfig::fromArray([
            'modules' => ['preview' => ['cointelligence-workspaces']],
        ]);

        self::assertTrue($config->modulePreview('cointelligence-workspaces'));
        self::assertFalse($config->moduleEnabled('cointelligence-workspaces'));
    }

    #[Test]
    public function moduleInBothListsIsTreatedAsPreviewNotEnabled(): void
    {
        $config = DistributionConfig::fromArray([
            'modules' => [
                'enabled' => ['docs'],
                'preview' => ['docs'],
            ],
        ]);

        self::assertTrue($config->modulePreview('docs'));
        self::assertFalse($config->moduleEnabled('docs'));
    }

    #[Test]
    public function missingFileResolvesToSafeDefaults(): void
    {
        $config = DistributionConfig::fromFile(
            sys_get_temp_dir() . '/anokii_no_such_config_' . uniqid() . '.yaml'
        );

        self::assertSame(TenancyMode::Sovereign, $config->tenancyMode());
        self::assertFalse($config->dataResidency()['cross_tenant_reads']);
    }
}
