import rawAppVersion from '../../../VERSION?raw';

export const appVersion = rawAppVersion.trim();
export const githubReleaseUrl = `https://github.com/coollabsio/shoutrrr/releases/tag/${appVersion}`;
