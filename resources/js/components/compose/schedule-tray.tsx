import { Clock, ListChecks, Zap } from 'lucide-react';
import type { ComponentType } from 'react';

import type { QueueSlotState } from '@/hooks/compose/use-next-slot';
import type { ScheduleTray as TrayState } from '@/lib/compose/composer-state';
import { cn } from '@/lib/utils';

import { defaultPickedAt, PickTimePopover } from './pick-time-popover';
import { QueuePreview } from './queue-preview';

type Props = {
    tray: TrayState;
    onChange: (next: TrayState) => void;
    tz: string;
    queueState: QueueSlotState;
};

export function ScheduleTray({ tray, onChange, tz, queueState }: Props) {
    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <div
                role="tablist"
                aria-label="When to publish"
                className="flex w-full items-center rounded-md border border-border bg-muted p-[3px] sm:inline-flex sm:w-auto"
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
            {tray.mode === 'queue' && (
                <QueuePreview
                    state={queueState}
                    selectedSlot={tray.pickedAt}
                    onSelectSlot={(slot) =>
                        onChange({ mode: 'queue', pickedAt: slot })
                    }
                />
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
                'inline-flex flex-1 items-center justify-center gap-1.5 rounded-[6px] px-2 py-1.5 text-[12.5px] font-medium text-muted-foreground transition-all sm:flex-none sm:px-3 sm:py-1',
                'hover:text-foreground',
                'data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
            )}
        >
            <Icon className="size-3.5 shrink-0" aria-hidden />
            {label}
        </button>
    );
}
