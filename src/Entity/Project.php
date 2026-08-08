<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Anokii\Support\Values;
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
#[ContentEntityType(id: 'project', label: 'Project', description: 'A shared project related to one or more communities.', storageBackend: \Waaseyaa\Entity\Storage\PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Project extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $name = '';

    #[Field(label: 'Slug', required: true, settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(label: 'Relates to', description: 'JSON array of community slugs this project concerns.', required: false, settings: ['weight' => 2], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $relates_to = '';

    #[Field(label: 'Located at', description: 'Place slug.', required: false, settings: ['weight' => 3], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $located_at = '';

    #[Field(label: 'Topic', description: 'Topic slug.', required: false, settings: ['weight' => 4], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $has_topic = '';

    #[Field(label: 'Source URL', required: false, settings: ['weight' => 5], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
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
        return Values::stringList(json_decode($this->str('relates_to'), true));
    }
}
