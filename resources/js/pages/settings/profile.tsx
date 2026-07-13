import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import DeleteUser from '@/components/settings/delete-user';
import { Avatar, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
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

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                    onSuccess={() => setSelectedPhoto(null)}
                >
                    {({ processing, errors, progress }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="profile-photo">
                                    Profile photo
                                </Label>
                                <div className="flex items-center gap-4">
                                    <Avatar className="size-16">
                                        <AvatarImage
                                            src={
                                                photoPreview ?? auth.user.avatar
                                            }
                                            alt={auth.user.name}
                                        />
                                    </Avatar>
                                    <div className="grid min-w-0 gap-1">
                                        <label
                                            htmlFor="profile-photo"
                                            aria-disabled={processing}
                                            className="inline-flex h-8 w-fit shrink-0 cursor-pointer items-center justify-center rounded-2xl bg-primary px-3 text-sm font-medium whitespace-nowrap text-primary-foreground transition-all hover:bg-primary/80 aria-disabled:pointer-events-none aria-disabled:opacity-50"
                                        >
                                            Choose photo
                                        </label>
                                        <Input
                                            id="profile-photo"
                                            type="file"
                                            name="photo"
                                            accept="image/*"
                                            disabled={processing}
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
                                <InputError
                                    className="mt-2"
                                    message={errors.photo}
                                />
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
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
