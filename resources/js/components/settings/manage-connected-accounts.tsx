import { router } from '@inertiajs/react';

import Heading from '@/components/common/heading';
import ProviderIcon from '@/components/socialite/provider-icon';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/auth/socialite';
import { destroy } from '@/routes/connections';

export type Connection = {
    provider: string;
    label: string;
    connected: boolean;
    id: string | null;
};

type Props = {
    connections: Connection[];
};

export default function ManageConnectedAccounts({ connections }: Props) {
    const handleDisconnect = (id: string) => {
        // Disconnecting doesn't remove the provider row — it flips the row back
        // to its "Connect" state (cleared id, connected: false), so the optimistic
        // update maps the matching connection rather than removing it.
        router.delete(destroy.url(id), {
            preserveScroll: true,
            optimistic: (props) => ({
                connections: (
                    (props as { connections?: Connection[] }).connections ?? []
                ).map((connection) =>
                    connection.id === id
                        ? { ...connection, connected: false, id: null }
                        : connection,
                ),
            }),
        });
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Connected accounts"
                description="Connect a provider to sign in faster"
            />

            <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                {connections.map((connection) => (
                    <div
                        key={connection.provider}
                        className="flex items-center justify-between p-4"
                    >
                        <div className="flex items-center gap-3">
                            <ProviderIcon
                                provider={connection.provider}
                                className="h-5 w-5"
                            />
                            <span className="font-medium">
                                {connection.label}
                            </span>
                        </div>

                        {connection.connected && connection.id ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    handleDisconnect(connection.id as string)
                                }
                            >
                                Disconnect
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                nativeButton={false}
                                render={
                                    <a
                                        href={redirect.url(connection.provider)}
                                    />
                                }
                            >
                                Connect
                            </Button>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
