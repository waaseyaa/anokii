<?php

declare(strict_types=1);

namespace Anokii\Provider;

use Anokii\Access\AccountBoundary;
use Anokii\Access\WorkspaceRoles;
use Anokii\Admin\AdminTemplates;
use Anokii\Auth\LoginThrottle;
use Anokii\Auth\SetupTokenRepository;
use Anokii\Dashboard\WorkspaceLoginController;
use Anokii\Entity\ContactSubmission;
use Anokii\Entity\Document;
use Anokii\Entity\DocumentNote;
use Anokii\Entity\DriveFile;
use Anokii\Entity\Page;
use Anokii\Entity\Pillar;
use Anokii\Workspace\Analytics\AnalyticsCollector;
use Anokii\Workspace\Analytics\AnalyticsEndpoint;
use Anokii\Workspace\Analytics\AnalyticsReport;
use Anokii\Workspace\Controller\AnalyticsController;
use Anokii\Workspace\Controller\DocumentsController;
use Anokii\Workspace\Controller\DriveController;
use Anokii\Workspace\Controller\IdentityController;
use Anokii\Workspace\Controller\InboxController;
use Anokii\Workspace\Controller\PagesController;
use Anokii\Workspace\Controller\WorkspaceController;
use Anokii\Workspace\Documents\DocumentService;
use Anokii\Workspace\Documents\DocumentStorage;
use Anokii\Workspace\Documents\GotenbergClient;
use Anokii\Workspace\Drive\DriveFileService;
use Anokii\Workspace\Drive\DriveStorage;
use Anokii\Workspace\Identity\PillarService;
use Anokii\Workspace\Pages\PagesService;
use Anokii\Workspace\Pages\PublishedPageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\User\UserSelfProfileReaderInterface;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Community\CommunityContextInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * Wires the Anokii login-gated workspace at /admin/anokii/*: the shell (login,
 * logout, dashboard, settings, set-password) and the baseline tools shipped by
 * the distribution — Identity, Documents, Drive, Pages, Inbox, and the canonical
 * first-party Analytics (plus the public cookieless collector at /api/collect).
 *
 * This is the piece that was previously re-implemented in every consuming app.
 * Routes register ->allowAll() at the framework layer; each controller enforces
 * the session via {@see \Anokii\Dashboard\DashboardGate}/{@see \Anokii\Support\Auth}
 * and redirects unauthenticated page requests to /admin/anokii/login (401 for JSON).
 * Route priority 100 beats the admin SPA's GET catch-all at /admin/{path}.
 *
 * The gated Co-Intelligence chat surface and any bespoke per-app tools are NOT
 * wired here (the chat lands in a later increment; bespoke tools stay app-side).
 *
 * @api
 */
final class WorkspaceServiceProvider extends ServiceProvider implements ProvidesRolesInterface
{
    /**
     * Route priority for the workspace. Beats the admin SPA's GET catch-all at
     * /admin/{path} (waaseyaa/admin-surface, priority 0).
     */
    private const ROUTE_PRIORITY = 100;

    private ?DatabaseInterface $db = null;

    public function register(): void
    {
        // The workspace entities. A package's entities are not auto-discovered
        // from an app's src/, so register them explicitly. db:init --sync-schema
        // then materializes their tables on the instance's database.
        $tenancy = ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY];
        $this->entityType(EntityType::fromClass(Pillar::class, revisionable: true, revisionDefault: true, translatable: true, tenancy: $tenancy));
        $this->entityType(EntityType::fromClass(Document::class, revisionable: true, revisionDefault: true, tenancy: $tenancy));
        $this->entityType(EntityType::fromClass(DriveFile::class, revisionable: true, revisionDefault: true, tenancy: $tenancy));
        $this->entityType(EntityType::fromClass(Page::class, revisionable: true, revisionDefault: true, tenancy: $tenancy));
        $this->entityType(EntityType::fromClass(DocumentNote::class, tenancy: $tenancy));
        $this->entityType(EntityType::fromClass(ContactSubmission::class, tenancy: $tenancy));
    }

    public function boot(): void
    {
        if (!$this->kernelPresent()) {
            return;
        }
        // Supporting tables are owned by versioned app migrations. Provider
        // boot must not perform schema writes on ordinary HTTP requests.

        $this->registerPackageTemplates();
    }

    /**
     * Make the package's shared Twig templates (templates/anokii/*) resolvable on
     * the SSR Twig environment, both unprefixed (so `anokii/...` resolves) and
     * under @anokiipkg. One spot; covers every workspace tool controller.
     */
    private function registerPackageTemplates(): void
    {
        try {
            $twig = \Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment();
            if ($twig === null) {
                return;
            }
            $pkg = AdminTemplates::path();
            $loader = $twig->getLoader();
            if ($loader instanceof \Twig\Loader\ChainLoader) {
                $fs = new \Twig\Loader\FilesystemLoader();
                $fs->addPath($pkg);
                $fs->addPath($pkg . '/anokii', 'anokiipkg');
                $loader->addLoader($fs);
            } elseif ($loader instanceof \Twig\Loader\FilesystemLoader) {
                $loader->addPath($pkg);
                $loader->addPath($pkg . '/anokii', 'anokiipkg');
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $access = WorkspaceRoles::handler();

        // Audited authority for User internals (credentials/roles) — required
        // by login + password flows on framework ≥ alpha.269 (sealed fields).
        $internalFieldReader = $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class);
        \assert($internalFieldReader instanceof \Waaseyaa\Access\User\UserInternalFieldReaderInterface);
        $principalFactory = $this->resolve(AccountPrincipalFactoryInterface::class);
        $profileReader = $this->resolve(UserSelfProfileReaderInterface::class);
        \assert($principalFactory instanceof AccountPrincipalFactoryInterface);
        \assert($profileReader instanceof UserSelfProfileReaderInterface);
        $accounts = new AccountBoundary($principalFactory, $profileReader);
        $communityContext = $this->resolve(CommunityContextInterface::class);
        if (!$communityContext instanceof CommunityContextInterface || !$communityContext->isActive()) {
            throw new \RuntimeException('The workspace requires an active community context.');
        }
        $communityId = $communityContext->get();
        if (!is_string($communityId) || trim($communityId) === '') {
            throw new \RuntimeException('The workspace requires a non-empty active community id.');
        }
        $logger = $this->resolve(LoggerInterface::class);
        if (!$logger instanceof LoggerInterface) {
            throw new \RuntimeException('The workspace requires the framework logger binding.');
        }
        $loginThrottle = new LoginThrottle(new DatabaseRateLimiter($this->db()));

        $shell = new WorkspaceController($entityTypeManager, $internalFieldReader, $accounts);
        $login = new WorkspaceLoginController(
            $entityTypeManager,
            new SetupTokenRepository($this->db()),
            '/admin/anokii/login',
            '/admin/anokii',
            'anokii/login.html.twig',
            'anokii/set-password.html.twig',
            $internalFieldReader,
            $loginThrottle,
        );
        $identity = new IdentityController(
            $entityTypeManager,
            new PillarService($entityTypeManager, communityId: $communityId),
            $access,
            $accounts,
            $logger,
        );
        $documents = new DocumentsController(
            $entityTypeManager,
            new DocumentService(
                $entityTypeManager,
                new DocumentStorage($this->filesDir(), $this->documentMimeTypes(), $this->uploadMaxBytes()),
                new GotenbergClient($this->gotenbergUrl()),
                $communityId,
            ),
            new DocumentStorage($this->filesDir(), $this->documentMimeTypes(), $this->uploadMaxBytes()),
            $access,
            $accounts,
            $logger,
        );
        $drive = new DriveController(
            $entityTypeManager,
            new DriveFileService($entityTypeManager, $communityId),
            new DriveStorage($this->filesDir()),
            $access,
            $accounts,
            $logger,
        );
        $pageService = new PagesService($entityTypeManager, communityId: $communityId);
        $pages = new PagesController(
            $entityTypeManager,
            $pageService,
            $access,
            $accounts,
            $logger,
        );
        $inbox = new InboxController($entityTypeManager, $access, $accounts);
        $analytics = new AnalyticsController($entityTypeManager, new AnalyticsReport($this->db()), $accounts);

        $get = static fn(string $name, string $path, callable $c) => $router->addRoute(
            $name,
            RouteBuilder::create($path)->controller($c)->allowAll()->methods('GET')->priority(self::ROUTE_PRIORITY)->build(),
        );
        $post = static fn(string $name, string $path, callable $c) => $router->addRoute(
            $name,
            RouteBuilder::create($path)->controller($c)->allowAll()->methods('POST')->priority(self::ROUTE_PRIORITY)->build(),
        );

        // CMS-owned public routes. The route table is rebuilt per request in
        // the current kernel, so a newly created page is available immediately.
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($entityTypeManager !== null && $twig !== null) {
            $renderer = new PublishedPageRenderer(
                $entityTypeManager->getRepository('page'),
                $twig,
                $this->projectRoot . '/public',
            );
            foreach ($pageService->listPages() as $pageRow) {
                $path = $pageRow['path'];
                if ($path === '' || $path === '/admin' || str_starts_with($path, '/admin/') || $path === '/api' || str_starts_with($path, '/api/')) {
                    continue;
                }
                $get(
                    'anokii.public.page.' . hash('sha256', $path),
                    $path,
                    static function (Request $request) use ($renderer, $path): Response {
                        $html = $renderer->render($path);

                        return $html === null
                            ? new Response('Page not found.', 404)
                            : new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                    },
                );
            }
        }

        // Shell + auth
        $get('anokii.home', '/admin/anokii', fn(Request $r) => $shell->dashboard($r));
        $get('anokii.login', '/admin/anokii/login', fn(Request $r) => $login->loginForm($r));
        $post('anokii.login.post', '/admin/anokii/login', fn(Request $r) => $login->loginSubmit($r));
        $get('anokii.logout', '/admin/anokii/logout', fn(Request $r) => $login->logout($r));
        $get('anokii.settings', '/admin/anokii/settings', fn(Request $r) => $shell->settings($r));
        $post('anokii.settings.post', '/admin/anokii/settings', fn(Request $r) => $shell->settingsSave($r));
        $get('anokii.setpw', '/admin/anokii/set-password', fn(Request $r) => $login->setPasswordForm($r));
        $post('anokii.setpw.post', '/admin/anokii/set-password', fn(Request $r) => $login->setPasswordSubmit($r));

        // Identity
        $get('anokii.identity', '/admin/anokii/identity', fn(Request $r) => $identity->index($r));
        $post('anokii.identity.create', '/admin/anokii/identity/create', fn(Request $r) => $identity->create($r));
        $post('anokii.identity.save', '/admin/anokii/identity/save', fn(Request $r) => $identity->save($r));
        $get('anokii.identity.history', '/admin/anokii/identity/{pid}/history', fn(Request $r, string $pid) => $identity->history($r, $pid));
        $post('anokii.identity.translate', '/admin/anokii/identity/translate', fn(Request $r) => $identity->saveTranslation($r));
        $get('anokii.identity.thistory', '/admin/anokii/identity/{pid}/{langcode}/history', fn(Request $r, string $pid, string $langcode) => $identity->translationHistory($r, $pid, $langcode));

        // Pages
        $get('anokii.pages', '/admin/anokii/pages', fn(Request $r) => $pages->index($r));
        $post('anokii.pages.create', '/admin/anokii/pages/create', fn(Request $r) => $pages->create($r));
        $get('anokii.pages.edit', '/admin/anokii/pages/{id}', fn(Request $r, string $id) => $pages->edit($r, $id));
        $get('anokii.pages.preview', '/admin/anokii/pages/{id}/preview', fn(Request $r, string $id) => $pages->preview($r, $id));
        $get('anokii.pages.history', '/admin/anokii/pages/{id}/history', fn(Request $r, string $id) => $pages->history($r, $id));
        $post('anokii.pages.save', '/admin/anokii/pages/{id}/save', fn(Request $r, string $id) => $pages->save($r, $id));
        $post('anokii.pages.publish', '/admin/anokii/pages/{id}/publish', fn(Request $r, string $id) => $pages->publish($r, $id));
        $post('anokii.pages.rollback', '/admin/anokii/pages/{id}/rollback', fn(Request $r, string $id) => $pages->rollback($r, $id));

        // Documents
        $get('anokii.documents', '/admin/anokii/documents', fn(Request $r) => $documents->index($r));
        $post('anokii.documents.create', '/admin/anokii/documents/create', fn(Request $r) => $documents->create($r));
        $get('anokii.documents.show', '/admin/anokii/documents/{uuid}', fn(Request $r, string $uuid) => $documents->show($r, $uuid));
        $post('anokii.documents.version', '/admin/anokii/documents/{uuid}/version', fn(Request $r, string $uuid) => $documents->uploadVersion($r, $uuid));
        $post('anokii.documents.setcurrent', '/admin/anokii/documents/{uuid}/set-current', fn(Request $r, string $uuid) => $documents->setCurrent($r, $uuid));
        $post('anokii.documents.rollback', '/admin/anokii/documents/{uuid}/rollback', fn(Request $r, string $uuid) => $documents->rollback($r, $uuid));
        $post('anokii.documents.note', '/admin/anokii/documents/{uuid}/note', fn(Request $r, string $uuid) => $documents->addNote($r, $uuid));
        $get('anokii.documents.file', '/admin/anokii/documents/{uuid}/file/{vid}/{kind}', fn(Request $r, string $uuid, string $vid, string $kind) => $documents->download($r, $uuid, $vid, $kind));

        // Drive
        $get('anokii.drive', '/admin/anokii/drive', fn(Request $r) => $drive->index($r));
        $post('anokii.drive.upload', '/admin/anokii/drive/upload', fn(Request $r) => $drive->upload($r));
        $get('anokii.drive.file', '/admin/anokii/drive/file/{id}', fn(Request $r, string $id) => $drive->download($r, $id));
        $post('anokii.drive.delete', '/admin/anokii/drive/delete', fn(Request $r) => $drive->delete($r));

        // Inbox
        $get('anokii.inbox', '/admin/anokii/inbox', fn(Request $r) => $inbox->index($r));
        $post('anokii.inbox.read', '/admin/anokii/inbox/read', fn(Request $r) => $inbox->markAllRead($r));

        // Analytics dashboard (gated) + public cookieless collector
        $get('anokii.analytics', '/admin/anokii/analytics', fn(Request $r) => $analytics->index($r));
        $privacySecret = getenv('ANOKII_PRIVACY_SECRET') ?: (getenv('WAASEYAA_JWT_SECRET') ?: '');
        $collector = new AnalyticsEndpoint(
            strlen($privacySecret) >= 32 ? new AnalyticsCollector($this->db(), $privacySecret) : null,
            new DatabaseRateLimiter($this->db()),
        );
        $router->addRoute(
            'anokii.collect',
            RouteBuilder::create('/api/collect')
                ->controller(static fn(Request $r): Response => $collector->handle($r))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        // Coming-soon placeholder for preview modules.
        $get('anokii.module', '/admin/anokii/m/{module}', fn(Request $r, string $module) => $shell->comingSoon($r, $module));
    }

    /**
     * Contribute the workspace roles (administrator / editor / viewer) to the
     * framework RoleRepository so `user:assign-role` can resolve them and stamp
     * their permissions.
     *
     * @return iterable<\Waaseyaa\User\Role>
     */
    public function roles(): iterable
    {
        yield from new WorkspaceRoles()->roles();
    }

    private function db(): DatabaseInterface
    {
        if ($this->db instanceof DatabaseInterface) {
            return $this->db;
        }
        $resolved = $this->resolve(DatabaseInterface::class);
        if (!$resolved instanceof DatabaseInterface) {
            throw new \RuntimeException('The Anokii workspace requires the kernel DatabaseInterface binding.');
        }

        return $this->db = $resolved;
    }

    private function filesDir(): string
    {
        $env = getenv('WAASEYAA_FILES_DIR') ?: '';

        return $env !== '' ? $env : $this->projectRoot . '/storage/files';
    }

    private function gotenbergUrl(): string
    {
        $env = getenv('GOTENBERG_URL');

        return is_string($env) ? trim($env) : '';
    }

    private function uploadMaxBytes(): int
    {
        return 10 * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    private function documentMimeTypes(): array
    {
        return [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    private function kernelPresent(): bool
    {
        try {
            return $this->resolve(DatabaseInterface::class) instanceof DatabaseInterface;
        } catch (\Throwable) {
            return false;
        }
    }
}
