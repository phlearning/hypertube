import { Link, router, usePage } from '@inertiajs/react';
import {
    LogOut,
    Settings,
    LayoutGrid,
    User,
    Film,
    ShieldCheck,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { dashboard } from '@/routes';
import { index as adminTorrentJobsIndex } from '@/routes/admin/torrent-jobs';
import { index as libraryIndex } from '@/routes/library';
import { edit } from '@/routes/profile';
import { index } from '@/routes/users/index';

import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Video',
        href: libraryIndex(),
        icon: Film,
        prefetch: false,
    },
    {
        title: 'Users',
        href: index(),
        icon: User,
    },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const cleanup = useMobileNavigation();
    const { auth } = usePage<{ auth: Auth }>().props;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const navItems: NavItem[] =
        auth.user.role === 'admin'
            ? [
                  ...mainNavItems,
                  {
                      title: 'Administration',
                      href: adminTorrentJobsIndex(),
                      icon: ShieldCheck,
                  },
              ]
            : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild>
                            <Link
                                className="block w-full cursor-pointer"
                                href={edit()}
                                prefetch
                                onClick={cleanup}
                            >
                                <Settings className="h-5 w-5" />
                                <span>Settings</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild>
                            <Link
                                href={logout()}
                                as="button"
                                onClick={handleLogout}
                                className="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                <LogOut className="h-5 w-5" />
                                <span>Log out</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>
        </Sidebar>
    );
}
