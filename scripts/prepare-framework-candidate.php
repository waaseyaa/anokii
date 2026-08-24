#!/usr/bin/env php
<?php

declare(strict_types=1);

use Anokii\CandidateCi\FrameworkCohort;

require __DIR__ . '/lib/FrameworkCohort.php';

$options = getopt('', ['consumer-root:', 'framework-root:', 'framework-sha:', 'trust-mode:']);

try {
    $consumerRoot = (string) ($options['consumer-root'] ?? '');
    $frameworkRoot = (string) ($options['framework-root'] ?? '');
    $frameworkSha = (string) ($options['framework-sha'] ?? '');
    $trustMode = (string) ($options['trust-mode'] ?? '');

    $evidence = FrameworkCohort::install(
        dirname(__DIR__),
        $consumerRoot,
        $frameworkRoot,
        $frameworkSha,
        $trustMode,
    );
    $hash = FrameworkCohort::assertManifestHash(
        $consumerRoot . '/var/framework-candidate/' . FrameworkCohort::MANIFEST_NAME,
    );
    printf(
        "Prepared %d Framework packages from %s for Anokii %s in %s mode. manifest_sha256=%s\n",
        count($evidence['candidate_packages'] ?? []),
        $frameworkSha,
        (string) ($evidence['consumer_sha'] ?? ''),
        $trustMode,
        $hash,
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'prepare-framework-candidate: ' . $error->getMessage() . "\n");
    exit(1);
}
