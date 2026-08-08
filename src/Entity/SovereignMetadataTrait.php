<?php

declare(strict_types=1);

namespace Anokii\Entity;

use Anokii\Support\Values;
use Waaseyaa\Entity\Attribute\Field;

/** Shared persisted sovereignty metadata for Anokii-owned content. */
trait SovereignMetadataTrait
{
    #[Field(required: true, label: 'Community', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $community_id = '';

    #[Field(type: 'classification_label', required: true, default: 'nation-restricted', label: 'Classification', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $classification_label = 'nation-restricted';

    #[Field(required: false, label: 'Classification source', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $classification_inherited_from = null;

    #[Field(required: false, label: 'Classification override time', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $classification_overridden_at = null;

    public function getCommunityId(): string
    {
        return Values::str($this->get('community_id'));
    }

    public function setCommunityId(string $communityId): static
    {
        $this->set('community_id', $communityId);

        return $this;
    }

    public function getClassificationLabel(): string
    {
        return Values::str($this->get('classification_label'), 'nation-restricted');
    }

    public function setClassificationLabel(string $label): static
    {
        $this->set('classification_label', $label);

        return $this;
    }
}
