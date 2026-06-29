import { useEffect, useRef, useState } from 'react';

import InstanceSettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSettingsController';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ChevronsUpDown } from 'lucide-react';

type Props = {
    provider: string;
    apiKey: string;
    value: string;
    onChange: (model: string) => void;
    disabled?: boolean;
};

function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function AiModelCombobox({
    provider,
    apiKey,
    value,
    onChange,
    disabled,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [models, setModels] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const cache = useRef<Record<string, string[]>>({});

    useEffect(() => {
        setModels([]);
        setError(null);
        setQuery('');
    }, [provider]);

    async function fetchModels() {
        if (cache.current[provider] !== undefined) {
            setModels(cache.current[provider]);

            return;
        }

        setLoading(true);
        setError(null);

        try {
            const res = await fetch(
                InstanceSettingsController.aiModels().url,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        provider,
                        ...(apiKey ? { api_key: apiKey } : {}),
                    }),
                },
            );

            if (res.ok) {
                const json = (await res.json()) as { models: string[] };
                cache.current[provider] = json.models;
                setModels(json.models);
            } else {
                const json = (await res.json()) as { message?: string };
                setError(
                    json.message ??
                        'Could not load models. You can still type a model id.',
                );
                cache.current[provider] = [];
            }
        } catch {
            setError('Could not load models. You can still type a model id.');
        } finally {
            setLoading(false);
        }
    }

    function handleOpenChange(next: boolean) {
        setOpen(next);

        if (next && provider) {
            void fetchModels();
        }
    }

    function select(model: string) {
        onChange(model);
        setOpen(false);
        setQuery('');
    }

    const trimmedQuery = query.trim();
    const isExactMatch = models.some(
        (m) => m.toLowerCase() === trimmedQuery.toLowerCase(),
    );
    const showCustomEntry =
        trimmedQuery.length > 0 && !isExactMatch && trimmedQuery !== value;

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled || !provider}
                    className="w-full justify-between font-normal"
                >
                    <span className="truncate text-left">
                        {value || 'Select or type a model…'}
                    </span>
                    <ChevronsUpDown className="shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                <Command>
                    <CommandInput
                        value={query}
                        onValueChange={setQuery}
                        placeholder="Search models…"
                    />
                    <CommandList>
                        {loading ? (
                            <div className="py-6 text-center text-sm text-muted-foreground">
                                Loading models…
                            </div>
                        ) : (
                            <>
                                {error && (
                                    <div className="px-3 py-2.5 text-xs text-muted-foreground">
                                        {error}
                                    </div>
                                )}
                                <CommandGroup>
                                    {models.map((model) => (
                                        <CommandItem
                                            key={model}
                                            value={model}
                                            data-checked={value === model}
                                            onSelect={() => select(model)}
                                        >
                                            {model}
                                        </CommandItem>
                                    ))}
                                    {showCustomEntry && (
                                        <CommandItem
                                            key={`__custom__${trimmedQuery}`}
                                            value={`__custom__${trimmedQuery}`}
                                            onSelect={() =>
                                                select(trimmedQuery)
                                            }
                                        >
                                            <span className="text-muted-foreground">
                                                Use "
                                            </span>
                                            {trimmedQuery}
                                            <span className="text-muted-foreground">
                                                "
                                            </span>
                                        </CommandItem>
                                    )}
                                </CommandGroup>
                                {!loading && models.length === 0 && (
                                    <CommandEmpty>
                                        {trimmedQuery
                                            ? 'No matching model — press Enter to use this id.'
                                            : 'No models found. Type a model id.'}
                                    </CommandEmpty>
                                )}
                            </>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
