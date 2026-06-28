export type VideoAspectPreset =
    | 'auto'
    | '1:1'
    | '4:5'
    | '16:9'
    | '9:16'
    | 'freeform';

export const VIDEO_ASPECT_PRESETS: readonly {
    value: VideoAspectPreset;
    label: string;
}[] = [
    { value: 'auto', label: 'Auto' },
    { value: '1:1', label: '1:1' },
    { value: '4:5', label: '4:5' },
    { value: '16:9', label: '16:9' },
    { value: '9:16', label: '9:16' },
    { value: 'freeform', label: 'Free' },
] as const;

export function videoAspectToRatio(aspect: VideoAspectPreset): number | null {
    switch (aspect) {
        case '1:1':
            return 1;
        case '4:5':
            return 4 / 5;
        case '16:9':
            return 16 / 9;
        case '9:16':
            return 9 / 16;
        case 'auto':
        case 'freeform':
            return null;
    }
}
