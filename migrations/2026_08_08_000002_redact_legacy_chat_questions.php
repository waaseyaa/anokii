<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('chat_query_log')) {
            return;
        }

        $schema->getConnection()->executeStatement(
            "UPDATE chat_query_log SET question = '[redacted]' WHERE question <> '[redacted]'",
        );
    }
};
