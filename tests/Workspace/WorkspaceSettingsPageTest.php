<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use Anokii\Access\AccountBoundary;
use Anokii\Tests\Support\AuditedIdentityRuntime;
use Anokii\Tests\Support\InMemoryUserRepository;
use Anokii\Workspace\Controller\WorkspaceController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\User\User;

/**
 * The workspace shell rendered end to end for a REAL signed-in {@see User}
 * under the framework's sealed field layout: real session lookup through the
 * entity repository, real controller, real Twig.
 *
 * `mail` is Internal and `name` is Protected, so both the settings form and the
 * sidebar user chip must source their values from the audited
 * authenticated-self profile snapshot. Reading them off the entity produced a 500 —
 * these tests assert a 200 with the real values in the markup.
 */
#[CoversClass(WorkspaceController::class)]
#[CoversClass(\Anokii\Shell\Shell::class)]
#[CoversClass(\Anokii\Support\Auth::class)]
final class WorkspaceSettingsPageTest extends TestCase
{
    private AuditedIdentityRuntime $audited;

    protected function setUp(): void
    {
        $this->audited = new AuditedIdentityRuntime();
        SsrServiceProvider::setTwigEnvironment(
            SsrServiceProvider::createTwigEnvironment(dirname(__DIR__, 2)),
        );
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        SsrServiceProvider::setTwigEnvironment(null);
        $_SESSION = [];
    }

    #[Test]
    public function the_settings_page_renders_the_audited_name_and_email(): void
    {
        $response = $this->controller($this->user())->settings(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="Nina Waabi"', (string) $response->getContent());
        self::assertStringContainsString('value="nina@example.test"', (string) $response->getContent());
    }

    #[Test]
    public function the_settings_page_reads_mail_through_the_purpose_limited_audited_capability(): void
    {
        $this->controller($this->user())->settings(new Request());

        self::assertContains(
            'user.self-profile/self_profile/name,mail',
            $this->audited->reads,
            'Sealed mail must be obtained through the account-bound self-profile capability, never off the entity.',
        );
    }

    #[Test]
    public function the_dashboard_renders_for_a_real_sealed_user_without_a_server_error(): void
    {
        $response = $this->controller($this->user())->dashboard(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Welcome, Nina Waabi', (string) $response->getContent());
    }

    #[Test]
    public function the_user_chip_shows_the_real_role_rather_than_degrading_to_member(): void
    {
        $content = (string) $this->controller($this->user())->dashboard(new Request())->getContent();

        self::assertStringContainsString('Editor', $content);
    }

    #[Test]
    public function an_account_with_no_name_falls_back_to_its_email_address(): void
    {
        $nameless = new User([
            'uid' => 21,
            'name' => '',
            'mail' => 'nameless@example.test',
            'roles' => ['viewer'],
            'permissions' => [],
            'status' => true,
        ]);

        $content = (string) $this->controller($nameless)->dashboard(new Request())->getContent();

        self::assertStringContainsString('Welcome, nameless@example.test', $content);
    }

    #[Test]
    public function an_account_with_neither_name_nor_email_gets_a_truthful_placeholder(): void
    {
        // Never invent an identity, and never leak a sealed read attempt as a
        // 500: an account the shell cannot name is labelled by its account id.
        $anonymousish = new User([
            'uid' => 22,
            'name' => '',
            'mail' => '',
            'roles' => [],
            'permissions' => [],
            'status' => true,
        ]);

        $response = $this->controller($anonymousish)->dashboard(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Welcome, Account #22', (string) $response->getContent());
    }

    private function user(): User
    {
        return new User([
            'uid' => 20,
            'name' => 'Nina Waabi',
            'mail' => 'nina@example.test',
            'roles' => ['editor'],
            'permissions' => ['edit pages'],
            'status' => true,
        ]);
    }

    private function controller(User $user): WorkspaceController
    {
        $_SESSION['waaseyaa_uid'] = (string) $user->id();

        return new WorkspaceController(
            $this->entityTypeManager($user),
            $this->audited->internalFieldReader(),
            new AccountBoundary($this->audited->principalFactory(), $this->audited->selfProfileReader()),
        );
    }

    private function entityTypeManager(User $user): EntityTypeManager
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event, ?string $eventName = null): object
            {
                return $event;
            }
        };

        $manager = new EntityTypeManager(
            eventDispatcher: $dispatcher,
            repositoryFactory: static fn(): InMemoryUserRepository => new InMemoryUserRepository($user),
        );
        $manager->registerEntityType(EntityType::fromClass(User::class));

        return $manager;
    }
}
