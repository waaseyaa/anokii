<?php

declare(strict_types=1);

namespace Anokii\Tests\Access;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkspaceAccessBoundaryStructureTest extends TestCase
{
    #[Test]
    public function every_workspace_entity_decision_uses_the_canonical_principal_boundary(): void
    {
        $controllerDir = dirname(__DIR__, 2) . '/src/Workspace/Controller';
        $files = glob($controllerDir . '/*Controller.php') ?: [];
        $decisions = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/\$this->access->(?:check|checkCreateAccess)\([^;]+\)/s', $source, $matches);
            foreach ($matches[0] as $decision) {
                $decisions[] = basename($file) . ': ' . $decision;
                $canonical = str_contains($decision, '$this->accounts->principal($user)')
                    || (str_contains($decision, '$principal') && str_contains($source, '$principal = $this->accounts->principal($user)'));
                self::assertTrue(
                    $canonical,
                    basename($file) . ' bypasses AccountBoundary when making an entity access decision.',
                );
            }
        }

        self::assertCount(17, $decisions, 'Update the guarded decision inventory when adding or removing a workspace access check.');
    }

    #[Test]
    public function provider_wires_only_framework_owned_audited_account_services(): void
    {
        self::assertTrue(interface_exists(\Waaseyaa\Access\User\UserSelfProfileReaderInterface::class));
        self::assertTrue(class_exists(\Waaseyaa\Audit\Bootstrap\AuditedUserSelfProfileReader::class));
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Provider/WorkspaceServiceProvider.php');

        self::assertStringContainsString('resolve(AccountPrincipalFactoryInterface::class)', $source);
        self::assertStringContainsString('resolve(UserSelfProfileReaderInterface::class)', $source);
        self::assertStringNotContainsString('new AuthorizationPrincipal(', $source);
        self::assertStringNotContainsString('sessionIdentity($user)', $source);
        self::assertStringNotContainsString('maintenanceAuthorization($user)', $source);
    }
}
