<?php

declare(strict_types=1);

namespace Anokii\Operator\Http;

use Symfony\Component\HttpFoundation\Response;

/** Applies the private operator-surface response contract consistently. */
final class OperatorResponsePolicy
{
    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
