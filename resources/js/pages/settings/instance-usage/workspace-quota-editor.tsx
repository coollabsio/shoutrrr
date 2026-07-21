import { useForm } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

import type { WorkspaceQuota } from '../instance-usage';

type Props = {
    workspaceId: string;
    quota: WorkspaceQuota;
    // The initial workspace is always unlimited and the gate ignores any override,
    // so its quota can't be changed — show it locked instead of a silent no-op.
    locked?: boolean;
};

const budgetInputId = 'workspace-x-budget';

export function WorkspaceQuotaEditor({ workspaceId, quota, locked }: Props) {
    const form = useForm({
        unlimited: quota.kind === 'unlimited',
        dollars: quota.dollars ?? '',
    });

    if (locked) {
        return (
            <div className="space-y-2">
                <h3 className="text-sm font-medium">X quota</h3>
                <p className="rounded-md border p-3 text-sm text-muted-foreground">
                    The initial workspace always has an unlimited X quota and
                    can’t be changed.
                </p>
            </div>
        );
    }

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
                    <label
                        htmlFor={budgetInputId}
                        className="text-xs text-muted-foreground"
                    >
                        Monthly X budget (USD)
                    </label>
                    <Input
                        id={budgetInputId}
                        type="number"
                        min={0}
                        step="0.01"
                        placeholder="Leave blank for instance default"
                        value={form.data.dollars}
                        onChange={(e) =>
                            form.setData('dollars', e.target.value)
                        }
                        aria-invalid={Boolean(form.errors.dollars)}
                    />
                    {form.errors.dollars && (
                        <p className="text-sm text-destructive">
                            {form.errors.dollars}
                        </p>
                    )}
                </div>
            )}
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Saving…' : 'Save quota'}
            </Button>
        </form>
    );
}
