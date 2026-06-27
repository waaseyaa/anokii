<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Workspace\Pages\PagesService;
use Anokii\Workspace\WorkspaceShell;
use Anokii\Support\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * Anokii Pages: the workspace editor for the public marketing `page` entities.
 * Lists pages with their publish status, edits a page's copy (title, SEO meta,
 * head styles, block fields) as draft revisions, previews a draft through the
 * real block renderer, and publishes / rolls back the live view via the
 * framework's published-revision pointer.
 *
 * Gating mirrors the other tools: page (GET) requests redirect to /admin/anokii/login
 * when signed out; JSON actions return 401. Saving a draft requires `edit
 * pages`; publish / rollback require `publish pages` — both enforced through the
 * shared AccessPolicy (the single source of truth).
 */
final class PagesController
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly PagesService $pages,
        private readonly EntityAccessHandler $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Anokii unavailable: Twig is not initialised.', 500);
        }

        $context = WorkspaceShell::context($user, 'pages') + [
            'editing' => false,
            'pages' => $this->pages->listPages(),
        ];

        return $this->html($twig->render('anokii/pages.html.twig', $context));
    }

    public function edit(Request $request, string $id): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }

        $page = $this->pages->find($id);
        if ($page === null) {
            return new RedirectResponse('/admin/anokii/pages');
        }

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Anokii unavailable: Twig is not initialised.', 500);
        }

        $context = WorkspaceShell::context($user, 'pages') + [
            'editing' => true,
            'page' => [
                'id' => $id,
                'path' => $page->getPath(),
                'title' => $page->getTitle(),
                'meta_description' => $page->getMetaDescription() ?? '',
                'meta_robots' => $page->getMetaRobots() ?? '',
                'head_styles' => $page->getHeadStyles() ?? '',
                'blocks' => $page->getBlocks(),
                'draft_rev' => (int) $page->getRevisionId(),
                'published_rev' => $this->pages->publishedRevisionId($id),
            ],
        ];

        return $this->html($twig->render('anokii/pages.html.twig', $context));
    }

    public function save(Request $request, string $id): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $page = $this->pages->find($id);
        if ($page === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown page.'], 404);
        }
        if (!$this->access->check($page, 'update', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to edit pages.'], 403);
        }

        $decoded = json_decode((string) $request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];

        $fields = [];
        foreach (['title', 'meta_description', 'meta_robots', 'head_styles'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = is_string($data[$key]) ? $data[$key] : '';
            }
        }
        if (array_key_exists('blocks', $data) && is_array($data['blocks'])) {
            $fields['blocks'] = $data['blocks'];
        }

        $draftRev = $this->pages->saveDraft($id, $fields, Auth::label($user));
        if ($draftRev === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not save the draft.'], 422);
        }

        return new JsonResponse([
            'ok' => true,
            'draft_rev' => $draftRev,
            'published_rev' => $this->pages->publishedRevisionId($id),
        ]);
    }

    public function publish(Request $request, string $id): Response
    {
        return $this->moveLive($request, $id, static fn(PagesService $s) => $s->publish($id), 'publish');
    }

    public function rollback(Request $request, string $id): Response
    {
        $decoded = json_decode((string) $request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];
        $rev = (int) ($data['rev'] ?? 0);
        if ($rev <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'Missing revision.'], 422);
        }

        return $this->moveLive($request, $id, static fn(PagesService $s) => $s->rollbackPublished($id, $rev), 'publish');
    }

    /** Per-page revision history (live / draft flags) for the history panel. */
    public function history(Request $request, string $id): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $revisions = [];
        foreach ($this->pages->listHistory($id) as $rev) {
            $revisions[] = [
                'rev' => $rev['rev'],
                'log' => $rev['log'],
                'when' => $rev['when']?->format('M j, Y g:i A') . ' UTC',
                'is_published' => $rev['is_published'],
                'is_draft' => $rev['is_draft'],
            ];
        }
        if ($revisions === []) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown page.'], 404);
        }

        return new JsonResponse(['ok' => true, 'revisions' => $revisions]);
    }

    /**
     * Preview the current DRAFT of a page through the real public block renderer
     * (templates/page.html.twig + the block partials), so an editor sees exactly
     * what publishing would make live. Signed-in workspace users only.
     */
    public function preview(Request $request, string $id): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse('/admin/anokii/login');
        }

        $page = $this->pages->find($id);
        if ($page === null) {
            return new Response('Page not found.', 404);
        }

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Preview unavailable: Twig is not initialised.', 500);
        }

        $html = $twig->render('page.html.twig', [
            'path' => $page->getPath(),
            'page' => [
                'title' => $page->getTitle(),
                'meta_description' => $page->getMetaDescription(),
                'meta_robots' => 'noindex,nofollow',
                'head_styles' => $page->getHeadStyles(),
                'blocks' => $page->getBlocks(),
            ],
        ]);

        return $this->html($html);
    }

    /**
     * Shared publish/rollback path: gate on `publish pages`, run the move, report
     * the new live revision.
     *
     * @param callable(PagesService):?int $move
     */
    private function moveLive(Request $request, string $id, callable $move, string $operation): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $page = $this->pages->find($id);
        if ($page === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown page.'], 404);
        }
        if (!$this->access->check($page, $operation, $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to publish pages.'], 403);
        }

        $publishedRev = $move($this->pages);
        if ($publishedRev === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not update the live page.'], 422);
        }

        return new JsonResponse(['ok' => true, 'published_rev' => $publishedRev]);
    }

    private function html(string $body): Response
    {
        return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
