<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Hydration\HydratableFromStorageInterface;
use Waaseyaa\Entity\Hydration\HydrationContext;

/**
 * A retrieval chunk: a heading-delimited passage extracted from one of the
 * install's published pages (or a published news post).
 *
 * This is the content source for the RAG layer. It is an entity (not a raw
 * table) so it keeps both keyword and vector retrieval open and slots into
 * ai-vector's (entity_type, entity_id) keying later: at embed time the vector is
 * stored under entity_type='doc_chunk', entity_id=<this entity's id>, and the
 * displayable text lives here. No embeddings are produced at this stage.
 *
 * `chunk_key` is the stable idempotency key (derived from source_url + heading +
 * part index); ingestion upserts on it so re-runs update rather than duplicate.
 * `entity_type` / `entity_id` link the chunk to its source graph entity (a
 * service or project) by slug; empty for general content that any vantage can
 * reach.
 *
 * @api
 */
#[ContentEntityType(id: 'doc_chunk', label: 'Doc chunk', description: 'A heading-delimited passage from a published page, used as RAG retrieval content.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class DocChunk extends ContentEntityBase implements HydratableFromStorageInterface
{
    #[Field(label: 'Chunk key', description: 'Stable idempotency key: source_url + heading + part index.', required: true, settings: ['weight' => 0])]
    public string $chunk_key = '';

    #[Field(label: 'Source URL', description: 'The published page path this passage came from, e.g. /communities/sagamok.', required: true, settings: ['weight' => 1])]
    public string $source_url = '';

    #[Field(label: 'Title', description: 'The source page title.', required: true, settings: ['weight' => 2])]
    public string $title = '';

    #[Field(label: 'Heading', description: 'The section heading this passage sits under (empty for intro text).', required: false, settings: ['weight' => 3])]
    public string $heading = '';

    #[Field(type: 'text', label: 'Text', description: 'The passage text, whitespace-normalized.', required: true, settings: ['weight' => 4])]
    public string $text = '';

    #[Field(label: 'Source entity type', description: 'Graph entity this chunk belongs to (e.g. service, project). Empty for general content.', required: false, settings: ['weight' => 5])]
    public string $entity_type = '';

    #[Field(label: 'Source entity id', description: 'Slug of the linked source entity (e.g. sagamok-housing, massey-solar).', required: false, settings: ['weight' => 6])]
    public string $entity_id = '';

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
    public static function make(array $values): self
    {
        return new self($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromStorage(array $values, HydrationContext $context): static
    {
        return new self(
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

    public function getChunkKey(): string
    {
        return (string) ($this->get('chunk_key') ?? '');
    }

    public function getSourceUrl(): string
    {
        return (string) ($this->get('source_url') ?? '');
    }

    public function getTitle(): string
    {
        return (string) ($this->get('title') ?? '');
    }

    public function getHeading(): string
    {
        return (string) ($this->get('heading') ?? '');
    }

    public function getText(): string
    {
        return (string) ($this->get('text') ?? '');
    }
}
