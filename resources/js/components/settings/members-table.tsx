import { Crown, MoreVertical, Trash2 } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

import { roleBadgeVariant, roleIcon } from './member-role-helpers';
import type { Member } from './member-types';

export default function MembersTable({
    members,
    canManage,
    isOwner,
    availableRoles,
    onUpdateRole,
    onRemove,
    onTransfer,
}: {
    members: Member[];
    canManage: boolean;
    isOwner: boolean;
    availableRoles: string[];
    onUpdateRole: (memberId: string, role: string) => void;
    onRemove: (member: Member) => void;
    onTransfer: (member: Member) => void;
}) {
    return (
        <div className="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Member</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Joined</TableHead>
                        {canManage && (
                            <TableHead className="w-12 text-right">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {members.map((member) => (
                        <TableRow key={member.id}>
                            <TableCell>
                                <div className="flex items-center gap-3">
                                    <Avatar className="size-8">
                                        <AvatarImage
                                            src={member.avatar}
                                            alt={member.name}
                                        />
                                        <AvatarFallback>
                                            {member.name.charAt(0)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {member.name}
                                        </p>
                                        <p className="truncate text-sm text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge variant={roleBadgeVariant(member.role)}>
                                    <span className="flex items-center gap-1">
                                        {roleIcon(member.role)}
                                        <span className="capitalize">
                                            {member.role}
                                        </span>
                                    </span>
                                </Badge>
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {new Date(
                                    member.created_at,
                                ).toLocaleDateString()}
                            </TableCell>
                            {canManage && (
                                <TableCell className="text-right">
                                    {!member.is_owner && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                    />
                                                }
                                            >
                                                <MoreVertical className="size-4" />
                                                <span className="sr-only">
                                                    Member actions
                                                </span>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {availableRoles
                                                    .filter(
                                                        (role) =>
                                                            role !==
                                                                member.role &&
                                                            role !== 'owner',
                                                    )
                                                    .map((role) => (
                                                        <DropdownMenuItem
                                                            key={role}
                                                            onClick={() =>
                                                                onUpdateRole(
                                                                    member.id,
                                                                    role,
                                                                )
                                                            }
                                                        >
                                                            {roleIcon(role)}
                                                            <span>
                                                                Make {role}
                                                            </span>
                                                        </DropdownMenuItem>
                                                    ))}
                                                {isOwner && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            onTransfer(member)
                                                        }
                                                    >
                                                        <Crown className="size-4 text-yellow-600" />
                                                        <span>
                                                            Transfer ownership
                                                        </span>
                                                    </DropdownMenuItem>
                                                )}
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        onRemove(member)
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                    Remove
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
