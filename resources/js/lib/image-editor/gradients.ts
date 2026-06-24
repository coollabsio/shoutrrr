export type GradientStop = { color: string; at: number };

export type GradientFill = {
    type: 'gradient';
    id: string;
    angle: number;
    stops: GradientStop[];
};

/** Future background sources (solid, image, …) join this union. */
export type BackgroundFill = GradientFill;

export type GradientPreset = {
    id: string;
    name: string;
    angle: number;
    stops: GradientStop[];
};

function stops(...colors: string[]): GradientStop[] {
    const last = colors.length - 1;

    return colors.map((color, i) => ({ color, at: last === 0 ? 0 : i / last }));
}

export const GRADIENTS: GradientPreset[] = [
    {
        id: 'sunset',
        name: 'Sunset',
        angle: 135,
        stops: stops('#ff9a9e', '#fad0c4'),
    },
    {
        id: 'oceanic',
        name: 'Oceanic',
        angle: 135,
        stops: stops('#2193b0', '#6dd5ed'),
    },
    {
        id: 'grape',
        name: 'Grape',
        angle: 135,
        stops: stops('#a18cd1', '#fbc2eb'),
    },
    {
        id: 'citrus',
        name: 'Citrus',
        angle: 135,
        stops: stops('#f6d365', '#fda085'),
    },
    {
        id: 'mint',
        name: 'Mint',
        angle: 135,
        stops: stops('#43e97b', '#38f9d7'),
    },
    {
        id: 'royal',
        name: 'Royal',
        angle: 135,
        stops: stops('#667eea', '#764ba2'),
    },
    {
        id: 'ember',
        name: 'Ember',
        angle: 135,
        stops: stops('#f09819', '#edde5d'),
    },
    {
        id: 'dusk',
        name: 'Dusk',
        angle: 135,
        stops: stops('#4b6cb7', '#182848'),
    },
    {
        id: 'rose',
        name: 'Rose',
        angle: 135,
        stops: stops('#e55d87', '#5fc3e4'),
    },
    {
        id: 'slate',
        name: 'Slate',
        angle: 135,
        stops: stops('#1f2937', '#4b5563'),
    },
    {
        id: 'aurora',
        name: 'Aurora',
        angle: 135,
        stops: stops('#00c6ff', '#0072ff'),
    },
    {
        id: 'peach',
        name: 'Peach',
        angle: 135,
        stops: stops('#ffecd2', '#fcb69f'),
    },
];

export function gradientToFill(preset: GradientPreset): GradientFill {
    return {
        type: 'gradient',
        id: preset.id,
        angle: preset.angle,
        stops: preset.stops,
    };
}

export function findGradient(id: string): GradientPreset | undefined {
    return GRADIENTS.find((g) => g.id === id);
}

export function backgroundCss(fill: BackgroundFill): string {
    const stopList = fill.stops
        .map((s) => `${s.color} ${Math.round(s.at * 100)}%`)
        .join(', ');

    return `linear-gradient(${fill.angle}deg, ${stopList})`;
}
