<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public array $after = ['waaseyaa/field'];

    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('classification_label_definition')) {
            throw new \RuntimeException('The framework classification schema must exist before Anokii labels are seeded.');
        }

        $connection = $schema->getConnection();
        $labels = [
            ['f124d4fa-4300-55ea-9c66-0f904d15886a', 'public', 'Public', 0],
            ['c59f5b19-1bc7-50f9-95c8-990fd60e06da', 'community', 'Community', 10],
            ['1b062d6f-1e4a-5ec0-88a3-60be4b95c643', 'nation-restricted', 'Nation Restricted', 30],
        ];

        foreach ($labels as [$uuid, $labelId, $displayName, $level]) {
            $connection->executeStatement(
                'INSERT INTO classification_label_definition '
                . '(uuid, label_id, display_name, confidentiality_level) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT(label_id) DO NOTHING',
                [$uuid, $labelId, $displayName, $level],
            );
        }
    }
};
