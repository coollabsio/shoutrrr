import { Form, Head, router, usePage } from '@inertiajs/react';
import { Crown, MoreVertical, Search, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import RemoveInstanceOwnerDialog from '@/components/settings/remove-instance-owner-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { removeById } from '@/lib/optimistic';

type InstanceUser = {
    id: string;
    name: string;
    email: string;
    avatar: string;
    created_at?: string;
};

type Props = {
    owners: InstanceUser[];
    users: InstanceUser[];
    search: string;
};

export default function InstanceAdmins({ owners, users, search }: Props) {
    const { auth } = usePage().props;
    const [query, setQuery] = useState(search);
    const [ownerToRemove, setOwnerToRemove] = useState<InstanceUser | null>(
        null,
    );

    function confirmRemoveOwner() {
        if (!ownerToRemove) {
            return;
        }

        const removedId = ownerToRemove.id;

        router.delete(InstanceSettingsController.destroyAdmin.url(removedId), {
            preserveScroll: true,
            optimistic: (props) => ({
                owners: removeById(
                    (props as { owners?: InstanceUser[] }).owners,
                    removedId,
                ),
            }),
            onSuccess: () => {
                toast.success('Instance owner removed');
                setOwnerToRemove(null);
            },
            onError: () => setOwnerToRemove(null),
        });
    }

    function handleSearch(event: React.FormEvent) {
        event.preventDefault();

        router.get(
            InstanceSettingsController.admins({
                query: { search: query },
            }).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    }

    return (
        <>
            <Head title="Instance admins" />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Admins"
                    description="Add registered users as instance owners. No invitation or acceptance is required."
                />

                <section className="space-y-4">
                    <div className="space-y-1">
                        <h2 className="text-sm font-medium">Instance owners</h2>
                        <p className="text-sm text-muted-foreground">
                            These users can manage instance-wide settings.
                        </p>
                    </div>

                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>User</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead className="w-32 text-right">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {owners.map((owner) => (
                                    <TableRow key={owner.id}>
                                        <TableCell>
                                            <UserSummary user={owner} />
                                        </TableCell>
                                        <TableCell>
                                            <Badge>
                                                <span className="flex items-center gap-1">
                                                    <Crown className="size-3" />
                                                    Owner
                                                </span>
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {owner.id !== auth.user.id &&
                                                owners.length > 1 && (
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
                                                                Owner actions
                                                            </span>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    setOwnerToRemove(
                                                                        owner,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="size-4" />
                                                                Remove
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <section className="space-y-4">
                    <div className="space-y-1">
                        <h2 className="text-sm font-medium">Add owner</h2>
                        <p className="text-sm text-muted-foreground">
                            Search registered users by email, then grant the
                            instance owner role.
                        </p>
                    </div>

                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="grid flex-1 gap-2">
                            <Label htmlFor="search" className="sr-only">
                                Search users by email
                            </Label>
                            <Input
                                id="search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by email"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            <Search className="size-4" />
                            Search
                        </Button>
                    </form>

                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>User</TableHead>
                                    <TableHead className="w-32 text-right">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={2}
                                            className="py-6 text-center text-sm text-muted-foreground"
                                        >
                                            {search
                                                ? 'No registered users match this email search.'
                                                : 'Search by email to find a registered user.'}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    users.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <UserSummary user={user} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Form
                                                    {...InstanceSettingsController.storeAdmin.form()}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    onSuccess={() =>
                                                        toast.success(
                                                            'Instance owner added',
                                                        )
                                                    }
                                                >
                                                    {({
                                                        errors,
                                                        processing,
                                                    }) => (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name="email"
                                                                value={
                                                                    user.email
                                                                }
                                                            />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                <UserPlus className="size-4" />
                                                                Add owner
                                                            </Button>
                                                            <InputError
                                                                message={
                                                                    errors.email
                                                                }
                                                            />
                                                        </>
                                                    )}
                                                </Form>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>

            <RemoveInstanceOwnerDialog
                owner={ownerToRemove}
                onClose={() => setOwnerToRemove(null)}
                onConfirm={confirmRemoveOwner}
            />
        </>
    );
}

function UserSummary({ user }: { user: InstanceUser }) {
    return (
        <div className="flex items-center gap-3">
            <Avatar className="size-8">
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback>{user.name.charAt(0)}</AvatarFallback>
            </Avatar>
            <div className="min-w-0">
                <p className="truncate font-medium">{user.name}</p>
                <p className="truncate text-sm text-muted-foreground">
                    {user.email}
                </p>
            </div>
        </div>
    );
}

InstanceAdmins.layout = {
    breadcrumbs: [
        {
            title: 'Instance settings',
            href: InstanceSettingsController.edit().url,
        },
        {
            title: 'Admins',
            href: InstanceSettingsController.admins().url,
        },
    ],
};
