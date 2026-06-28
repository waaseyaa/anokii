<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * A public marketing page, driven from the workspace (Anokii Pages).
 *
 * The public site stops being hand-coded Twig and becomes content held on
 * revisionable `page` entities: the route renders the entity's PUBLISHED
 * revision, editing creates newer draft revisions, and Publish moves the
 * framework's published-revision pointer (see EntityRepository::setPublishedRevision()).
 *
 * Fields (all carried in the automatic `_data` blob, snapshotted per revision):
 *   title             the <title> text (the label key)
 *   path              the public route path, e.g. "/", "/about" (lookup key)
 *   meta_description  <meta name="description"> content; null falls back to the layout default
 *   meta_robots       <meta name="robots"> content; null falls back to "index,follow"
 *   head_styles       per-page extra CSS injected into {% block head_styles %}; null for none
 *   status            "published" once live (informational; the published pointer is authoritative)
 *   blocks            ordered list of content blocks: each `{ "type": "...", ... }`,
 *                     one template partial per block type, rendered in order
 */
#[ContentEntityType(id: 'page', label: 'Page', description: 'A public marketing page rendered from ordered content blocks.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
final class Page extends ContentEntityBase implements RevisionableInterface
{
    public function getTitle(): string
    {
        return (string) ($this->get('title') ?? '');
    }

    public function setTitle(string $title): static
    {
        $this->set('title', $title);

        return $this;
    }

    public function getPath(): string
    {
        return (string) ($this->get('path') ?? '');
    }

    public function setPath(string $path): static
    {
        $this->set('path', $path);

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        $value = $this->get('meta_description');

        return $value === null ? null : (string) $value;
    }

    public function setMetaDescription(?string $description): static
    {
        $this->set('meta_description', $description);

        return $this;
    }

    public function getMetaRobots(): ?string
    {
        $value = $this->get('meta_robots');

        return $value === null ? null : (string) $value;
    }

    public function setMetaRobots(?string $robots): static
    {
        $this->set('meta_robots', $robots);

        return $this;
    }

    public function getHeadStyles(): ?string
    {
        $value = $this->get('head_styles');

        return $value === null ? null : (string) $value;
    }

    public function setHeadStyles(?string $headStyles): static
    {
        $this->set('head_styles', $headStyles);

        return $this;
    }

    public function getStatus(): string
    {
        return (string) ($this->get('status') ?? 'draft');
    }

    public function setStatus(string $status): static
    {
        $this->set('status', $status);

        return $this;
    }

    /**
     * The ordered content blocks. Each block is an associative array with at
     * least a `type` key naming its template partial; remaining keys are that
     * block type's content fields.
     *
     * @return list<array<string, mixed>>
     */
    public function getBlocks(): array
    {
        $blocks = $this->get('blocks');
        if (!\is_array($blocks)) {
            return [];
        }

        /** @var list<array<string, mixed>> $blocks */
        return array_values(array_filter($blocks, '\is_array'));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    public function setBlocks(array $blocks): static
    {
        $this->set('blocks', array_values($blocks));

        return $this;
    }

    /** Write a short summary into the revision log (what this edit changed). */
    public function recordEdit(string $summary): static
    {
        $this->setRevisionLog($summary);

        return $this;
    }

    /** When this revision was created, from the revision metadata. */
    public function getRevisionCreatedAt(): ?\DateTimeImmutable
    {
        return $this->revisionMetadata()?->revisionCreatedAt;
    }
}
