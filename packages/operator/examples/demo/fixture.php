<?php

declare(strict_types=1);

/*
 * Fictional operator demo fixture. "Example Nation", its operator, and every
 * source, draft, and submission below are invented; no real Nation, person,
 * or document is used.
 *
 * Two scenarios reuse the shell and the package's shared demo primitives:
 *   - desk: a communications desk with public and members-only flows;
 *   - review: a needs-review workflow.
 * "records" shows the package's generic module page.
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
        new OperatorModule('desk', 'Communications desk', 'Daily work', '/admin/anokii/desk', 'Turn a source document into reviewed updates for the public or for members.', $icon('M4 6h16v10H8l-4 4zM8 10h8M8 13h5')),
        new OperatorModule('review', 'Needs review', 'Daily work', '/admin/anokii/review', 'Approve or send back sample submissions waiting for review.', $icon('M9 11l2 2 4-4M5 4h14v16H5z')),
        new OperatorModule('records', 'Records', 'Office', '/admin/anokii/records', 'Browse sample records kept by the office.', $icon('M4 7h6l2 2h8v10H4z')),
    ],
    'pages' => [
        'desk' => [
            'template' => 'desk.html.twig',
            'eyebrow' => 'Communications',
            'intro' => 'Pick a source, choose who it is for, review every draft, then publish. Everything here is simulated: nothing is uploaded, saved, published, or sent.',
            'context' => [
                'sample_sources' => [
                    ['id' => 'office-hours', 'title' => 'Sample notice: office hours change'],
                    ['id' => 'members-meeting', 'title' => 'Sample agenda: members meeting'],
                ],
                'channels' => [
                    'public' => [
                        ['id' => 'website', 'label' => 'Website news', 'hint' => 'Anyone can read it.', 'draft' => 'The office has new hours starting next month. Visit the office page for the full schedule.'],
                        ['id' => 'newsletter', 'label' => 'Email newsletter', 'hint' => 'Sent to newsletter subscribers.', 'draft' => 'A quick note from the office: our hours are changing next month. Details are on the website.'],
                        ['id' => 'social', 'label' => 'Social media post', 'hint' => 'A short public post.', 'draft' => 'New office hours start next month. See the website for details.'],
                    ],
                    'members' => [
                        ['id' => 'portal', 'label' => 'Members portal', 'hint' => 'Only signed-in members can read it.', 'draft' => 'The members meeting agenda is ready. Please read it before the meeting.'],
                        ['id' => 'members-email', 'label' => 'Email to opted-in members', 'hint' => 'Only members who chose to get email.', 'draft' => 'The agenda for the next members meeting is now in the members portal.'],
                    ],
                ],
                // One channel per audience fails on the first try, so both flows show retry.
                'fail_first' => ['social', 'members-email'],
            ],
        ],
        'review' => [
            'template' => 'review.html.twig',
            'intro' => 'Decide on each sample submission, confirm, then apply. Applying is simulated: nothing is saved or sent.',
            'context' => [
                'items' => [
                    ['id' => 'feast', 'title' => 'Sample event: community feast', 'kind' => 'Event listing', 'submitted' => 'From Sample Staff One', 'summary' => 'A community feast at the sample hall, with a request to list it on the events page.'],
                    ['id' => 'student-job', 'title' => 'Sample job: summer student', 'kind' => 'Job posting', 'submitted' => 'From Sample Staff Two', 'summary' => 'A summer student position at the sample office, closing in three weeks.'],
                    ['id' => 'water', 'title' => 'Sample notice: water advisory lifted', 'kind' => 'Notice', 'submitted' => 'From Sample Staff One', 'summary' => 'A notice that a sample water advisory has ended.'],
                ],
                'fail_first' => ['student-job'],
            ],
        ],
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
        '/demo-assets/scenarios.css' => 'assets/scenarios.css',
        '/demo-assets/desk.js' => 'assets/desk.js',
        '/demo-assets/review.js' => 'assets/review.js',
    ],
];
