import AppLogoIcon from '@/components/layout/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-6 shrink-0 items-center justify-center">
                <AppLogoIcon className="size-6 text-primary group-data-[collapsible=icon]:mr-1" />
            </div>
            <div className="grid flex-1 text-left">
                <span className="truncate text-[13px] leading-tight font-semibold tracking-tight">
                    Shoutrrr
                </span>
            </div>
        </>
    );
}
