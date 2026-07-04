import { Link } from '@inertiajs/react';
import { Activity, FlaskConical, LayoutDashboard, Radar, Rss, Server, Star } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { backtests, dashboard, feed, radar, signals, sources, watchlists } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Desk',
        href: dashboard(),
        icon: LayoutDashboard,
    },
    {
        title: 'Radar',
        href: radar(),
        icon: Radar,
    },
    {
        title: 'Feed',
        href: feed(),
        icon: Rss,
    },
    {
        title: 'Signals',
        href: signals(),
        icon: Activity,
    },
    {
        title: 'Backtests',
        href: backtests(),
        icon: FlaskConical,
    },
    {
        title: 'Watchlists',
        href: watchlists(),
        icon: Star,
    },
    {
        title: 'Sources',
        href: sources(),
        icon: Server,
    },
];

export function AppSidebar() {
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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
