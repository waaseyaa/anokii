<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Hydration\HydratableFromStorageInterface;
use Waaseyaa\Entity\Hydration\HydrationContext;

/**
 * Shared storage-hydration plumbing for the Anokii relational-graph entities
 * (Community, Place, Organization, Service, Project, Topic).
 *
 * Each concrete subclass declares its own #[ContentEntityType] / #[ContentEntityKeys]
 * attributes and typed #[Field] properties; this base supplies the constructor,
 * factory, and hydration methods so that boilerplate is written once rather than
 * six times. Field values live in the ContentEntityBase value bag (read via
 * get()/set()); cross-entity references are stored by stable slug, not numeric
 * primary key, so seeding stays idempotent and order-independent.
 *
 * Promoted from the oiatc app into the package as the canonical graph model so
 * every consuming install (sovereign or shared-graph) shares one entity shape.
 *
 * @api
 */
abstract class GraphEntityBase extends ContentEntityBase implements HydratableFromStorageInterface
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<string, mixed> $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function make(array $values): static
    {
        return new static($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromStorage(array $values, HydrationContext $context): static
    {
        return new static(
            values: $values,
            entityTypeId: $context->entityTypeId,
            entityKeys: $context->entityKeys,
            fieldDefinitions: [],
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function duplicateInstance(array $values): static
    {
        return new static(
            values: $values,
            entityTypeId: $this->getEntityTypeId(),
            entityKeys: $this->entityKeys,
            fieldDefinitions: $this->getFieldDefinitions(),
        );
    }

    /** Read a field as a trimmed string (null-safe). */
    protected function str(string $field): string
    {
        return (string) ($this->get($field) ?? '');
    }

    public function getSlug(): string
    {
        return $this->str('slug');
    }

    public function getName(): string
    {
        return $this->str('name');
    }
}
