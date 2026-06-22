<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;

/**
 * A service: provided_by an Organization, located_at a Place, has_topic a Topic.
 *
 * References are stored by slug. doc_chunk rows link to a Service via
 * (entity_type='service', entity_id=<this slug>); the chunk text is the
 * retrieval/grounding content, while this entity supplies the topic, place, and
 * provider used for relationship and proximity ranking.
 *
 * @api
 */
#[ContentEntityType(id: 'service', label: 'Service', description: 'A service provided by an organization at a place, on a topic.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Service extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0])]
    public string $name = '';

    #[Field(label: 'Slug', required: true, settings: ['weight' => 1])]
    public string $slug = '';

    #[Field(label: 'Provided by', description: 'Organization slug.', required: false, settings: ['weight' => 2])]
    public string $provided_by = '';

    #[Field(label: 'Located at', description: 'Place slug.', required: false, settings: ['weight' => 3])]
    public string $located_at = '';

    #[Field(label: 'Topic', description: 'Topic slug.', required: false, settings: ['weight' => 4])]
    public string $has_topic = '';

    #[Field(label: 'Source URL', required: false, settings: ['weight' => 5])]
    public string $source_url = '';

    public function getLocatedAt(): string
    {
        return $this->str('located_at');
    }

    public function getTopic(): string
    {
        return $this->str('has_topic');
    }
}
