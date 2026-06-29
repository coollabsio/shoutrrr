import { Head, router, useForm } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AiSettings = {
    ai_enabled: boolean;
    ai_provider: string;
    ai_model: string;
    ai_api_key_set: boolean;
};

type PageProps = { settings: AiSettings };

export default function InstanceAi({ settings }: PageProps) {
    const { data, setData, put, processing } = useForm({
        ai_enabled: settings.ai_enabled,
        ai_provider: settings.ai_provider,
        ai_model: settings.ai_model,
        ai_api_key: '',
        ai_clear_api_key: false,
    });

    function handleSubmit(event: React.FormEvent) {
        event.preventDefault();
        put(InstanceSettingsController.updateAi().url, { preserveScroll: true });
    }

    return (
        <>
            <Head title="AI settings" />
            <h1 className="sr-only">AI settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="AI assistant"
                    description="Configure the LLM provider that powers composer rewrites and reply suggestions."
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="ai_enabled"
                            checked={data.ai_enabled}
                            onCheckedChange={(checked) =>
                                setData('ai_enabled', checked === true)
                            }
                        />
                        <div className="space-y-1">
                            <Label htmlFor="ai_enabled">Enable AI assistant</Label>
                            <p className="text-sm text-muted-foreground">
                                When disabled, all AI features are hidden across the app.
                            </p>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="ai_provider">Provider</Label>
                        <Input
                            id="ai_provider"
                            value={data.ai_provider}
                            onChange={(e) => setData('ai_provider', e.target.value)}
                            placeholder="anthropic"
                        />
                        <p className="text-sm text-muted-foreground">
                            Any provider supported by Prism (e.g. anthropic, openai, ollama).
                        </p>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="ai_model">Model</Label>
                        <Input
                            id="ai_model"
                            value={data.ai_model}
                            onChange={(e) => setData('ai_model', e.target.value)}
                            placeholder="claude-sonnet-4-5"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="ai_api_key">API key</Label>
                        <Input
                            id="ai_api_key"
                            type="password"
                            autoComplete="off"
                            value={data.ai_api_key}
                            disabled={data.ai_clear_api_key}
                            onChange={(e) => setData('ai_api_key', e.target.value)}
                            placeholder={
                                settings.ai_api_key_set
                                    ? '•••••••• (leave blank to keep)'
                                    : 'Enter API key'
                            }
                        />
                        {settings.ai_api_key_set ? (
                            <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Checkbox
                                    checked={data.ai_clear_api_key}
                                    onCheckedChange={(checked) =>
                                        setData('ai_clear_api_key', checked === true)
                                    }
                                />
                                Remove stored key
                            </label>
                        ) : null}
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    InstanceSettingsController.testAi().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            disabled={processing || !settings.ai_api_key_set}
                        >
                            Test connection
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

InstanceAi.layout = {
    breadcrumbs: [
        { title: 'Instance settings', href: InstanceSettingsController.edit().url },
        { title: 'AI', href: InstanceSettingsController.editAi().url },
    ],
};
