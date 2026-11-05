import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
    compact = false,
}: {
    user: User;
    showEmail?: boolean;
    compact?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar
                className={
                    compact
                        ? 'size-5 overflow-hidden rounded-full'
                        : 'h-8 w-8 overflow-hidden rounded-full'
                }
            >
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback className="rounded-full bg-neutral-200 text-[11px] font-semibold text-neutral-700 dark:bg-white/10 dark:text-white">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div
                className={
                    compact
                        ? 'grid min-w-0 flex-1 text-left text-xs leading-tight'
                        : 'grid flex-1 text-left text-sm leading-tight'
                }
            >
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <span className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
