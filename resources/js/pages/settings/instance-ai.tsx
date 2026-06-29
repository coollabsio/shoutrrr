import { Head, router, useForm } from '@inertiajs/react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import Heading from '@/components/common/heading';
import { AiModelCombobox } from '@/components/settings/ai-model-combobox';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AiSettings = {
    ai_enabled: boolean;
    ai_provider: string;
    ai_model: string;
    ai_api_key_set: boolean;
};

/** Text-capable providers Prism is configured for (see config/prism.php). */
const AI_PROVIDERS = [
    { value: 'anthropic', label: 'Anthropic (Claude)' },
    { value: 'openai', label: 'OpenAI' },
    { value: 'gemini', label: 'Google Gemini' },
    { value: 'ollama', label: 'Ollama (self-hosted)' },
    { value: 'mistral', label: 'Mistral' },
    { value: 'groq', label: 'Groq' },
    { value: 'deepseek', label: 'DeepSeek' },
    { value: 'xai', label: 'xAI (Grok)' },
    { value: 'openrouter', label: 'OpenRouter' },
    { value: 'perplexity', label: 'Perplexity' },
] as const;

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
            <Head title="ShoutAI" />
            <h1 className="sr-only">ShoutAI</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="ShoutAI"
                    description="Configure the LLM provider that powers ShoutAI — composer rewrites and reply suggestions."
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
                            <Label htmlFor="ai_enabled">Enable ShoutAI</Label>
                            <p className="text-sm text-muted-foreground">
                                When disabled, all AI features are hidden across the app.
                            </p>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="ai_provider">Provider</Label>
                        <Select
                            value={data.ai_provider}
                            onValueChange={(value) =>
                                setData('ai_provider', value)
                            }
                        >
                            <SelectTrigger id="ai_provider" className="w-full">
                                <SelectValue placeholder="Select a provider" />
                            </SelectTrigger>
                            <SelectContent>
                                {AI_PROVIDERS.map((provider) => (
                                    <SelectItem
                                        key={provider.value}
                                        value={provider.value}
                                    >
                                        {provider.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            The LLM provider that powers AI features. Set the
                            matching API key below.
                        </p>
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

                    <div className="space-y-2">
                        <Label htmlFor="ai_model">Model</Label>
                        <AiModelCombobox
                            provider={data.ai_provider}
                            apiKey={data.ai_api_key}
                            value={data.ai_model}
                            onChange={(m) => setData('ai_model', m)}
                            disabled={!data.ai_provider}
                        />
                        <p className="text-sm text-muted-foreground">
                            Pick from the provider's available models, or type a
                            custom id. Enter the API key above first so the list
                            can load.
                        </p>
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
        { title: 'ShoutAI', href: InstanceSettingsController.editAi().url },
    ],
};
