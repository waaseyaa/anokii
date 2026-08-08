<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Anokii\Support\Values;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;

/**
 * A community: a vantage point onto the shared graph, not a walled tenant.
 *
 * `located_at` is the slug of the community's own Place; `region` is a curated,
 * author-supplied catchment of Place slugs (ordered by distance) that the
 * retriever may reach into. Resources are never copied per community; cross-
 * community reach is expressed through the region and through shared Projects.
 *
 * @api
 */
#[ContentEntityType(id: 'community', label: 'Community', description: 'A vantage community onto the shared graph, with a curated region catchment.', storageBackend: \Waaseyaa\Entity\Storage\PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Community extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $name = '';

    #[Field(label: 'Slug', description: 'Stable vantage identifier used in the URL and the /api/chat community parameter.', required: true, settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(label: 'Located at', description: 'Slug of this community\'s own Place.', required: false, settings: ['weight' => 2], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $located_at = '';

    #[Field(label: 'Region', description: 'JSON array of Place slugs forming the curated catchment, ordered by distance.', required: false, settings: ['weight' => 3], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $region = '';

    public function getLocatedAt(): string
    {
        return $this->str('located_at');
    }

    /**
     * @return list<string>
     */
    public function getRegion(): array
    {
        return Values::stringList(json_decode($this->str('region'), true));
    }
}
