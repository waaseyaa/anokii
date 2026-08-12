<?php

declare(strict_types=1);

namespace Anokii\Operator\Module;

/** One authorized module shown in an Anokii operator workspace. */
final readonly class OperatorModule
{
    public function __construct(
        public string $id,
        public string $label,
        public string $group,
        public string $href,
        public string $description,
        public string $icon = '',
        public bool $dashboardTile = true,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw new \InvalidArgumentException('Operator module ids must be lowercase slugs.');
        }
        if (trim($label) === '' || trim($group) === '' || trim($description) === '') {
            throw new \InvalidArgumentException('Operator modules require a label, group, and description.');
        }
        if (!str_starts_with($href, '/admin/anokii')) {
            throw new \InvalidArgumentException('Operator module routes must stay under /admin/anokii.');
        }
    }

    /** @return array{id:string,label:string,group:string,href:string,description:string,icon:string,dashboard_tile:bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'group' => $this->group,
            'href' => $this->href,
            'description' => $this->description,
            'icon' => $this->icon,
            'dashboard_tile' => $this->dashboardTile,
        ];
    }
}
