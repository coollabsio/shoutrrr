import { useForm } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

type Props = {
    workspaceId: string;
    quota: { kind: string; dollars: number | null };
};

export function WorkspaceQuotaEditor({ workspaceId, quota }: Props) {
    const form = useForm({
        unlimited: quota.kind === 'unlimited',
        dollars: quota.dollars ?? '',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.put(
            InstanceSettingsController.updateWorkspaceBudget({
                workspace: workspaceId,
            }).url,
            { preserveScroll: true },
        );
    }

    return (
        <form onSubmit={submit} className="space-y-3">
            <label className="flex items-center gap-2 text-sm">
                <Switch
                    checked={form.data.unlimited}
                    onCheckedChange={(v) => form.setData('unlimited', v)}
                />
                Unlimited X quota
            </label>
            {!form.data.unlimited && (
                <div className="space-y-1">
                    <label className="text-xs text-muted-foreground">
                        Monthly X budget (USD)
                    </label>
                    <Input
                        type="number"
                        min={0}
                        step="0.01"
                        placeholder="Leave blank for instance default"
                        value={form.data.dollars}
                        onChange={(e) =>
                            form.setData('dollars', e.target.value)
                        }
                    />
                </div>
            )}
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Saving…' : 'Save quota'}
            </Button>
        </form>
    );
}
