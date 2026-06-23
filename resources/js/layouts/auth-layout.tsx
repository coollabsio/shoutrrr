import { FlashListener } from '@/components/common/flash-listener';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    brandText,
    children,
}: {
    title?: string;
    description?: string;
    brandText?: string;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate
            title={title}
            description={description}
            brandText={brandText}
        >
            <FlashListener />
            {children}
        </AuthLayoutTemplate>
    );
}
