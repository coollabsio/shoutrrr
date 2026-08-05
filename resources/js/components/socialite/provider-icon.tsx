import type { ReactElement, SVGProps } from 'react';

import GoogleIcon from '@/components/socialite/icons/google-icon';
import LinkedInIcon from '@/components/socialite/icons/linkedin-icon';
import XIcon from '@/components/socialite/icons/x-icon';
import { Globe } from '@/components/ui/icons';

const ICONS: Record<string, (props: SVGProps<SVGSVGElement>) => ReactElement> =
    {
        google: GoogleIcon,
        x: XIcon,
        linkedin: LinkedInIcon,
    };

type Props = SVGProps<SVGSVGElement> & {
    provider: string;
};

export default function ProviderIcon({ provider, ...props }: Props) {
    const Icon = ICONS[provider];

    if (!Icon) {
        return <Globe {...props} />;
    }

    return <Icon {...props} />;
}
