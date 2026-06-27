<?php

declare(strict_types=1);

namespace Anokii\Workspace\Pages;

/**
 * Optional edge-cache purge hook, invoked after a page's published pointer
 * moves (publish / rollback) so a CDN does not serve stale HTML.
 *
 * The distribution ships no implementation: PagesService treats the purger as a
 * nullable dependency and defaults to no purge, so publish works out of the box.
 * An instance fronted by a CDN (Cloudflare, Fastly, ...) binds its own purger
 * implementing this interface. Implementations must be fail-soft — a purge is
 * cache hygiene, never a correctness step, and must never block a publish.
 *
 * @api
 */
interface CachePurgerInterface
{
    /** Purge the whole site's edge cache. Must not throw on failure. */
    public function purgeAll(): void;
}
