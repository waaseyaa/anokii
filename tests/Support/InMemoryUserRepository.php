<?php

declare(strict_types=1);

namespace Anokii\Tests\Support;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * Minimal in-memory `user` repository so a controller test can resolve the
 * session account the way {@see \Anokii\Support\Auth::currentUser()} really
 * does — through EntityTypeManager::getRepository('user')->find() — while the
 * entity itself stays a real, sealed {@see \Waaseyaa\User\User}.
 *
 * Only the operations the workspace session path uses are implemented; every
 * other repository operation throws, so a test that strays outside the covered
 * surface fails loudly instead of silently getting an empty result.
 */
final class InMemoryUserRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> */
    private array $entities = [];

    /** @var list<EntityInterface> */
    public array $saved = [];

    public function __construct(EntityInterface ...$users)
    {
        foreach ($users as $user) {
            $this->entities[(string) $user->id()] = $user;
        }
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $existing = isset($this->entities[(string) $entity->id()]);
        $this->entities[(string) $entity->id()] = $entity;
        $this->saved[] = $entity;

        return $existing ? 2 : 1;
    }

    public function exists(string $id): bool
    {
        return isset($this->entities[$id]);
    }

    public function count(array $criteria = []): int
    {
        return count($this->entities);
    }

    public function create(array $values = []): EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function getQuery(): EntityQueryInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function delete(EntityInterface $entity): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function listRevisions(string $entityId): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function loadWorkingCopy(string $id): ?EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function deleteMany(array $entities): int
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function findTranslations(EntityInterface $entity): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    private function unsupported(string $method): \LogicException
    {
        return new \LogicException(sprintf('%s::%s() is not part of the covered test surface.', self::class, $method));
    }
}
