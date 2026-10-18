<?php

namespace App\Support;

use App\Models\User;
use App\Navigation\SidebarMenu;
use Illuminate\Http\Request;

/**
 * Backwards-compat shim. The real sidebar catalog now lives in
 * {@see \App\Navigation\SidebarMenu}; this class forwards the old public
 * surface so middleware (`SyncViewModeWithRoute`) and existing tests keep
 * working without a wide find-and-replace.
 */
final class SidebarNavigation
{
    public const CONTEXT_ADMIN = SidebarMenu::CONTEXT_ADMIN;

    public const CONTEXT_SHOOTER = SidebarMenu::CONTEXT_SHOOTER;

    public static function catalog(): array
    {
        return SidebarMenu::catalog();
    }

    public static function sectionsFor(User $user, string $viewMode, ?Request $request = null): array
    {
        return SidebarMenu::sectionsFor($user, $viewMode, $request);
    }

    public static function labelsFor(User $user, string $viewMode): array
    {
        return SidebarMenu::labelsFor($user, $viewMode);
    }

    public static function exclusiveContextForRoute(?string $routeName): ?string
    {
        return SidebarMenu::exclusiveContextForRoute($routeName);
    }
}
