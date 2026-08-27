<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Kernel\HttpKernel;

if (PHP_SAPI === 'cli-server') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestPath = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);
    if (is_string($requestPath) && is_file(__DIR__ . $requestPath)) {
        return false;
    }
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

if (getenv('WAASEYAA_SKIP_DOTENV') !== 'true' && is_file($projectRoot . '/.env')) {
    try {
        new Symfony\Component\Dotenv\Dotenv()
            ->usePutenv()
            ->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
    } catch (Symfony\Component\Dotenv\Exception\FormatException|Symfony\Component\Dotenv\Exception\PathException $exception) {
        http_response_code(500);
        error_log('Anokii configuration error: ' . $exception->getMessage());
        echo 'Application configuration error. Check server logs.';
        exit(1);
    }
}

$handle = static function () use ($projectRoot): void {
    try {
        $response = new HttpKernel($projectRoot)->handle();
    } catch (\Throwable $exception) {
        $response = new Response('Application error. Check server logs.', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        error_log('Anokii HTTP error: ' . $exception->getMessage());
    }

    $response->send();
};

$workerMode = ($_SERVER['WAASEYAA_FRANKENPHP_WORKER'] ?? getenv('WAASEYAA_FRANKENPHP_WORKER')) === '1';
if ($workerMode) {
    if (!function_exists('frankenphp_handle_request')) {
        throw new \RuntimeException('FrankenPHP worker mode is enabled but the worker API is unavailable.');
    }

    ignore_user_abort(true);

    $maxRequestsRaw = getenv('FRANKENPHP_WORKER_MAX_REQUESTS');
    $maxRequests = $maxRequestsRaw === false ? 0 : (int) $maxRequestsRaw;

    for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests; ++$handled) {
        $keepRunning = frankenphp_handle_request($handle);
        gc_collect_cycles();
        if (!$keepRunning) {
            break;
        }
    }

    return;
}

$handle();
