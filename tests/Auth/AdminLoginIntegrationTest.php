<?php

declare(strict_types=1);

namespace Anokii\Tests\Auth;

use Anokii\Access\AccountBoundary;
use Anokii\Admin\AdminModules;
use Anokii\Admin\AdminShell;
use Anokii\Auth\LoginThrottle;
use Anokii\Dashboard\AdminLoginController;
use Anokii\Dashboard\DashboardGate;
use Anokii\Dashboard\LoginBrand;
use Anokii\Tests\Support\AuditedIdentityRuntime;
use Anokii\Tests\Support\AuthRuntimeSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\AuthManager;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\User;

/**
 * Exercise the shared form controller with real sealed accounts, audited
 * readers, framework authentication policy, sessions and database throttling.
 * Only account storage is replaced with a bounded in-memory repository double.
 */
final class AdminLoginIntegrationTest extends TestCase
{
    private AuditedIdentityRuntime $audited;
    private EntityTypeManager $entities;
    private AuthManager $auth;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->audited = new AuditedIdentityRuntime();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function authorized_login_uses_framework_generation_and_http_principal_then_renders_identity(): void
    {
        $user = $this->user();
        $controller = $this->controller($user);
        $response = $controller->loginSubmit($this->request());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/anokii/analytics', $response->headers->get('Location'));
        self::assertSame(20, $_SESSION[AuthenticatedSession::USER_ID_KEY]);
        self::assertSame(7, $_SESSION[AuthenticatedSession::GENERATION_KEY]);
        self::assertTrue($this->auth->isAuthenticated());

        $accounts = new AccountBoundary($this->audited->principalFactory(), $this->audited->selfProfileReader());
        $context = AdminShell::context($accounts->identity($user), $user->id(), 'analytics', AdminModules::sharedGraph());
        self::assertNotEmpty($context);
        self::assertSame('Test administrator', $accounts->label($user));
        self::assertContains('http.identity-bootstrap/session_bootstrap/roles,permissions,status', $this->audited->reads);
        foreach ($this->audited->reads as $read) {
            self::assertStringNotContainsString('maintenance_cli', $read);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function rejectedLogins(): iterable
    {
        yield 'wrong password' => [[], 'incorrect', false];
        yield 'inactive account' => [['status' => false], 'correct-password', false];
        yield 'missing admin permission' => [['permissions' => []], 'correct-password', false];
        yield 'configured verification requirement' => [['email_verified' => false], 'correct-password', true];
    }

    /** @param array<string, mixed> $overrides */
    #[Test]
    #[DataProvider('rejectedLogins')]
    public function rejected_logins_never_open_an_admin_session(array $overrides, string $password, bool $verificationRequired): void
    {
        $controller = $this->controller($this->user($overrides), $verificationRequired);
        $response = $controller->loginSubmit($this->request($password));
        self::assertSame(401, $response->getStatusCode());
        self::assertArrayNotHasKey(AuthenticatedSession::USER_ID_KEY, $_SESSION);
        self::assertArrayNotHasKey(AuthenticatedSession::GENERATION_KEY, $_SESSION);
    }

    #[Test]
    public function repeated_failed_credentials_are_throttled_and_logout_clears_the_session(): void
    {
        $controller = $this->controller($this->user());
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $controller->loginSubmit($this->request('wrong'))->getStatusCode());
        }
        self::assertSame(429, $controller->loginSubmit($this->request())->getStatusCode());
        $_SESSION = [AuthenticatedSession::USER_ID_KEY => 20, AuthenticatedSession::GENERATION_KEY => 7, 'other' => 'value'];
        self::assertSame('/admin/login', $controller->logout(new Request())->getTargetUrl());
        self::assertSame([], $_SESSION);
    }

    #[Test]
    public function protected_pages_redirect_anonymous_and_deny_accounts_without_permission(): void
    {
        $this->controller($this->user(['permissions' => []]));
        $gate = new class ($this->entities, $this->audited->internalFieldReader(), $this->audited->principalFactory()) extends DashboardGate {
            protected function loginPath(): string
            {
                return '/admin/login';
            }
            public function page(Request $request): Response
            {
                return $this->requirePermission($request, 'access anokii admin') ?? new Response('allowed');
            }
        };
        $request = Request::create('/admin/anokii/contact');
        self::assertSame(302, $gate->page($request)->getStatusCode());
        $_SESSION = [AuthenticatedSession::USER_ID_KEY => 20, AuthenticatedSession::GENERATION_KEY => 7];
        self::assertSame(403, $gate->page($request)->getStatusCode());
    }

    #[Test]
    public function framework_session_resume_rejects_revoked_generation(): void
    {
        $this->controller($this->user());
        $reader = $this->audited->internalFieldReader();
        $middleware = new SessionMiddleware(
            $this->entities->getRepository('user'),
            internalFields: $reader,
            authenticationEligibility: new VerifiedEmailAuthenticationEligibility(AuthConfig::fromArray([], 'testing'), $reader),
        );
        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                $account = $request->attributes->get('_account');
                if (!$account instanceof AccountInterface) {
                    throw new \LogicException('The framework did not resolve an account.');
                }

                return new Response($account->isAuthenticated() ? 'signed in' : 'anonymous');
            }
        };
        foreach ([7 => 'signed in', 6 => 'anonymous'] as $generation => $expected) {
            $_SESSION = [AuthenticatedSession::USER_ID_KEY => 20, AuthenticatedSession::GENERATION_KEY => $generation];
            $request = Request::create('/admin/anokii/contact');
            $request->attributes->set('_session', $_SESSION);
            self::assertSame($expected, $middleware->process($request, $next)->getContent());
        }
        $session = $request->attributes->get('_session');
        self::assertIsArray($session);
        self::assertArrayNotHasKey(AuthenticatedSession::USER_ID_KEY, $session);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function successful_login_rotates_the_native_session_and_logout_destroys_it(): void
    {
        session_cache_limiter('');
        session_start(['use_cookies' => false]);
        try {
            $before = session_id();
            $controller = $this->controller($this->user());
            self::assertSame(302, $controller->loginSubmit($this->request())->getStatusCode());
            self::assertNotSame($before, session_id());
            $controller->logout(new Request());
            self::assertSame(PHP_SESSION_NONE, session_status());
            self::assertSame([], $_SESSION);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function user(array $overrides = []): User
    {
        return new User(array_replace([
            'uid' => 20, 'name' => 'Test administrator', 'mail' => 'admin@example.test',
            'pass' => password_hash('correct-password', PASSWORD_DEFAULT),
            'roles' => ['editor'], 'permissions' => ['access anokii admin'],
            'status' => true, 'email_verified' => true, 'session_generation' => 7,
        ], $overrides));
    }

    private function controller(User $user, bool $verificationRequired = false): AdminLoginController
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('findBy')->willReturnCallback(static fn(array $criteria): array => $criteria === ['mail' => 'admin@example.test'] ? [$user] : []);
        $repository->method('find')->willReturn($user);
        $this->entities = new EntityTypeManager(
            eventDispatcher: new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepositoryInterface => $repository,
        );
        $this->entities->registerEntityType(EntityType::fromClass(User::class));
        $reader = $this->audited->internalFieldReader();
        $this->auth = new AuthManager($reader, new VerifiedEmailAuthenticationEligibility(
            AuthConfig::fromArray(['require_verified_email' => $verificationRequired], 'testing'),
            $reader,
        ));
        $database = DBALDatabase::createSqlite(':memory:');
        AuthRuntimeSchema::install($database);

        return new AdminLoginController(
            $this->entities,
            '/admin/login',
            '/admin/anokii',
            'access anokii admin',
            new LoginBrand(),
            $reader,
            new LoginThrottle(new DatabaseRateLimiter($database)),
            $this->auth,
            $this->audited->principalFactory(),
        );
    }

    private function request(string $password = 'correct-password'): Request
    {
        return Request::create('/admin/login', 'POST', [
            'email' => 'admin@example.test', 'password' => $password, 'next' => '/admin/anokii/analytics',
        ], server: ['REMOTE_ADDR' => '192.0.2.10']);
    }
}
