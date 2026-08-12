<?php

declare(strict_types=1);

use Anokii\Operator\Module\OperatorModule;
use Anokii\Operator\Shell\OperatorShell;
use Anokii\Operator\Template\OperatorTemplates;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$autoloadCandidates = array_filter([
    getenv('ANOKII_PREVIEW_AUTOLOAD') ?: null,
    dirname(__DIR__, 3).'/vendor/autoload.php',
    dirname(__DIR__, 4).'/anokii/vendor/autoload.php',
]);
$vendor = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $vendor = $candidate;
        break;
    }
}
if ($vendor === null) {
    http_response_code(500);
    echo 'Preview autoloader not found. Set ANOKII_PREVIEW_AUTOLOAD.';
    return;
}
require $vendor;
require_once dirname(__DIR__).'/src/Module/OperatorModule.php';
require_once dirname(__DIR__).'/src/Shell/OperatorShell.php';
require_once dirname(__DIR__).'/src/Template/OperatorTemplates.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/theme.css') {
    header('Content-Type: text/css; charset=UTF-8');
    readfile(__DIR__.'/sfn-operator-theme.css');
    return;
}
if ($path === '/logo.png') {
    $logoCandidates = array_filter([
        getenv('SHEG_PREVIEW_LOGO') ?: null,
        dirname(__DIR__, 4).'/sheg-staging-readiness-20260812/public/media/2024/02/Sheg-FN-logo-vector.png',
        dirname(__DIR__, 4).'/sheg-waaseyaa-pass1/public/media/2024/02/Sheg-FN-logo-vector.png',
    ]);
    $logo = null;
    foreach ($logoCandidates as $candidate) {
        if (is_file($candidate)) {
            $logo = $candidate;
            break;
        }
    }
    if ($logo === null) {
        http_response_code(404);
        return;
    }
    header('Content-Type: image/png');
    readfile($logo);
    return;
}

$icon = static fn(string $path): string => '<path d="'.$path.'" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
$modules = [
    new OperatorModule('website', 'Website', 'Daily work', '/admin/anokii/website', 'Create, review, and publish pages, updates, events, jobs, and announcements.', $icon('M4 5h16v14H4zM4 9h16M8 13h8M8 16h5')),
    new OperatorModule('media', 'Media', 'Daily work', '/admin/anokii/media', 'Upload and organize images and documents used on the website.', $icon('M4 7h6l2 2h8v10H4z')),
    new OperatorModule('members', 'Members', 'Member services', '/admin/anokii/members', 'Manage consent-gated members portal access and account status.', $icon('M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm8-1a2.5 2.5 0 1 0 0-5M3 20c.6-4 2.7-6 5-6s4.4 2 5 6m0-6c2.8 0 4.8 1.7 5 5')),
    new OperatorModule('identity', 'Identity', 'Nation profile', '/admin/anokii/identity', 'Review the Nation profile and identity pillars.', $icon('M12 4l7 3v5c0 4-3 7-7 8-4-1-7-4-7-8V7z')),
];

$context = OperatorShell::context($modules, '', 'Russell Jones', 'Administrator', [
    'brand_title' => 'Sheguiandah First Nation',
    'brand_tag' => 'Anokii workspace',
    'brand_logo_src' => '/logo.png',
    'brand_logo_alt' => 'Sheguiandah First Nation',
    'theme_href' => '/theme.css',
    'logout_path' => '#sign-out-preview',
    'page_title' => 'Workspace',
    'page_intro' => 'Your website and members portal tools live here. Choose a task to get started.',
]);

$moduleId = match ($path) {
    '/admin/anokii/website' => 'website',
    '/admin/anokii/media' => 'media',
    '/admin/anokii/members' => 'members',
    '/admin/anokii/identity' => 'identity',
    default => '',
};
$twig = new Environment(new FilesystemLoader(), ['strict_variables' => true, 'autoescape' => 'html']);
OperatorTemplates::register($twig);
if ($moduleId === '') {
    echo $twig->render('@anokii_operator/dashboard.html.twig', $context);
    return;
}

$copy = [
    'website' => ['Website', 'Communications', 'Manage the public website without leaving Anokii.', 'Recently updated content', [['Community update', 'Edited today', 'Draft'], ['Employment page', 'Published yesterday', 'Published'], ['Community gathering', 'Review requested', 'Review']], ['Create content', 'Find content', 'Review drafts']],
    'media' => ['Media', 'Communications', 'Find and upload approved website images and documents.', 'Recent media', [['Council notice PDF', 'Document', 'Ready'], ['Community gathering', 'Image', 'Published'], ['Application form', 'Members document', 'Protected']], ['Upload media', 'Browse images', 'Browse documents']],
    'members' => ['Members', 'Member services', 'Manage consent-gated access to the members portal.', 'Recent access work', [['Test Member One', 'Consent verified', 'Enabled'], ['Test Member Two', 'Credential reset required', 'Action needed'], ['Test Member Three', 'Access disabled', 'Disabled']], ['Start intake', 'Find a member', 'Review recent activity']],
    'identity' => ['Identity', 'Nation profile', 'Review the Nation profile used across Anokii.', 'Identity pillars', [['Who we are', 'Nation profile', 'Current'], ['How we work', 'Operating principles', 'Current'], ['Where data lives', 'Residency record', 'Review']], ['Review profile', 'View revisions', 'Translation status']],
][$moduleId];
$context = OperatorShell::context($modules, $moduleId, 'Russell Jones', 'Administrator', array_replace($context, [
    'page_title' => $copy[0], 'page_eyebrow' => $copy[1], 'page_intro' => $copy[2],
    'primary_title' => $copy[3],
    'primary_intro' => 'Representative local content only. No production data is used.',
    'preview_rows' => array_map(static fn(array $row): array => ['title' => $row[0], 'detail' => $row[1], 'state' => $row[2]], $copy[4]),
    'quick_actions' => array_map(static fn(string $label): array => ['label' => $label, 'href' => '#preview'], $copy[5]),
]));
echo $twig->render('@anokii_operator/module-preview.html.twig', $context);
