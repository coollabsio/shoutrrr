import { Form, Head, router } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import { useConfirm } from '@/components/common/confirm-dialog';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

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
    deleteDisabledReason: string | null;
    timezone: string;
    timezones: string[];
};

export default function WorkspaceOverview({
    workspace,
    canManage,
    isOwner,
    canDelete,
    deleteDisabledReason,
    timezone,
    timezones,
}: Props) {
    const confirmAction = useConfirm();
    const [deleting, setDeleting] = useState(false);
    const [selectedPhoto, setSelectedPhoto] = useState<File | null>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!selectedPhoto) {
            setPhotoPreview(null);

            return;
        }

        const previewUrl = URL.createObjectURL(selectedPhoto);
        setPhotoPreview(previewUrl);

        return () => URL.revokeObjectURL(previewUrl);
    }, [selectedPhoto]);

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
        router.delete(WorkspaceController.destroy(workspace.id).url, {
            preserveScroll: true,
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
                    className="space-y-6"
                    onSuccess={() => setSelectedPhoto(null)}
                >
                    {({ processing, errors, recentlySuccessful, progress }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="workspace-photo">
                                    Workspace photo
                                </Label>
                                <div className="flex items-center gap-4">
                                    <img
                                        src={photoPreview ?? workspace.logo}
                                        alt={workspace.name}
                                        className="size-16 rounded-md object-cover"
                                    />
                                    <div className="grid min-w-0 gap-1">
                                        <label
                                            htmlFor="workspace-photo"
                                            aria-disabled={
                                                !canManage || processing
                                            }
                                            className="inline-flex h-8 w-fit shrink-0 cursor-pointer items-center justify-center rounded-2xl bg-primary px-3 text-sm font-medium whitespace-nowrap text-primary-foreground transition-all hover:bg-primary/80 aria-disabled:pointer-events-none aria-disabled:opacity-50"
                                        >
                                            Choose photo
                                        </label>
                                        <Input
                                            id="workspace-photo"
                                            type="file"
                                            name="photo"
                                            accept="image/*"
                                            disabled={!canManage || processing}
                                            className="sr-only"
                                            onChange={(event) =>
                                                setSelectedPhoto(
                                                    event.currentTarget
                                                        .files?.[0] ?? null,
                                                )
                                            }
                                        />
                                        <p
                                            className="max-w-56 truncate text-xs text-muted-foreground"
                                            title={selectedPhoto?.name}
                                        >
                                            {selectedPhoto
                                                ? `Selected: ${selectedPhoto.name}`
                                                : 'Image up to 2 MB.'}
                                        </p>
                                    </div>
                                </div>
                                <InputError message={errors.photo} />
                                {progress && (
                                    <progress
                                        value={progress.percentage}
                                        max="100"
                                        className="h-2 w-full"
                                    >
                                        {progress.percentage}%
                                    </progress>
                                )}
                            </div>

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
                                }}
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
                                {deleteDisabledReason && (
                                    <p className="max-w-48 text-xs text-muted-foreground sm:text-right">
                                        {deleteDisabledReason}
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
    const [open, setOpen] = useState(false);
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
                <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger
                        render={
                            <Button
                                id="posting-timezone"
                                type="button"
                                variant="outline"
                                role="combobox"
                                aria-expanded={open}
                                disabled={!canManage}
                                className="justify-between font-normal"
                            />
                        }
                    >
                        <span className="truncate">
                            {value || 'Select a timezone'}
                        </span>
                        <ChevronsUpDown className="opacity-50" />
                    </PopoverTrigger>
                    <PopoverContent className="w-(--anchor-width) p-0">
                        <Command>
                            <CommandInput placeholder="Search timezones..." />
                            <CommandList>
                                <CommandEmpty>No timezone found.</CommandEmpty>
                                <CommandGroup>
                                    {timezones.map((tz) => (
                                        <CommandItem
                                            key={tz}
                                            value={tz}
                                            data-checked={value === tz}
                                            onSelect={() => {
                                                setValue(tz);
                                                setOpen(false);
                                            }}
                                        >
                                            {tz}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    </PopoverContent>
                </Popover>
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
