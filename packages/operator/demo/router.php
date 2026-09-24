<?php

declare(strict_types=1);

/*
 * PHP built-in server router for a fixture-driven Anokii operator demo.
 *
 *   ANOKII_OPERATOR_DEMO_AUTOLOAD=vendor/autoload.php \
 *   ANOKII_OPERATOR_DEMO_FIXTURE=path/to/fixture.php \
 *   php -S 127.0.0.1:8080 vendor/waaseyaa/anokii-operator/demo/router.php
 *
 * Relative paths resolve against the directory php -S was started in. See
 * README.md next to this file. Every request is answered here, so php -S never
 * serves files from its document root, and any SAPI other than the built-in
 * server gets a 404.
 */

use Anokii\Operator\Demo\OperatorDemo;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);

    return;
}

$fail = static function (string $message): void {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo $message, "\n";
};

$autoload = getenv('ANOKII_OPERATOR_DEMO_AUTOLOAD');
if (!is_string($autoload) || $autoload === '' || !is_file($autoload)) {
    $fail("Set ANOKII_OPERATOR_DEMO_AUTOLOAD to your project's vendor/autoload.php.");

    return;
}
$fixture = getenv('ANOKII_OPERATOR_DEMO_FIXTURE');
if (!is_string($fixture) || $fixture === '') {
    $fail('Set ANOKII_OPERATOR_DEMO_FIXTURE to your demo fixture file.');

    return;
}

require_once $autoload;

try {
    $request = Request::createFromGlobals();
    OperatorDemo::fromFixtureFile($fixture)->handle($request)->prepare($request)->send();
} catch (Throwable $e) {
    $fail('Demo failed: ' . $e::class . ': ' . $e->getMessage());
}
