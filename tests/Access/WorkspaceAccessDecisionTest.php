<?php

declare(strict_types=1);

namespace Anokii\Tests\Access;

use Anokii\Access\AccountBoundary;
use Anokii\Access\PageAccessPolicy;
use Anokii\Access\WorkspaceRoles;
use Anokii\Entity\Document;
use Anokii\Entity\Page;
use Anokii\Tests\Support\AuditedIdentityRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\User\User;

/**
 * Workspace access decisions over REAL hydrated {@see User} entities under the
 * framework's sealed field layout (`roles`/`permissions`/`mail` are Internal),
 * the real {@see EntityAccessHandler} from {@see WorkspaceRoles::handler()}, and
 * the real audited identity stack.
 *
 * Two things are pinned here:
 *
 *   1. The handler refuses a mutable account outright
 *      (EntityAccessHandler::assertImmutableDecisionAccount), so every call site
 *      must snapshot the User at the audited identity boundary first. That
 *      boundary is {@see AccountBoundary} — the single Anokii helper.
 *   2. The resulting decisions still carry the workspace role/permission
 *      semantics: an editor may edit, a viewer may not, and neither path throws.
 *
 * A StubUser would pass these tests even if the audited derivation were broken;
 * real Users under a sealed layout are the point.
 */
#[CoversClass(AccountBoundary::class)]
#[CoversClass(PageAccessPolicy::class)]
#[CoversClass(\Anokii\Access\AbstractEntityAccessPolicy::class)]
final class WorkspaceAccessDecisionTest extends TestCase
{
    private AuditedIdentityRuntime $audited;

    private AccountBoundary $accounts;

    private EntityAccessHandler $access;

    protected function setUp(): void
    {
        $this->audited = new AuditedIdentityRuntime();
        $this->accounts = new AccountBoundary(
            $this->audited->principalFactory(),
            $this->audited->selfProfileReader(),
        );
        $this->access = WorkspaceRoles::handler();
    }

    #[Test]
    public function a_real_sealed_user_cannot_be_handed_to_the_access_handler_directly(): void
    {
        // The defect this remediation closes: every call site used to pass the
        // mutable User straight through. Pinned so a regression is loud.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Access decisions require an immutable AuthorizationPrincipal');

        new \ReflectionMethod($this->access, 'check')->invoke($this->access, $this->page(), 'update', $this->editor());
    }

    #[Test]
    public function the_boundary_derives_an_immutable_principal_carrying_the_sealed_claims(): void
    {
        $principal = $this->accounts->principal($this->editor());

        self::assertInstanceOf(AuthorizationPrincipalInterface::class, $principal);
        self::assertSame(['editor'], $principal->getRoles());
        self::assertTrue($principal->hasPermission(WorkspaceRoles::EDIT_PAGES));
        self::assertTrue($principal->isAuthenticated());
        self::assertContains(
            'http.identity-bootstrap/session_bootstrap/roles,permissions,status',
            $this->audited->reads,
            'The principal must come from the audited identity-bootstrap capability.',
        );
    }

    #[Test]
    public function a_privileged_editor_may_update_a_page(): void
    {
        $principal = $this->accounts->principal($this->editor());

        self::assertTrue($this->access->check($this->page(), 'update', $principal)->isAllowed());
    }

    #[Test]
    public function an_unprivileged_viewer_may_not_update_a_page(): void
    {
        $principal = $this->accounts->principal($this->viewer());

        self::assertFalse($this->access->check($this->page(), 'update', $principal)->isAllowed());
    }

    #[Test]
    public function publishing_a_page_needs_more_than_the_edit_permission(): void
    {
        $editorWithoutPublish = $this->accounts->principal($this->user(
            uid: 11,
            roles: ['editor'],
            permissions: [WorkspaceRoles::EDIT_PAGES],
        ));
        $publisher = $this->accounts->principal($this->user(
            uid: 12,
            roles: ['editor'],
            permissions: [WorkspaceRoles::EDIT_PAGES, WorkspaceRoles::PUBLISH_PAGES],
        ));

        self::assertFalse($this->access->check($this->page(), 'publish', $editorWithoutPublish)->isAllowed());
        self::assertTrue($this->access->check($this->page(), 'publish', $publisher)->isAllowed());
    }

    #[Test]
    public function create_access_follows_the_entity_edit_permission(): void
    {
        $documentEditor = $this->accounts->principal($this->user(
            uid: 13,
            roles: ['editor'],
            permissions: [WorkspaceRoles::EDIT_DOCUMENTS],
        ));
        $pageEditor = $this->accounts->principal($this->user(
            uid: 14,
            roles: ['editor'],
            permissions: [WorkspaceRoles::EDIT_PAGES],
        ));

        self::assertTrue($this->access->checkCreateAccess('document', '', $documentEditor)->isAllowed());
        self::assertFalse($this->access->checkCreateAccess('document', '', $pageEditor)->isAllowed());
    }

    #[Test]
    public function the_administrator_role_keeps_its_blanket_grant(): void
    {
        $admin = $this->accounts->principal($this->user(
            uid: 15,
            roles: [WorkspaceRoles::ROLE_ADMIN],
            permissions: [],
        ));

        self::assertTrue($this->access->check($this->page(), 'update', $admin)->isAllowed());
        self::assertTrue($this->access->check($this->page(), 'delete', $admin)->isAllowed());
        self::assertTrue($this->access->checkCreateAccess('document', '', $admin)->isAllowed());
    }

    #[Test]
    public function a_signed_in_account_may_read_a_workspace_entity(): void
    {
        $viewer = $this->accounts->principal($this->viewer());

        self::assertTrue($this->access->check($this->page(), 'view', $viewer)->isAllowed());
        self::assertTrue($this->access->check($this->document(), 'view', $viewer)->isAllowed());
    }

    #[Test]
    public function every_decision_over_a_real_sealed_user_completes_without_throwing(): void
    {
        // The sealed-field regression surfaced in production as a 500, not a 403.
        // Every operation on every workspace entity type must resolve to a
        // boolean decision, never an exception.
        $completed = 0;
        foreach ([$this->editor(), $this->viewer(), $this->blocked()] as $user) {
            $principal = $this->accounts->principal($user);
            foreach ([$this->page(), $this->document()] as $entity) {
                foreach (['view', 'update', 'delete', 'publish'] as $operation) {
                    $this->access->check($entity, $operation, $principal)->isAllowed();
                    ++$completed;
                }
            }
            foreach (['page', 'document', 'document_note', 'drive_asset', 'identity_pillar'] as $type) {
                $this->access->checkCreateAccess($type, '', $principal)->isAllowed();
                ++$completed;
            }
        }

        self::assertSame(39, $completed);
    }

    #[Test]
    public function a_policy_invoked_directly_with_a_mutable_user_fails_closed(): void
    {
        // Defence in depth: the handler already refuses a mutable account, but a
        // policy must never consult one either — reading sealed roles would
        // throw, and guessing would be worse than denying.
        $policy = new PageAccessPolicy();
        $user = $this->editor();

        $access = new \ReflectionMethod($policy, 'access');
        $create = new \ReflectionMethod($policy, 'createAccess');
        self::assertFalse($this->invokeDecision($access, $policy, $this->page(), 'update', $user)->isAllowed());
        self::assertFalse($this->invokeDecision($access, $policy, $this->page(), 'publish', $user)->isAllowed());
        self::assertFalse($this->invokeDecision($access, $policy, $this->page(), 'view', $user)->isAllowed());
        self::assertFalse($this->invokeDecision($create, $policy, 'page', '', $user)->isAllowed());
    }

    private function editor(): User
    {
        return $this->user(uid: 7, roles: ['editor'], permissions: [WorkspaceRoles::EDIT_PAGES]);
    }

    private function viewer(): User
    {
        return $this->user(uid: 8, roles: [WorkspaceRoles::ROLE_VIEWER], permissions: []);
    }

    private function blocked(): User
    {
        return $this->user(uid: 9, roles: ['editor'], permissions: [WorkspaceRoles::EDIT_PAGES], status: false);
    }

    /**
     * A real framework User. Constructing one seals `roles`, `permissions` and
     * `mail` as Internal, exactly as repository hydration does.
     *
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function user(int $uid, array $roles, array $permissions, bool $status = true): User
    {
        return new User([
            'uid' => $uid,
            'name' => 'Account ' . $uid,
            'mail' => 'account' . $uid . '@example.test',
            'roles' => $roles,
            'permissions' => $permissions,
            'status' => $status,
        ]);
    }

    private function page(): Page
    {
        return new Page(['id' => 1, 'title' => 'Home', 'path' => '/']);
    }

    private function document(): Document
    {
        return new Document(['id' => 1, 'title' => 'Treaty notes']);
    }

    private function invokeDecision(\ReflectionMethod $method, object $target, mixed ...$arguments): AccessResult
    {
        $result = $method->invoke($target, ...$arguments);
        self::assertInstanceOf(AccessResult::class, $result);

        return $result;
    }
}
