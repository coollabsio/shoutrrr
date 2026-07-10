import ProviderIcon from '@/components/socialite/provider-icon';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/auth/socialite';

type Props = {
    provider: string;
    label: string;
    invitation?: string;
};

export default function SocialButton({ provider, label, invitation }: Props) {
    const href = redirect.url(provider, {
        query: invitation ? { invitation } : undefined,
    });

    return (
        <Button
            variant="outline"
            className="w-full"
            nativeButton={false}
            render={<a href={href} />}
        >
            <ProviderIcon provider={provider} className="h-4 w-4" />
            Continue with {label}
        </Button>
    );
}
