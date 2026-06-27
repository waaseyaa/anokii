<?php

declare(strict_types=1);

namespace Anokii\Workspace\Pages;

use Anokii\Entity\Page;
use Twig\Environment;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Renders the PUBLISHED revision of a public `page` entity through the shared
 * block renderer (templates/page.html.twig + templates/blocks/*).
 *
 * This is the single render path for the public site copy: the public page
 * route serves it over HTTP. Draft revisions are invisible here by construction
 * — only loadPublishedRevision() is ever read.
 *
 * The block partials referenced by `page.html.twig` are APP-PROVIDED: each
 * block `type` maps to a template the instance ships, so the set of renderable
 * blocks is instance-specific. The renderer renders whatever block templates
 * the app provides.
 */
final class PublishedPageRenderer
{
    /**
     * @param string|null $publicDir Absolute path to the instance's public web
     *                               root, used to measure photo dimensions for
     *                               photo_strip block enrichment. When null,
     *                               that enrichment is skipped (the template's
     *                               CSS fallback applies).
     */
    public function __construct(
        private readonly EntityRepositoryInterface $pages,
        private readonly Environment $twig,
        private readonly ?string $publicDir = null,
    ) {}

    /**
     * Render the published revision of the page at the given path, or null
     * when no page exists there or nothing is published yet.
     */
    public function render(string $path): ?string
    {
        $page = $this->loadPublishedByPath($path);
        if ($page === null) {
            return null;
        }

        return $this->twig->render('page.html.twig', [
            'path' => $path,
            'page' => [
                'title' => $page->getTitle(),
                'meta_description' => $page->getMetaDescription(),
                'meta_robots' => $page->getMetaRobots(),
                'head_styles' => $page->getHeadStyles(),
                'blocks' => $this->enrichPhotoStrips($page->getBlocks()),
            ],
        ]);
    }

    /**
     * Render-time enrichment for photo_strip blocks: measure each photo's
     * intrinsic dimensions from the shipped file so the template can emit the
     * aspect ratio as a CSS custom property (the equal-height row math).
     * Computed per render, never stored — page content stays untouched. A
     * missing or unreadable file simply gets no ratio (the CSS falls back).
     * Skipped entirely when no public directory is configured.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function enrichPhotoStrips(array $blocks): array
    {
        if ($this->publicDir === null) {
            return $blocks;
        }
        foreach ($blocks as $bi => $block) {
            if (($block['type'] ?? '') !== 'photo_strip' || !is_array($block['photos'] ?? null)) {
                continue;
            }
            foreach ($block['photos'] as $pi => $photo) {
                $src = (string) ($photo['src'] ?? '');
                if ($src === '' || !str_starts_with($src, '/')) {
                    continue;
                }
                $file = $this->publicDir . $src;
                if (!is_file($file)) {
                    continue;
                }
                $size = @getimagesize($file);
                if ($size === false || $size[0] < 1 || $size[1] < 1) {
                    continue;
                }
                $blocks[$bi]['photos'][$pi]['_w'] = $size[0];
                $blocks[$bi]['photos'][$pi]['_h'] = $size[1];
            }
        }

        return $blocks;
    }

    /**
     * Find the page whose `path` matches, then load its published revision.
     * Returns null when no page is seeded for the path or nothing is published.
     */
    public function loadPublishedByPath(string $path): ?Page
    {
        $entityId = null;
        foreach ($this->pages->findBy(['path' => $path]) as $candidate) {
            if ($candidate instanceof Page) {
                $entityId = (string) $candidate->id();
                break;
            }
        }

        if ($entityId === null) {
            return null;
        }

        $published = $this->pages->loadPublishedRevision($entityId);

        return $published instanceof Page ? $published : null;
    }
}
