<?php

declare(strict_types=1);

namespace Anokii\Workspace\Controller;

use Anokii\Support\Auth;
use Anokii\Workspace\Analytics\AnalyticsReport;
use Anokii\Workspace\WorkspaceShell;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * First-party analytics inside the Anokii workspace: a self-hosted, cookieless
 * report (pageviews, unique visitors, top pages, referrers, devices) read from
 * the instance's own database, with no third party anywhere. The dashboard is
 * read-only and gated on authentication (any signed-in workspace user); it
 * needs no special permission.
 *
 * @api
 */
final class AnalyticsController
{
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        private readonly AnalyticsReport $report,
        private readonly string $loginPath = '/admin/anokii/login',
    ) {}

    public function index(Request $request): Response
    {
        $user = Auth::currentUser($this->entityTypeManager);
        if ($user === null) {
            return new RedirectResponse($this->loginPath);
        }

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Anokii unavailable: Twig is not initialised.', 500);
        }

        $from = $this->cleanDate($request->query->get('from'), gmdate('Y-m-d', strtotime('-29 days')));
        $to = $this->cleanDate($request->query->get('to'), gmdate('Y-m-d'));

        $context = WorkspaceShell::context($user, 'analytics') + [
            'report' => $this->report->summary($from, $to),
            'range' => ['from' => $from, 'to' => $to],
        ];

        return new Response($twig->render('anokii/analytics.html.twig', $context), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function cleanDate(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $value
            : $fallback;
    }
}
