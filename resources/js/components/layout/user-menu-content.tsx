import { Link } from '@inertiajs/react';

import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { LogOut, Settings, Sun } from '@/components/ui/icons';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="min-w-0 px-2 py-1.5">
                    <div className="truncate text-[13px] font-semibold">
                        {user.name}
                    </div>
                    <div className="truncate text-[11px] text-muted-foreground">
                        {user.email}
                    </div>
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem
                    render={
                        <Link
                            className="block w-full cursor-pointer"
                            href={edit()}
                            onClick={cleanup}
                        />
                    }
                >
                    <Settings className="mr-2" />
                    Settings
                </DropdownMenuItem>
                <DropdownMenuItem
                    render={
                        <Link
                            className="block w-full cursor-pointer"
                            href={editAppearance()}
                            onClick={cleanup}
                        />
                    }
                >
                    <Sun className="mr-2" />
                    Appearance
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                render={
                    <Link
                        className="block w-full cursor-pointer"
                        href={logout()}
                        as="button"
                        onClick={cleanup}
                        data-test="logout-button"
                    />
                }
            >
                <LogOut className="mr-2" />
                Log out
            </DropdownMenuItem>
        </>
    );
}
