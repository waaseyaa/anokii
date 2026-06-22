<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;

/**
 * A town or city node in the graph, with coordinates for distance ranking.
 *
 * Latitude/longitude are stored as strings (kept in the _data blob) and cast on
 * read; distance is a ranking signal only and is never shown as a travel time.
 * `travel_note` holds a sourced travel estimate shown verbatim where present
 * (e.g. Elliot Lake), and is never computed or invented.
 *
 * @api
 */
#[ContentEntityType(id: 'place', label: 'Place', description: 'A town or city with coordinates, used for catchment and distance ranking.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Place extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0])]
    public string $name = '';

    #[Field(label: 'Slug', description: 'Stable identifier used in region lists and references.', required: true, settings: ['weight' => 1])]
    public string $slug = '';

    #[Field(label: 'Latitude', required: false, settings: ['weight' => 2])]
    public string $lat = '';

    #[Field(label: 'Longitude', required: false, settings: ['weight' => 3])]
    public string $lng = '';

    #[Field(label: 'Travel note', description: 'Sourced travel estimate, shown verbatim; never computed or invented.', required: false, settings: ['weight' => 4])]
    public string $travel_note = '';

    public function getLat(): float
    {
        return (float) $this->str('lat');
    }

    public function getLng(): float
    {
        return (float) $this->str('lng');
    }

    public function getTravelNote(): string
    {
        return $this->str('travel_note');
    }
}
