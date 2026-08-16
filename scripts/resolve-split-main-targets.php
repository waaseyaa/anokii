#!/usr/bin/env php
<?php

declare(strict_types=1);

$allowlist = [
    'core' => ['local' => 'packages/core', 'remote' => 'anokii-core'],
    'identity' => ['local' => 'packages/identity', 'remote' => 'anokii-identity'],
    'operator' => ['local' => 'packages/operator', 'remote' => 'anokii-operator'],
];

$selected = [];
foreach (explode(',', $argv[1] ?? '') as $candidate) {
    $name = trim($candidate);
    if ($name === '') {
        continue;
    }
    if (!isset($allowlist[$name])) {
        fwrite(STDERR, sprintf("Split-main target '%s' is not allowlisted.\n", $name));
        exit(2);
    }
    $selected[$name] = true;
}

if ($selected === []) {
    fwrite(STDERR, "Select at least one allowlisted split-main package.\n");
    exit(2);
}

$include = [];
foreach (array_keys($selected) as $name) {
    $include[] = $allowlist[$name];
}

echo json_encode(['include' => $include], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
