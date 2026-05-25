<?php
/**
 * Anokii deployer overlay — inherits from Waaseyaa reference recipe.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Mission: anokii-distribution-scaffold-01KSEFT7
 *
 * This file overlays the Waaseyaa base recipe with Anokii-specific
 * deployment configuration: Nation-scoped storage bucket naming,
 * classification policy seeding, and Nation tenant bootstrap helpers.
 */

declare(strict_types=1);

namespace Deployer;

require 'vendor/waaseyaa/deployer/recipes/waaseyaa.php';

// ---------------------------------------------------------------------------
// Nation-scoped storage bucket naming
// ---------------------------------------------------------------------------

set('storage_bucket', static fn (string $nation, string $env): string => "anokii-{$nation}-{$env}");

// ---------------------------------------------------------------------------
// Classification policy seed
// ---------------------------------------------------------------------------

/**
 * Seed the Anokii default classification taxonomy on first deploy.
 * Chains after the framework's deploy:writable task so the writable
 * directories exist before config:import runs.
 */
task('anokii:seed:classification', static function (): void {
    run('{{bin/waaseyaa}} config:import config/classification.anokii-default.yaml');
})->desc('Seed Anokii default classification taxonomy');

after('deploy:writable', 'anokii:seed:classification');

// ---------------------------------------------------------------------------
// Nation tenant bootstrap
// ---------------------------------------------------------------------------

/**
 * Bootstrap a Nation tenant config from the Sagamok example stub.
 * Usage: dep anokii:tenant:bootstrap --nation=<nation-short>
 *
 * The target config file is config/tenants/<nation>.yaml. Operators
 * should review and customise the generated file before committing.
 */
task('anokii:tenant:bootstrap', static function (): void {
    $nation = get('nation') ?? throw new \RuntimeException(
        'Pass --nation=<nation-short> to anokii:tenant:bootstrap'
    );
    run("cp config/tenants/sagamok.yaml.example config/tenants/{$nation}.yaml");
    writeln("Tenant stub created: config/tenants/{$nation}.yaml — review and customise before committing.");
})->desc('Bootstrap a Nation tenant config from the Sagamok example stub');
