<?php

declare(strict_types=1);

namespace Anokii\Shell;

use Anokii\Support\Auth;
use Waaseyaa\User\User;

/**
 * Builds the shared template context every Anokii shell page needs: the active
 * nav id, the signed-in user's chip (label, role, avatar initials), plus any
 * extra context the instance supplies.
 *
 * Both reference instances re-derived the same three user-chip values (label,
 * a humanized role, two-letter initials) and the same `nav_active` marker. That
 * common core lives here. What stays instance-specific is the nav/module list
 * itself and the per-role human labels, both passed in by the caller, so this
 * class never imports an instance's nav registry or role model.
 *
 * @api
 */
final class Shell
{
    /**
     * Assemble the base shell context for a page.
     *
     * @param User                       $user       The signed-in account.
     * @param string                     $active     The id of the active nav entry.
     * @param array<string, mixed>       $extra      Instance context merged on top
     *                                               (for example the nav/module list
     *                                               under whatever key the template
     *                                               expects, page-specific data).
     * @param array<string, string>      $roleLabels Optional map of role-id to human
     *                                               label; the first of the user's
     *                                               roles found here wins. When empty,
     *                                               the first role id is humanized.
     *
     * @return array<string, mixed>
     *
     * @api
     */
    public static function context(
        User $user,
        string $active,
        array $extra = [],
        array $roleLabels = [],
    ): array {
        $label = Auth::label($user);

        return [
            'nav_active' => $active,
            'user_label' => $label,
            'user_role' => self::roleLabel($user, $roleLabels),
            'user_initials' => self::initials($label),
        ] + $extra;
    }

    /**
     * Resolve a human role label for the user chip. When $roleLabels maps one
     * of the user's role ids, that label wins (in the user's role order);
     * otherwise the first role id is humanized ("band_admin" to "Band Admin").
     * Falls back to "Member" when the account holds no roles.
     *
     * @param array<string, string> $roleLabels
     *
     * @api
     */
    public static function roleLabel(User $user, array $roleLabels = []): string
    {
        $roles = $user->getRoles();
        if ($roles === []) {
            return 'Member';
        }

        if ($roleLabels !== []) {
            foreach ($roles as $roleId) {
                if (isset($roleLabels[$roleId])) {
                    return $roleLabels[$roleId];
                }
            }
        }

        return self::humanize((string) $roles[0]);
    }

    /**
     * Avatar initials from a display label: first letters of the first two
     * words, else the first two characters of a single word.
     *
     * @api
     */
    public static function initials(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '??';
        }

        $words = preg_split('/\s+/', $label) ?: [];
        if (count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }

        return strtoupper(mb_substr($label, 0, 2));
    }

    /**
     * Humanize a machine name: "band_admin" or "band-admin" to "Band Admin".
     *
     * @api
     */
    public static function humanize(string $machineName): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $machineName));
    }
}
