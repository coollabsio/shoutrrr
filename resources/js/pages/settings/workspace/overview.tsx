import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import { useConfirm } from '@/components/common/confirm-dialog';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Props = {
    workspace: {
        id: string;
        name: string;
        slug: string;
        logo: string;
        owner_id: string;
    };
    canManage: boolean;
    isOwner: boolean;
    canDelete: boolean;
    timezone: string;
    timezones: string[];
};

export default function WorkspaceOverview({
    workspace,
    canManage,
    isOwner,
    canDelete,
    timezone,
    timezones,
}: Props) {
    const confirmAction = useConfirm();
    const [deleting, setDeleting] = useState(false);

    async function deleteWorkspace() {
        if (!canDelete || deleting) {
            return;
        }

        const confirmed = await confirmAction({
            title: 'Delete workspace?',
            description:
                'This permanently deletes the workspace, its members, and its data. This cannot be undone.',
            actionLabel: 'Delete workspace',
            destructive: true,
        });

        if (!confirmed) {
            return;
        }

        setDeleting(true);
        router.flushAll();
        router.delete(WorkspaceController.destroy(workspace.id).url, {
            preserveScroll: true,
            onSuccess: () => router.flushAll(),
            onFinish: () => setDeleting(false),
        });
    }

    return (
        <>
            <Head title="Workspace settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Workspace information"
                    description="Update your workspace name and settings"
                />

                <Form
                    {...WorkspaceSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => router.flushAll()}
                    className="space-y-6"
                >
                    {({ processing, errors, recentlySuccessful }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Workspace name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    autoComplete="organization"
                                    defaultValue={workspace.name}
                                    disabled={!canManage || processing}
                                    placeholder="Workspace name"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            {canManage && (
                                <div className="flex items-center gap-4">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Saving...' : 'Save'}
                                    </Button>
                                    {recentlySuccessful && (
                                        <p className="text-sm text-muted-foreground">
                                            Saved
                                        </p>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </Form>

                <TimezoneSection
                    timezone={timezone}
                    timezones={timezones}
                    canManage={canManage}
                />

                {!isOwner && (
                    <div className="space-y-4">
                        <Heading
                            variant="small"
                            title="Leave workspace"
                            description="Remove yourself from this workspace."
                        />
                        <div className="flex flex-col items-start gap-3 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <div>
                                <p className="font-medium">Leave workspace</p>
                                <p className="text-sm text-muted-foreground">
                                    You'll lose access until you're invited
                                    again.
                                </p>
                            </div>
                            <Form
                                {...WorkspaceController.leave.form(
                                    workspace.id,
                                )}
                                options={{ preserveScroll: true }}
                                onBefore={() => {
                                    if (!confirm('Leave this workspace?')) {
                                        return false;
                                    }

                                    router.flushAll();
                                }}
                                onSuccess={() => router.flushAll()}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Leave workspace
                                    </Button>
                                )}
                            </Form>
                        </div>
                    </div>
                )}

                {isOwner && (
                    <div className="space-y-4">
                        <Heading
                            variant="small"
                            title="Danger zone"
                            description="Deleting a workspace is permanent and removes all members and data."
                        />
                        <div className="flex flex-col items-start gap-3 rounded-md border border-destructive/30 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <div>
                                <p className="font-medium">Delete workspace</p>
                                <p className="text-sm text-muted-foreground">
                                    This action cannot be undone.
                                </p>
                            </div>
                            <div className="flex flex-col items-start gap-2 sm:items-end">
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={deleting || !canDelete}
                                    onClick={deleteWorkspace}
                                >
                                    {deleting
                                        ? 'Deleting...'
                                        : 'Delete workspace'}
                                </Button>
                                {!canDelete && (
                                    <p className="max-w-48 text-xs text-muted-foreground sm:text-right">
                                        You can’t delete your only workspace.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function TimezoneSection({
    timezone,
    timezones,
    canManage,
}: {
    timezone: string;
    timezones: string[];
    canManage: boolean;
}) {
    const [value, setValue] = useState(timezone);
    const [saving, setSaving] = useState(false);
    const dirty = value !== timezone;

    function onSave() {
        setSaving(true);
        router.put(
            WorkspaceSettingsController.updateTimezone().url,
            { timezone: value },
            {
                preserveScroll: true,
                optimistic: () => ({ timezone: value }),
                onSuccess: () => toast.success('Posting timezone saved.'),
                onError: () => toast.error('Could not save the timezone.'),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Posting timezone"
                description="The timezone your queued posts publish in."
            />
            <div className="grid max-w-xs gap-2">
                <Label htmlFor="posting-timezone">Timezone</Label>
                <Select
                    value={value}
                    onValueChange={setValue}
                    disabled={!canManage}
                >
                    <SelectTrigger id="posting-timezone">
                        <SelectValue placeholder="Select a timezone" />
                    </SelectTrigger>
                    <SelectContent className="max-h-72">
                        {timezones.map((tz) => (
                            <SelectItem key={tz} value={tz}>
                                {tz}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            {canManage && (
                <Button
                    type="button"
                    disabled={!dirty || saving}
                    onClick={onSave}
                >
                    {saving ? 'Saving...' : 'Save'}
                </Button>
            )}
        </div>
    );
}

WorkspaceOverview.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: WorkspaceSettingsController.showOverview().url,
        },
    ],
};
