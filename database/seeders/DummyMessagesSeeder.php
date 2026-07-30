<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SendStatus;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Seeds a unified DM inbox for local development so the Messages tab, the
 * reply-window banner, media bubbles, and send-status states can be exercised
 * without a live platform connection.
 *
 * Creates conversations across X / Bluesky / Instagram / Facebook, mixing
 * unread, read, archived, open/closed messaging windows, and threads that
 * carry image attachments on outbound bubbles.
 *
 * Run via `composer dev`, `php artisan db:seed` (local), or:
 * php artisan db:seed --class=DummyMessagesSeeder
 */
class DummyMessagesSeeder extends Seeder
{
    public const int CONVERSATION_COUNT = 24;

    /**
     * Marks every remote id this seeder writes so re-running it wipes only its
     * own conversations, mirroring DummyEngagementSeeder's post marker.
     */
    private const string REMOTE_PREFIX = 'dummy-dm-';

    /**
     * @var list<string>
     */
    private const array INBOUND_LINES = [
        'Hey! Loved the volume-backups thread — any docs on restore?',
        'Quick one: does the cloud plan include the engagement inbox?',
        'Can you DM me the beta invite? Been waiting all week 🙌',
        'We migrated three VPS to Coolify over the weekend, flawless.',
        'Is there a way to bulk-schedule from a CSV?',
        'Your reply-window handling on IG is so much cleaner than the app.',
        'Sent you the logo files — let me know if the SVG works.',
        'Following up on the partnership email from Tuesday.',
        'Does the API support programmatic DMs yet, or replies only?',
        'This is exactly the workflow we needed. Take my money 😄',
        'Small bug: the unread badge lingered after I archived a thread.',
        'Any chance of Threads DM support down the line?',
    ];

    /**
     * @var list<string>
     */
    private const array OUTBOUND_LINES = [
        'Thanks for reaching out! Restore docs are landing this week.',
        'Yes — the engagement inbox ships on every cloud plan.',
        'Just sent the invite your way, check your other inbox too 🙂',
        'Amazing to hear! Mind if we quote that on the site?',
        'Not yet, but CSV import is next on the roadmap.',
        'Appreciate it — that means a lot coming from your team.',
        'Got the files, the SVG is perfect. Shipping it Monday.',
        'Looping in our partnerships lead now, expect a reply soon.',
    ];

    public function run(): void
    {
        $workspace = Workspace::query()->where('slug', 'test-workspace')->first()
            ?? Workspace::query()->first();

        if ($workspace === null) {
            $this->command->warn('No workspace found — run DefaultUserSeeder first.');

            return;
        }

        $author = User::query()->find($workspace->owner_id) ?? User::query()->first();
        $this->clearPrevious($workspace);

        $accounts = $this->accounts($workspace, $author);

        $createdConversations = 0;
        $createdMessages = 0;

        for ($i = 0; $i < self::CONVERSATION_COUNT; $i++) {
            /** @var ConnectedAccount $account */
            $account = $accounts[$i % $accounts->count()];
            $createdMessages += $this->seedConversation($workspace, $account, $i);
            $createdConversations++;
        }

        $this->command->info(
            "Seeded {$createdConversations} DM conversations ({$createdMessages} messages) into '{$workspace->name}'.",
        );
    }

    /**
     * DM-capable platforms only: X, Bluesky, Instagram and Facebook. Bluesky is
     * included deliberately so the media-gate (no attachments) is visible.
     *
     * @return Collection<int, ConnectedAccount>
     */
    private function accounts(Workspace $workspace, ?User $author): Collection
    {
        $specs = [
            [Platform::X, '@acme', 'Acme'],
            [Platform::Bluesky, '@acme.bsky.social', 'Acme'],
            [Platform::Instagram, 'acme', 'Acme'],
            [Platform::Facebook, 'Acme Page', 'Acme'],
        ];

        return collect($specs)->map(
            function (array $spec) use ($workspace, $author): ConnectedAccount {
                /** @var Platform $platform */
                [$platform, $handle, $displayName] = $spec;

                $account = ConnectedAccount::query()->firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'platform' => $platform->value,
                        'handle' => $handle,
                    ],
                    [
                        'display_name' => $displayName,
                        'avatar_url' => 'https://api.dicebear.com/9.x/initials/svg?seed='.urlencode($displayName).'&backgroundType=gradientLinear',
                        'remote_account_id' => $platform->value.'-dm-'.$workspace->id,
                        'auth_method' => $platform === Platform::Bluesky ? 'app_password' : 'oauth',
                        'connected_by_user_id' => $author?->id,
                        'status' => ConnectedAccountStatus::Active->value,
                    ],
                );

                return $account->refresh();
            },
        )->values();
    }

    private function seedConversation(Workspace $workspace, ConnectedAccount $account, int $index): int
    {
        $platform = $account->platform;
        $counterpart = $this->fakeCounterpart($index);
        $startedAt = now()->subHours($index + 1)->subMinutes($index * 7);

        // Rotate shapes so the inbox has variety for the list + thread states.
        $shape = $index % 6;
        $isArchived = $shape === 0;
        $isUnread = $shape === 1 || $shape === 2;
        $isMeta = in_array($platform, [Platform::Instagram, Platform::Facebook], true);

        // Meta enforces a 24h reply window; close it on one shape to surface the
        // "reply window closed" banner. Other platforms have no window (null).
        $windowExpiresAt = match (true) {
            ! $isMeta => null,
            $shape === 5 => now()->subHours(2),
            default => now()->addHours(20),
        };

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->create([
            'workspace_id' => $workspace->id,
            'connected_account_id' => $account->id,
            'platform' => $platform->value,
            'remote_conversation_id' => self::REMOTE_PREFIX.$platform->value.'-'.$index,
            'counterpart_handle' => $counterpart['handle'],
            'counterpart_name' => $counterpart['name'],
            'counterpart_avatar_url' => $counterpart['avatar'],
            'counterpart_remote_id' => self::REMOTE_PREFIX.'user-'.$index,
            'unread_count' => 0,
            'last_synced_at' => now()->subMinutes(3),
            'messaging_window_expires_at' => $windowExpiresAt,
        ]);

        $messages = $this->messagePlan($index, $platform, $shape);
        $cursor = $startedAt->copy();
        $unread = 0;
        $lastText = '';
        $lastAt = $cursor->copy();

        foreach ($messages as $slot => $plan) {
            $cursor = $cursor->addMinutes(6 + ($slot * 4));
            $inbound = $plan['direction'] === MessageDirection::Inbound;

            DirectMessage::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'remote_message_id' => self::REMOTE_PREFIX.'msg-'.$index.'-'.$slot,
                'direction' => $plan['direction']->value,
                'author_remote_id' => $inbound ? self::REMOTE_PREFIX.'user-'.$index : $account->remote_account_id,
                'text' => $plan['text'],
                'attachments' => $plan['attachments'],
                'remote_created_at' => $cursor->copy(),
                'is_ours' => ! $inbound,
                'send_status' => $inbound ? null : $plan['send_status']?->value,
                'our_remote_id' => $inbound ? null : self::REMOTE_PREFIX.'our-'.$index.'-'.$slot,
            ]);

            if ($inbound) {
                $unread++;
            } else {
                // An outbound reply clears the unread run before it.
                $unread = 0;
            }

            $lastText = (string) ($plan['text'] ?? '');
            $lastAt = $cursor->copy();
        }

        $conversation->forceFill([
            'last_message_at' => $lastAt,
            'last_message_preview' => Str::limit($lastText, 120),
            'unread_count' => $isUnread ? max(1, $unread) : 0,
            'read_at' => $isUnread ? null : $lastAt,
            'archived_at' => $isArchived ? $lastAt->copy()->addMinutes(5) : null,
        ])->save();

        return count($messages);
    }

    /**
     * Builds the message list for a conversation: an inbound opener, our reply,
     * a follow-up, and — where the platform + shape allow — an image bubble and
     * varied send-status states (sent / sending / failed).
     *
     * @return list<array{direction: MessageDirection, text: ?string, attachments: ?array<int, array<string, mixed>>, send_status: ?SendStatus}>
     */
    private function messagePlan(int $index, Platform $platform, int $shape): array
    {
        $withMedia = $platform->supportsDirectMessageMedia() && $shape === 3;

        $sendStatus = match ($shape) {
            4 => SendStatus::Sending,
            5 => SendStatus::Failed,
            default => SendStatus::Sent,
        };

        $plan = [
            [
                'direction' => MessageDirection::Inbound,
                'text' => self::INBOUND_LINES[$index % count(self::INBOUND_LINES)],
                'attachments' => null,
                'send_status' => null,
            ],
            [
                'direction' => MessageDirection::Outbound,
                'text' => self::OUTBOUND_LINES[$index % count(self::OUTBOUND_LINES)],
                'attachments' => $withMedia ? $this->imageAttachment($index) : null,
                'send_status' => $sendStatus,
            ],
        ];

        // A follow-up inbound on every other conversation, so some threads are
        // longer and some end on our reply.
        if ($index % 2 === 0) {
            $plan[] = [
                'direction' => MessageDirection::Inbound,
                'text' => self::INBOUND_LINES[($index + 5) % count(self::INBOUND_LINES)],
                'attachments' => null,
                'send_status' => null,
            ];
        }

        return $plan;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imageAttachment(int $index): array
    {
        return [[
            'kind' => 'image',
            'url' => 'https://picsum.photos/seed/dm'.$index.'/480/320',
            'mime' => 'image/jpeg',
            'alt_text' => 'Shared image',
        ]];
    }

    /**
     * @return array{handle: string, name: string, avatar: string}
     */
    private function fakeCounterpart(int $index): array
    {
        $names = [
            'Maya Fischer', 'Leon Brandt', 'Priya Nair', 'Tomás Rivera', 'Aiko Tanaka',
            'Noah Bennett', 'Sofia Costa', 'Idris Bello', 'Elena Popa', 'Marcus Cole',
            'Hana Farouk', 'Jules Moreau', 'Ravi Shankar', 'Greta Lind', 'Omar Haddad',
            'Yuki Nakamura', 'Clara Novak', 'Desmond Park', 'Ingrid Solberg', 'Théo Girard',
            'Amara Okafor', 'Lucas Meyer', 'Nadia Rahman', 'Felix Braun',
        ];

        $name = $names[$index % count($names)];
        $handle = '@'.Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return [
            'handle' => $handle,
            'name' => $name,
            'avatar' => 'https://api.dicebear.com/9.x/thumbs/svg?seed='.urlencode($name),
        ];
    }

    private function clearPrevious(Workspace $workspace): void
    {
        $conversations = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->where('remote_conversation_id', 'like', self::REMOTE_PREFIX.'%')
            ->get();

        foreach ($conversations as $conversation) {
            $conversation->messages()->delete();
            $conversation->delete();
        }
    }
}
