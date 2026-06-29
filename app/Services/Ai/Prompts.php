<?php

declare(strict_types=1);

namespace App\Services\Ai;

use InvalidArgumentException;

class Prompts
{
    /** @var array<string, string> */
    public const array PRESETS = [
        'shorten' => 'Make it noticeably shorter and tighter while preserving the core message.',
        'expand' => 'Expand it with one or two relevant, concrete details. Do not pad with fluff.',
        'professional' => 'Rewrite it in a polished, professional tone.',
        'casual' => 'Rewrite it in a warm, casual, conversational tone.',
        'punchy' => 'Rewrite it to be punchy and scroll-stopping, with a strong hook.',
        'fix_grammar' => 'Fix spelling, grammar, and punctuation. Keep the wording and tone otherwise unchanged.',
    ];

    private static function base(?string $platform, int $limit): string
    {
        $rules = "You are a social-media copywriter helping draft posts.\n"
            .'Return ONLY the post text — no preamble, quotes, hashtags-explanations, or commentary.';

        if ($platform !== null) {
            $rules .= "\nThe target platform is {$platform}.";
        }

        if ($limit > 0) {
            $rules .= "\nThe text MUST be at most {$limit} characters.";
        }

        return $rules;
    }

    public static function rewrite(?string $platform, int $limit): string
    {
        return self::base($platform, $limit)."\nRewrite the user's draft to improve clarity and impact.";
    }

    public static function preset(string $action, ?string $platform, int $limit): string
    {
        if (! isset(self::PRESETS[$action])) {
            throw new InvalidArgumentException("Unknown preset: {$action}");
        }

        return self::base($platform, $limit)."\n".self::PRESETS[$action];
    }

    public static function generate(?string $platform, int $limit): string
    {
        return self::base($platform, $limit)
            ."\nWrite a post that fulfils the user's instruction.";
    }

    public static function adapt(string $platform, int $limit): string
    {
        return self::base($platform, $limit)
            ."\nAdapt the user's draft specifically for {$platform}, matching that platform's conventions and length.";
    }

    public static function reply(string $platform, int $limit, string $tone): string
    {
        return self::base($platform, $limit)
            ."\nYou are drafting a reply to an incoming comment on {$platform}. "
            ."Use a {$tone} tone. Be specific to the conversation. Return ONLY the reply text.";
    }
}
