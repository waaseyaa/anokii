<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestPath = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);
    if (is_string($requestPath) && is_file(__DIR__ . $requestPath)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
if (getenv('WAASEYAA_SKIP_DOTENV') !== 'true' && is_file($projectRoot . '/.env')) {
    try {
        new \Symfony\Component\Dotenv\Dotenv()
            ->usePutenv()
            ->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
    } catch (\Symfony\Component\Dotenv\Exception\FormatException|\Symfony\Component\Dotenv\Exception\PathException $exception) {
        http_response_code(500);
        error_log('Anokii configuration error: ' . $exception->getMessage());
        echo 'Application configuration error. Check server logs.';
        exit(1);
    }
}

$handle = static function () use ($projectRoot): void {
    $response = new \Waaseyaa\Foundation\Kernel\HttpKernel($projectRoot)->handle();
    $response->send();
};

if (!function_exists('frankenphp_handle_request')) {
    $handle();

    return;
}

ignore_user_abort(true);
$maxRequests = max(0, (int) (getenv('FRANKENPHP_WORKER_MAX_REQUESTS') ?: 0));
for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests; ++$handled) {
    if (!\frankenphp_handle_request($handle)) {
        break;
    }
    gc_collect_cycles();
}
