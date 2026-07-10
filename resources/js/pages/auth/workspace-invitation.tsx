import { Head, Link } from '@inertiajs/react';
import { Calendar, User, Users } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    invitation: {
        token: string;
        workspace_name: string;
        role: string;
        inviter_name: string;
        expires_at: string;
    };
    userExists: boolean;
    loginUrl: string;
    registerUrl: string;
};

export default function WorkspaceInvitation({
    invitation,
    userExists,
    loginUrl,
    registerUrl,
}: Props) {
    return (
        <>
            <Head title="Join workspace" />

            <div className="space-y-6">
                <div className="rounded-md border p-4">
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <span className="flex items-center gap-2 font-medium">
                            <Users className="size-4" />
                            {invitation.workspace_name}
                        </span>
                        <Badge variant="outline" className="capitalize">
                            {invitation.role}
                        </Badge>
                    </div>
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <User className="size-4" />
                        Invited by {invitation.inviter_name}
                    </p>
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Calendar className="size-4" />
                        Expires{' '}
                        {new Date(invitation.expires_at).toLocaleDateString()}
                    </p>
                </div>

                <div className="space-y-3">
                    <Button
                        nativeButton={false}
                        className="w-full"
                        render={<Link href={loginUrl} />}
                    >
                        {userExists
                            ? 'Sign in & join'
                            : 'I already have an account'}
                    </Button>
                    {!userExists && (
                        <Button
                            nativeButton={false}
                            variant="outline"
                            className="w-full"
                            render={<Link href={registerUrl} />}
                        >
                            Create account & join
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

WorkspaceInvitation.layout = {
    title: "You're invited",
    description: 'Accept your invitation to join the workspace',
};
