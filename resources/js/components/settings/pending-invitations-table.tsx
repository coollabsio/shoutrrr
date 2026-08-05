import Heading from '@/components/common/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Mail, Trash2 } from '@/components/ui/icons';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

import { roleIcon } from './member-role-helpers';
import type { Invitation } from './member-types';

export default function PendingInvitationsTable({
    invitations,
    canManage,
    onCancel,
}: {
    invitations: Invitation[];
    canManage: boolean;
    onCancel: (id: string) => void;
}) {
    if (invitations.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Pending invitations"
                description="Invitations that haven't been accepted yet"
            />
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Email</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Invited by</TableHead>
                            <TableHead>Expires</TableHead>
                            {canManage && (
                                <TableHead className="w-12 text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {invitations.map((invitation) => (
                            <TableRow key={invitation.id}>
                                <TableCell>
                                    <div className="flex items-center gap-2">
                                        <Mail className="size-4 text-muted-foreground" />
                                        <span>{invitation.email}</span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">
                                        <span className="flex items-center gap-1">
                                            {roleIcon(invitation.role)}
                                            <span className="capitalize">
                                                {invitation.role}
                                            </span>
                                        </span>
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {invitation.invited_by ?? '—'}
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {new Date(
                                        invitation.expires_at,
                                    ).toLocaleDateString()}
                                </TableCell>
                                {canManage && (
                                    <TableCell className="text-right">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            onClick={() =>
                                                onCancel(invitation.id)
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                            <span className="sr-only">
                                                Cancel invitation
                                            </span>
                                        </Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
