<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedbackType: string
{
    case Bug = 'bug';
    case Feedback = 'feedback';
    case Question = 'question';

    public function label(): string
    {
        return match ($this) {
            self::Bug => '🐞 Bug',
            self::Feedback => '💡 Feedback',
            self::Question => '❓ Question',
        };
    }

    public function color(): int
    {
        return match ($this) {
            self::Bug => 0xED4245,      // Discord red
            self::Feedback => 0x5865F2, // Discord blurple
            self::Question => 0xFEE75C, // Discord yellow
        };
    }
}
