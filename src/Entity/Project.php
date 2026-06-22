<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;

/**
 * A project that relates_to many Communities and is located_at a Place. It is a
 * single shared entity (e.g. Massey Solar), never copied per community: the
 * `relates_to` list names every community it concerns by slug.
 *
 * @api
 */
#[ContentEntityType(id: 'project', label: 'Project', description: 'A shared project related to one or more communities.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Project extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0])]
    public string $name = '';

    #[Field(label: 'Slug', required: true, settings: ['weight' => 1])]
    public string $slug = '';

    #[Field(label: 'Relates to', description: 'JSON array of community slugs this project concerns.', settings: ['weight' => 2])]
    public string $relates_to = '';

    #[Field(label: 'Located at', description: 'Place slug.', settings: ['weight' => 3])]
    public string $located_at = '';

    #[Field(label: 'Topic', description: 'Topic slug.', settings: ['weight' => 4])]
    public string $has_topic = '';

    #[Field(label: 'Source URL', settings: ['weight' => 5])]
    public string $source_url = '';

    public function getLocatedAt(): string
    {
        return $this->str('located_at');
    }

    public function getTopic(): string
    {
        return $this->str('has_topic');
    }

    /**
     * @return list<string>
     */
    public function getRelatesTo(): array
    {
        $decoded = json_decode($this->str('relates_to'), true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }
}
