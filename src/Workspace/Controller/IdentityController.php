<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Entity\Pillar;
use Anokii\Support\Auth;
use Anokii\Workspace\Identity\PillarService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * The Identity Workspace, entity-native rebuild. Renders the pillars grouped by
 * section with a computed maturity bar, and persists status + notes edits as
 * revisions of the `identity_pillar` entity (full per-pillar history,
 * attribution). Status and notes are the editable fields; the rest of a pillar
 * is read-only here (the chat agent CRUDs it).
 *
 * The section taxonomy (grouping headers) is instance content, supplied as a
 * constructor arg; the distribution ships no hardcoded sections. A pillar whose
 * section key is not in the supplied taxonomy is simply not grouped into the
 * rendered output.
 *
 * Routes are registered ->allowAll() and this controller enforces the session:
 * page requests redirect to /admin/anokii/login, JSON actions return 401.
 */
final class IdentityController
{
    /**
     * @param array<string, array{no:string, title:string, sub:string}> $sections
     *        section taxonomy keyed by section key, in display order
     */
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly PillarService $pillars,
        private readonly EntityAccessHandler $access,
        private readonly array $sections = [],
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

        // Group pillars by section, preserving the section order.
        $sections = [];
        foreach ($this->sections as $key => $meta) {
            $sections[$key] = $meta + ['key' => $key, 'pillars' => []];
        }
        foreach ($this->pillars->listPillars() as $pillar) {
            $key = $pillar->getSection();
            if (isset($sections[$key])) {
                $sections[$key]['pillars'][] = $this->presentPillar($pillar);
            }
        }

        $context = \Anokii\Workspace\WorkspaceShell::context($user, 'identity') + [
            'sections' => array_values($sections),
            'counts' => $this->pillars->statusCounts(),
            'statuses' => [
                ['v' => 'defined', 't' => 'Defined'],
                ['v' => 'draft', 't' => 'Draft / legacy'],
                ['v' => 'work', 't' => 'Needs work'],
                ['v' => 'gap', 't' => 'Gap'],
            ],
        ];

        return new Response(
            $twig->render('anokii/identity.html.twig', $context),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function save(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $decoded = json_decode((string) $request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];

        $pid = trim((string) ($data['pid'] ?? ''));
        if ($pid === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing pillar id.'], 422);
        }

        // Access: the pillar must exist, and the account must be allowed to edit
        // it (the AccessPolicy is the single source of truth).
        $pillar = $this->pillars->findByPid($pid);
        if ($pillar === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown pillar.'], 422);
        }
        if (!$this->access->check($pillar, 'update', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to edit the Identity Workspace.'], 403);
        }

        $status = array_key_exists('status', $data) ? (string) $data['status'] : null;
        $notes = array_key_exists('notes', $data) ? (string) $data['notes'] : null;

        $result = $this->pillars->update($pid, $status, $notes, Auth::label($user));
        if ($result === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown pillar or nothing to update.'], 422);
        }

        return new JsonResponse([
            'ok' => true,
            'last_edited_by' => $result['editor_label'],
            'last_edited_at' => $this->humanStamp($result['updated_at']),
            'counts' => $this->pillars->statusCounts(),
        ]);
    }

    /** Per-pillar revision history (who changed what, when) for the history panel. */
    public function history(Request $request, string $pid): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $revisions = [];
        foreach ($this->pillars->listHistory($pid) as $rev) {
            $revisions[] = $this->presentRevision($rev);
        }
        if ($revisions === []) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown pillar.'], 404);
        }

        return new JsonResponse(['ok' => true, 'revisions' => $revisions]);
    }

    /**
     * Save (or update) a peer-language translation of a pillar's moat fields
     * (title + body). Gated by the same edit permission as a default-language
     * edit; the peer `(id, langcode)` row and its per-language revision are
     * written atomically by the framework.
     */
    public function saveTranslation(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }

        $decoded = json_decode((string) $request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];

        $pid = trim((string) ($data['pid'] ?? ''));
        $langcode = trim((string) ($data['langcode'] ?? ''));
        if ($pid === '' || $langcode === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing pillar id or language.'], 422);
        }
        if (!$this->pillars->isTranslationLangcode($langcode)) {
            return new JsonResponse(['ok' => false, 'error' => 'Unsupported language.'], 422);
        }

        $pillar = $this->pillars->findByPid($pid);
        if ($pillar === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown pillar.'], 422);
        }
        if (!$this->access->check($pillar, 'update', $user)->isAllowed()) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to edit the Identity Workspace.'], 403);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $body = (string) ($data['body'] ?? '');

        $result = $this->pillars->saveTranslation($pid, $langcode, $title, $body, Auth::label($user));
        if ($result === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not save the translation.'], 422);
        }

        return new JsonResponse([
            'ok' => true,
            'langcode' => $langcode,
            'last_edited_by' => $result['editor_label'],
            'last_edited_at' => $this->humanStamp($result['updated_at']),
        ]);
    }

    /** Per-language revision history for one pillar translation (independent timeline). */
    public function translationHistory(Request $request, string $pid, string $langcode): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Not signed in.'], 401);
        }
        if (!$this->pillars->isTranslationLangcode($langcode)) {
            return new JsonResponse(['ok' => false, 'error' => 'Unsupported language.'], 422);
        }

        $revisions = [];
        foreach ($this->pillars->listTranslationHistory($pid, $langcode) as $rev) {
            $revisions[] = $this->presentTranslationRevision($rev);
        }

        return new JsonResponse(['ok' => true, 'langcode' => $langcode, 'revisions' => $revisions]);
    }

    /** @return array<string,mixed> */
    private function presentPillar(Pillar $pillar): array
    {
        return [
            'pid' => $pillar->getPid(),
            'section' => $pillar->getSection(),
            'title' => $pillar->getTitle(),
            'now_label' => $pillar->getNowLabel(),
            'body' => $pillar->getBody(),
            'is_quote' => $pillar->isQuote(),
            'decide_label' => $pillar->getDecideLabel(),
            'decision' => $pillar->getDecision(),
            'status' => $pillar->getStatus(),
            'notes' => $pillar->getNotes(),
            'pills' => $pillar->getPills(),
            'is_full' => $pillar->isFull(),
            'last_edited_by' => $pillar->getEditorLabel(),
            'last_edited_at' => $this->humanStamp($pillar->getUpdatedAt()),
            'translations' => $this->presentTranslations($pillar),
        ];
    }

    /**
     * The peer-language translations of a pillar: for each supported language,
     * its current title/body + attribution, or an untranslated placeholder.
     *
     * @return list<array<string,mixed>>
     */
    private function presentTranslations(Pillar $pillar): array
    {
        $out = [];
        foreach ($this->pillars->translations() as $langcode => $endonym) {
            $translation = $this->pillars->getTranslation($pillar->getPid(), $langcode);
            $out[] = [
                'langcode' => $langcode,
                'endonym' => $endonym,
                'translated' => $translation !== null,
                'title' => $translation?->getTitle() ?? '',
                'body' => $translation?->getBody() ?? '',
                'last_edited_by' => $translation?->getEditorLabel() ?? '',
                'last_edited_at' => $translation !== null ? $this->humanStamp($translation->getUpdatedAt()) : '',
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function presentTranslationRevision(Pillar $rev): array
    {
        $created = $rev->getRevisionCreatedAt();
        $when = $rev->getUpdatedAt() !== ''
            ? $this->humanStamp($rev->getUpdatedAt())
            : ($created?->format('M j, Y g:i A') . ' UTC');

        return [
            'vid' => (int) $rev->getRevisionId(),
            'title' => $rev->getTitle(),
            'summary' => $rev->getRevisionLog(),
            'editor' => $rev->getEditorLabel() !== '' ? $rev->getEditorLabel() : 'System',
            'when' => $when,
            'is_current' => $rev->isCurrentRevision(),
        ];
    }

    /** @return array<string,mixed> */
    private function presentRevision(Pillar $rev): array
    {
        $created = $rev->getRevisionCreatedAt();
        // Prefer the preserved edit stamp (faithful across the migration); fall
        // back to the revision metadata time.
        $when = $rev->getUpdatedAt() !== ''
            ? $this->humanStamp($rev->getUpdatedAt())
            : ($created?->format('M j, Y g:i A') . ' UTC');

        return [
            'vid' => (int) $rev->getRevisionId(),
            'status' => $rev->getStatus(),
            'summary' => $rev->getRevisionLog(),
            'editor' => $rev->getEditorLabel() !== '' ? $rev->getEditorLabel() : 'System',
            // revision_author (framework, alpha.205+), falling back to the
            // editor_uid snapshot old revisions carry in _data.
            'editor_uid' => $rev->getEditorUid(),
            'when' => $when,
            'is_current' => $rev->isCurrentRevision(),
        ];
    }

    private function humanStamp(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return '';
        }
        $ts = strtotime($iso);

        return $ts === false ? $iso : gmdate('M j, Y g:i A', $ts) . ' UTC';
    }
}
