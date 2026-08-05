import type { IconComponent } from '@/components/ui/icons';

interface IconProps {
    iconNode?: IconComponent | null;
    className?: string;
}

export function Icon({ iconNode: IconComponent, className }: IconProps) {
    if (!IconComponent) {
        return null;
    }

    return <IconComponent className={className} />;
}
