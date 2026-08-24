#!/usr/bin/env php
<?php

declare(strict_types=1);

use Anokii\CandidateCi\FrameworkCohort;

require __DIR__ . '/lib/FrameworkCohort.php';

$options = getopt('', ['work-root:', 'framework-version:']);

try {
    $workRoot = (string) ($options['work-root'] ?? '');
    $evidence = FrameworkCohort::adopt(
        dirname(__DIR__),
        $workRoot,
        (string) ($options['framework-version'] ?? ''),
    );
    $hash = FrameworkCohort::assertManifestHash(
        $workRoot . '/var/framework-adoption/' . FrameworkCohort::MANIFEST_NAME,
    );
    $classification = $evidence['classification'];
    printf(
        "Adopted Framework %s. manifest_sha256=%s\n",
        (string) $evidence['framework_version'],
        $hash,
    );
    foreach ($classification as $class => $movements) {
        printf(
            "  %s: %d changed, %d added, %d removed, %d unchanged\n",
            $class,
            count($movements['changed']),
            count($movements['added']),
            count($movements['removed']),
            count($movements['unchanged']),
        );
        foreach ($movements['changed'] as $entry) {
            printf("    %s %s -> %s\n", $entry['name'], $entry['from'], $entry['to']);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'adopt-framework-release: ' . $error->getMessage() . "\n");
    exit(1);
}
