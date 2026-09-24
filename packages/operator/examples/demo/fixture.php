<?php

declare(strict_types=1);

/*
 * Fictional operator demo fixture. "Example Nation", its operator, and every
 * row below are invented; no real Nation, person, or document is used.
 *
 * Run it from the Anokii repository root (see packages/operator/demo/README.md):
 *
 *   ANOKII_OPERATOR_DEMO_AUTOLOAD=vendor/autoload.php \
 *   ANOKII_OPERATOR_DEMO_FIXTURE=packages/operator/examples/demo/fixture.php \
 *   php -S 127.0.0.1:8080 packages/operator/demo/router.php
 */

use Anokii\Operator\Module\OperatorModule;

$icon = static fn(string $path): string => '<path d="' . $path . '" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';

return [
    'brand' => [
        'title' => 'Example Nation',
        'tag' => 'Operator workspace',
        'logo_src' => '/demo-assets/logo.svg',
        'logo_alt' => 'Example Nation',
    ],
    'theme_href' => '/demo-assets/theme.css',
    'operator' => ['name' => 'Sample Operator', 'role' => 'Communications'],
    'dashboard_intro' => 'Fictional workspace for trying operator workflows. Choose a task to get started.',
    'modules' => [
        new OperatorModule('updates', 'Updates', 'Daily work', '/admin/anokii/updates', 'Review sample community updates before they are shared.', $icon('M5 5h14v14H5zM8 9h8M8 13h8M8 16h5')),
        new OperatorModule('records', 'Records', 'Daily work', '/admin/anokii/records', 'Browse sample records kept by the office.', $icon('M4 7h6l2 2h8v10H4z')),
    ],
    'pages' => [
        // A host-owned workflow page: its own markup, styles, and script.
        'updates' => [
            'template' => 'updates.html.twig',
            'eyebrow' => 'Communications',
            'context' => [
                'drafts' => [
                    ['title' => 'Office closed for the holiday', 'audience' => 'Public'],
                    ['title' => 'Community garden sign-up', 'audience' => 'Members only'],
                ],
            ],
        ],
        // The package's generic module page, filled from fixture rows.
        'records' => [
            'context' => [
                'primary_title' => 'Recent records',
                'preview_rows' => [
                    ['title' => 'Sample meeting notes', 'detail' => 'Document', 'state' => 'Filed'],
                    ['title' => 'Sample program form', 'detail' => 'Form', 'state' => 'Review'],
                ],
                'quick_actions' => [['label' => 'Find a record', 'href' => '#records']],
            ],
        ],
    ],
    'templates' => 'templates',
    'assets' => [
        '/demo-assets/theme.css' => 'assets/theme.css',
        '/demo-assets/logo.svg' => 'assets/logo.svg',
    ],
];
