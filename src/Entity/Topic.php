<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;

/**
 * A topic in the graph (e.g. housing, health and wellness, energy and solar).
 *
 * Services and Projects reference a topic by slug (has_topic). The canonical
 * topic vocabulary and the keywords used to infer a question's topic live in
 * {@see \Anokii\CoIntelligence\TopicVocabulary}; these entities make the topics
 * first-class nodes in the relational model.
 *
 * @api
 */
#[ContentEntityType(id: 'topic', label: 'Topic', description: 'A subject node that services and projects are tagged with.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Topic extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0])]
    public string $name = '';

    #[Field(label: 'Slug', required: true, settings: ['weight' => 1])]
    public string $slug = '';

    #[Field(label: 'Keywords', description: 'Space-separated keywords used to infer this topic from a question.', settings: ['weight' => 2])]
    public string $keywords = '';
}
