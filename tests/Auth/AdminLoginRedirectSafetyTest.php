<?php

declare(strict_types=1);

namespace Anokii\Tests\Auth;

use Anokii\Auth\LoginThrottle;
use Anokii\Dashboard\AdminLoginController;
use Anokii\Dashboard\LoginBrand;
use Anokii\Tests\Support\FakeInternalFieldReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DBALDatabase;

final class AdminLoginRedirectSafetyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function redirectCases(): iterable
    {
        yield 'posted deep link survives form submit' => ['/admin/pages/12', '/admin/pages/12'];
        yield 'prefix lookalike is rejected' => ['/administrator/phish', '/admin'];
        yield 'login recursion is rejected' => ['/admin/login/again', '/admin'];
        yield 'scheme-relative target is rejected' => ['//evil.example/admin', '/admin'];
    }

    #[Test]
    #[DataProvider('redirectCases')]
    public function redirect_target_is_a_path_boundary_not_a_string_prefix(string $candidate, string $expected): void
    {
        $controller = new AdminLoginController(
            null,
            '/admin/login',
            '/admin',
            null,
            new LoginBrand(),
            new FakeInternalFieldReader(),
            new LoginThrottle(new DatabaseRateLimiter(DBALDatabase::createSqlite(':memory:'))),
        );
        $request = Request::create('/admin/login', 'POST', ['next' => $candidate]);
        $method = new \ReflectionMethod($controller, 'safeNext');

        self::assertSame($expected, $method->invoke($controller, $request));
    }
}
