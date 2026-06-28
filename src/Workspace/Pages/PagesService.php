<?php

declare(strict_types=1);

namespace Anokii\Workspace\Pages;

use Anokii\Entity\Page;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Orchestrates Anokii Pages: the workspace editor for the public marketing
 * `page` entities, over the framework's revision + published-pointer model.
 *
 * Two pointers per page (framework, on the base table):
 *   - revision_id            the latest/working revision (the DRAFT)
 *   - published_revision_id  what the public site renders (the LIVE view)
 *
 * Editing saves a NEW revision (the draft moves ahead); Publish points the live
 * view at the current draft; Rollback points the live view at an older revision
 * (an instant revert, no new revision, the draft untouched). The public page
 * route reads loadPublishedRevision(); this service is the only writer.
 *
 * Edge-cache hygiene after a publish/rollback is delegated to an optional
 * {@see CachePurgerInterface}. The distribution ships none, so the purger
 * defaults to null and publish works without any CDN integration; an instance
 * fronted by a CDN injects its own purger.
 */
final class PagesService
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly ?CachePurgerInterface $purger = null,
    ) {}

    /**
     * All pages with their publish status, ordered by path.
     *
     * @return list<array{
     *   id:string, path:string, title:string,
     *   draft_rev:int, published_rev:?int,
     *   has_unpublished_changes:bool, is_live:bool
     * }>
     */
    public function listPages(): array
    {
        $rows = [];
        foreach ($this->pages()->findBy([]) as $entity) {
            if (!$entity instanceof Page) {
                continue;
            }
            $id = (string) $entity->id();
            $publishedRev = $this->publishedRevisionId($id);
            $draftRev = (int) $entity->getRevisionId();
            $rows[] = [
                'id' => $id,
                'path' => $entity->getPath(),
                'title' => $entity->getTitle(),
                'draft_rev' => $draftRev,
                'published_rev' => $publishedRev,
                'has_unpublished_changes' => $publishedRev === null || $publishedRev !== $draftRev,
                'is_live' => $publishedRev !== null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);

        return $rows;
    }

    /** The current (draft / working) revision of a page, or null. */
    public function find(string $id): ?Page
    {
        $page = $this->pages()->find($id);

        return $page instanceof Page ? $page : null;
    }

    /**
     * Apply edits (title, meta, head styles, blocks) and save them as a NEW
     * draft revision. Returns the new revision id, or null when the page is
     * unknown. The published pointer is untouched — the live view does not move
     * until publish().
     *
     * @param array{
     *   title?:string, meta_description?:?string, meta_robots?:?string,
     *   head_styles?:?string, blocks?:list<array<string,mixed>>
     * } $fields
     */
    public function saveDraft(string $id, array $fields, string $editorLabel): ?int
    {
        $page = $this->find($id);
        if ($page === null) {
            return null;
        }

        if (array_key_exists('title', $fields)) {
            $page->setTitle((string) $fields['title']);
        }
        if (array_key_exists('meta_description', $fields)) {
            $page->setMetaDescription($this->nullableString($fields['meta_description']));
        }
        if (array_key_exists('meta_robots', $fields)) {
            $page->setMetaRobots($this->nullableString($fields['meta_robots']));
        }
        if (array_key_exists('head_styles', $fields)) {
            $page->setHeadStyles($this->nullableString($fields['head_styles']));
        }
        if (array_key_exists('blocks', $fields) && is_array($fields['blocks'])) {
            $page->setBlocks($this->normalizeBlocks($fields['blocks']));
        }

        $label = $editorLabel !== '' ? $editorLabel : 'A workspace editor';
        $page->recordEdit($label . ' saved a draft of ' . ($page->getPath() ?: $page->getTitle()));
        $this->pages()->save($page);

        return (int) $page->getRevisionId();
    }

    /**
     * Publish: point the live view at the current draft revision. Returns the
     * now-published revision id, or null when the page is unknown.
     */
    public function publish(string $id): ?int
    {
        $page = $this->find($id);
        if ($page === null) {
            return null;
        }
        $rev = (int) $page->getRevisionId();
        $this->pages()->setPublishedRevision($id, $rev);
        $this->purgeEdgeCache();

        return $rev;
    }

    /**
     * Roll the live view back to an existing revision (an instant revert; the
     * draft is left where it is). Returns the now-published revision id, or null
     * when the page or revision is unknown.
     */
    public function rollbackPublished(string $id, int $revisionId): ?int
    {
        if ($this->pages()->loadRevision($id, $revisionId) === null) {
            return null;
        }
        $this->pages()->setPublishedRevision($id, $revisionId);
        $this->purgeEdgeCache();

        return $revisionId;
    }

    /**
     * Edge-cache hygiene after the published pointer moves. Fail-soft: the
     * publish/rollback has already happened; a missing purger or a purge error
     * must never surface as a publish failure. No purger is configured by
     * default, in which case this is a no-op.
     */
    private function purgeEdgeCache(): void
    {
        try {
            $this->purger?->purgeAll();
        } catch (\Throwable) {
            // Swallowed deliberately; the published pointer is already flipped.
        }
    }

    /**
     * Revision history for a page, newest first, each flagged live / draft.
     *
     * @return list<array{rev:int, log:string, when:?\DateTimeImmutable, is_published:bool, is_draft:bool}>
     */
    public function listHistory(string $id): array
    {
        $publishedRev = $this->publishedRevisionId($id);
        $draftRev = $this->find($id)?->getRevisionId();

        $history = [];
        foreach ($this->pages()->listRevisions($id) as $rev) {
            if (!$rev instanceof Page) {
                continue;
            }
            $revId = (int) $rev->getRevisionId();
            $history[] = [
                'rev' => $revId,
                'log' => $rev->getRevisionLog(),
                'when' => $rev->getRevisionCreatedAt(),
                'is_published' => $revId === $publishedRev,
                'is_draft' => $revId === (int) $draftRev,
            ];
        }

        return $history;
    }

    /** The published revision id of a page, or null when nothing is live. */
    public function publishedRevisionId(string $id): ?int
    {
        $published = $this->pages()->loadPublishedRevision($id);

        return $published instanceof Page ? (int) $published->getRevisionId() : null;
    }

    /**
     * Keep only well-formed blocks: a list of arrays each carrying a non-empty
     * string `type`. Field values are passed through unchanged (the editor edits
     * scalar copy; nested fields are preserved verbatim).
     *
     * @param array<int|string, mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private function normalizeBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['type']) && is_string($block['type']) && $block['type'] !== '') {
                /** @var array<string, mixed> $block */
                $out[] = $block;
            }
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function pages(): EntityRepositoryInterface
    {
        if ($this->entityTypeManager === null) {
            throw new \LogicException('PagesService requires a booted kernel (EntityTypeManager).');
        }

        return $this->entityTypeManager->getRepository('page');
    }
}
