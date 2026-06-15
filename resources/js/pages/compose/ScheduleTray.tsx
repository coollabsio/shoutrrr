import { Clock, ListChecks, Zap } from 'lucide-react';
import type { ComponentType } from 'react';

import { cn } from '@/lib/utils';

import type { ScheduleTray as TrayState } from './composer-state';
import { defaultPickedAt, PickTimePopover } from './PickTimePopover';
import { QueuePreview } from './QueuePreview';
import type { QueueSlotState } from './use-next-slot';

type Props = {
    tray: TrayState;
    onChange: (next: TrayState) => void;
    tz: string;
    queueState: QueueSlotState;
};

export function ScheduleTray({ tray, onChange, tz, queueState }: Props) {
    return (
        <div className="flex items-center gap-2">
            <div
                role="tablist"
                aria-label="When to publish"
                className="inline-flex items-center rounded-md border border-border bg-muted p-[3px]"
            >
                <Tab
                    icon={Zap}
                    label="Now"
                    active={tray.mode === 'now'}
                    onClick={() => onChange({ mode: 'now', pickedAt: null })}
                />
                <Tab
                    icon={ListChecks}
                    label="Queue"
                    active={tray.mode === 'queue'}
                    onClick={() => onChange({ mode: 'queue', pickedAt: null })}
                />
                <Tab
                    icon={Clock}
                    label="Pick time"
                    active={tray.mode === 'pick'}
                    onClick={() =>
                        onChange({
                            mode: 'pick',
                            pickedAt: tray.pickedAt ?? defaultPickedAt(tz),
                        })
                    }
                />
            </div>
            {tray.mode === 'pick' && (
                <PickTimePopover
                    value={tray.pickedAt}
                    onChange={(iso) =>
                        onChange({ mode: 'pick', pickedAt: iso })
                    }
                    tz={tz}
                />
            )}
            {tray.mode === 'queue' ? (
                <QueuePreview state={queueState} />
            ) : (
                <span className="text-[11px] text-muted-foreground">
                    Times in {tz}
                </span>
            )}
        </div>
    );
}

type TabProps = {
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    label: string;
    active: boolean;
    onClick: () => void;
};

function Tab({ icon: Icon, label, active, onClick }: TabProps) {
    return (
        <button
            type="button"
            aria-pressed={active}
            data-active={active}
            onClick={onClick}
            className={cn(
                'inline-flex flex-1 items-center justify-center gap-1.5 rounded-[6px] px-2 py-1 text-[12.5px] font-medium text-muted-foreground transition-all sm:flex-none sm:px-3',
                'hover:text-foreground',
                'data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
            )}
        >
            <Icon className="size-3.5 shrink-0" aria-hidden />
            {label}
        </button>
    );
}
