import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import ApiKeysController from '@/actions/App/Http/Controllers/Settings/ApiKeysController';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export default function CreateApiKeyDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="size-4" />
                    Create key
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create an API key</DialogTitle>
                    <DialogDescription>
                        The key acts on this workspace only. You&apos;ll see the
                        full key once, right after it&apos;s created.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...ApiKeysController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => {
                        toast.success('API key created');
                        setOpen(false);
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-4 py-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="CI deploy bot"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="scope">Access</Label>
                                    <Select name="scope" defaultValue="read">
                                        <SelectTrigger id="scope">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="read">
                                                Read
                                            </SelectItem>
                                            <SelectItem value="write">
                                                Read &amp; write
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.scope} />
                                    <p className="text-xs text-muted-foreground">
                                        Read keys can fetch data; read &amp;
                                        write keys can also create and change
                                        it.
                                    </p>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="expires_at">
                                        Expires{' '}
                                        <span className="text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="expires_at"
                                        name="expires_at"
                                        type="date"
                                    />
                                    <InputError message={errors.expires_at} />
                                    <p className="text-xs text-muted-foreground">
                                        Leave empty for a key that never
                                        expires.
                                    </p>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating…' : 'Create key'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
