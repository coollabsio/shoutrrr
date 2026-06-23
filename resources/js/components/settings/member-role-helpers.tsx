import { Crown, Shield, User } from 'lucide-react';

export function roleIcon(role: string) {
    switch (role) {
        case 'owner':
            return <Crown className="size-4" />;
        case 'admin':
            return <Shield className="size-4" />;
        default:
            return <User className="size-4" />;
    }
}

export function roleBadgeVariant(
    role: string,
): 'default' | 'secondary' | 'outline' {
    switch (role) {
        case 'owner':
            return 'default';
        case 'admin':
            return 'secondary';
        default:
            return 'outline';
    }
}
