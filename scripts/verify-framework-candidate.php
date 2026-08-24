#!/usr/bin/env php
<?php

declare(strict_types=1);

use Anokii\CandidateCi\FrameworkCohort;

require __DIR__ . '/lib/FrameworkCohort.php';

$options = getopt('', ['consumer-root:', 'framework-root:', 'framework-sha:', 'manifest-sha256::']);

try {
    $report = FrameworkCohort::verify(
        (string) ($options['consumer-root'] ?? ''),
        (string) ($options['framework-root'] ?? ''),
        (string) ($options['framework-sha'] ?? ''),
        isset($options['manifest-sha256']) ? (string) $options['manifest-sha256'] : null,
    );
    printf(
        "Verified %d Framework packages at %s in %s mode. manifest_sha256=%s\n",
        (int) $report['package_count'],
        (string) $report['framework_sha'],
        (string) $report['trust_mode'],
        (string) $report['manifest_sha256'],
    );
    foreach ($report['reflections'] as $name => $reflection) {
        printf("  %s reflected %s from %s\n", $name, $reflection['symbol'], $reflection['file']);
    }
    foreach ($report['anokii_dev_main_packages'] as $name => $version) {
        printf("  %s preserved at %s\n", $name, $version);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'verify-framework-candidate: ' . $error->getMessage() . "\n");
    exit(1);
}
