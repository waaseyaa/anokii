<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;

/**
 * An organization that provides Services (e.g. a band department or a regional
 * agency). Public, sourced information only; no member data.
 *
 * @api
 */
#[ContentEntityType(id: 'organization', label: 'Organization', description: 'A provider of services in the graph.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
final class Organization extends GraphEntityBase
{
    #[Field(label: 'Name', required: true, settings: ['weight' => 0])]
    public string $name = '';

    #[Field(label: 'Slug', required: true, settings: ['weight' => 1])]
    public string $slug = '';

    #[Field(label: 'Source URL', description: 'Public page this organization is described on.', settings: ['weight' => 2])]
    public string $source_url = '';
}
