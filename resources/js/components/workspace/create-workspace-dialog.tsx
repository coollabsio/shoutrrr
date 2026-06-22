import { Form, router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';

import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function CreateWorkspaceDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create workspace</DialogTitle>
                    <DialogDescription>
                        Workspaces keep your team and data separate.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...WorkspaceController.store.form()}
                    options={{ preserveState: false }}
                    resetOnSuccess={['name']}
                    onBefore={() => router.flushAll()}
                    onSuccess={() => {
                        router.flushAll();
                        onOpenChange(false);
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="workspace-name">Name</Label>
                                <Input
                                    id="workspace-name"
                                    name="name"
                                    placeholder="Acme Inc."
                                    autoFocus
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <Button type="submit" disabled={processing}>
                                {processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    'Create workspace'
                                )}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
