import AppLogoIcon from '@/components/layout/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-6 shrink-0 items-center justify-center">
                <AppLogoIcon className="size-6 text-primary" />
            </div>
            <div className="grid flex-1 text-left group-data-[collapsible=icon]:hidden">
                <span className="truncate text-[15px] leading-tight font-bold tracking-tight">
                    Shoutrrr
                </span>
            </div>
        </>
    );
}
