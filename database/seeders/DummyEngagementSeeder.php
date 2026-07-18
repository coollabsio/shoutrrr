<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\ReplyStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Seeds a large engagement inbox for local development so keyboard triage,
 * filters, and long-thread layout can be exercised without real platform data.
 *
 * Creates 65+ inbound conversations across X / Bluesky / LinkedIn, with a mix
 * of unread, read, responded, archived, and multi-message threads.
 *
 * Run via `composer dev`, `php artisan db:seed` (local), or:
 * php artisan db:seed --class=DummyEngagementSeeder
 */
class DummyEngagementSeeder extends Seeder
{
    public const int CONVERSATION_COUNT = 65;

    private const string POST_MARKER = '[dummy-engagement]';

    /**
     * @var list<string>
     */
    private const array SAMPLE_REPLIES = [
        'Love this — when does it ship?',
        'Been waiting for volume backups forever. Thank you!',
        'Any plans for TikTok / YouTube / Instagram next?',
        'Does Coolify have something like migrate a whole stack between VPS providers?',
        'Would love a Twitch stream walking through the mobile version.',
        'The distinction isn’t free vs paid — it’s philosophy. Postiz is excellent too.',
        'WhatsApp would be huge for Indonesia.',
        'Can we have backups for ClickHouse DB too? 🙏',
        'postiz is also free to self host',
        '🔥🔥🔥🔥🔥',
        'Awesome! Will keep an eye out for it :)',
        'Any plans for an API on the cloud version for programmatic scheduling?',
        'I never managed to successfully mount a volume storage to a container. Better docs with a video would help a ton.',
        'Ship then refactor the UI, I was just joking ofc! Thanks Andras',
        'Ah, very welcome feature! Currently using a cronjob for volume paths.',
        'hey any plan to adopt EntireHQ?',
        'Waiting for Kubernetes integration',
        'very good, thank you! An encryption system is still missing for S3 backups — is S3 without encryption secure?',
        'UI/UX needs a real upgrade 👀',
        'the man. the goat. the legend.',
        'We don’t deserve you',
        'Moot point. EU shares one token around the whole continent to ensure CO2 is minimised.',
        'You should check this: https://t.co/example Probably their API does the same thing 👍',
        'check DM',
        'This is exactly the workflow we’ve been missing.',
        'Does multi-repo support land in the same release?',
        'How does rate limiting work when many workspaces share an account?',
        'Please add dark-mode polish to the engagement desk 🌙',
        'Is there a way to filter by account and unread at the same time?',
        'Long threads get messy — sticky reply input would be chef’s kiss.',
        'Keyboard shortcuts for archive would save me hours.',
        'We get a lot of LinkedIn comments; sorting by recency would help.',
        'Bluesky replies feel snappier than X for us.',
        'Can replies include images / short clips?',
        'Would love MCP support for bulk triage.',
        'Does this work offline / self-hosted only, or cloud too?',
        'Feature request: bulk archive after responding.',
        'The post preview excerpt in the stream is super useful.',
        'Any SAML / SSO on the roadmap for teams?',
        'How do you handle deleted remote replies?',
        'Curious about notification digests vs real-time.',
        'This inbox is cleaner than managing three native apps.',
        'Please don’t make engagement a paid-only gate 😅',
        'Works great with our existing Coolify deploys.',
        'Docs link from the empty state would reduce support pings.',
        'Can I assign a teammate to a conversation?',
        'Emoji reactions would be a nice light-weight respond option.',
        'Is there a webhook when a new reply lands?',
        'The relative timestamps (“4h”) are perfect for triage.',
        'Some of these are spam — quick mute/block would help.',
        'How are conversation threads reconstructed across platforms?',
        'Love that our outbound replies show as “You”.',
        'Failed send status with retry would reduce anxiety.',
        'Does the archive sync back to the native platform?',
        'I want j/k navigation like Gmail.',
        'R to focus reply is muscle memory from other tools.',
        'A for archive — chef’s kiss if you add it.',
        'Does filtering by post keep selection stable?',
        'Pagination felt fine at 25/page, but I want denser rows.',
        'Can we pin a conversation to the top temporarily?',
        'Team mentions in replies would be powerful.',
        'How do you deal with quote-posts vs direct replies?',
        'Please keep the stream lightweight as volume grows.',
        'Self-hosters will abuse this for support desks 😄',
        'Solid work. Shipping cadence has been impressive.',
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
        $targets = $this->publishedTargets($workspace, $author, $accounts);

        $createdConversations = 0;
        $createdRows = 0;

        for ($i = 0; $i < self::CONVERSATION_COUNT; $i++) {
            /** @var PostTarget $target */
            $target = $targets[$i % $targets->count()];
            $createdRows += $this->seedConversation($workspace, $target, $i);
            $createdConversations++;
        }

        $this->command->info(
            "Seeded {$createdConversations} engagement conversations ({$createdRows} reply rows) into '{$workspace->name}'.",
        );
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function accounts(Workspace $workspace, ?User $author): Collection
    {
        $specs = [
            [Platform::X, '@acme', 'Acme'],
            [Platform::Bluesky, '@acme.bsky.social', 'Acme'],
            [Platform::LinkedIn, 'acme-inc', 'Acme Inc'],
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
                        'remote_account_id' => $platform->value.'-engagement-'.$workspace->id,
                        'auth_method' => $platform === Platform::Bluesky ? 'app_password' : 'oauth',
                        'connected_by_user_id' => $author?->id,
                        'status' => ConnectedAccountStatus::Active->value,
                    ],
                );

                $account->forceFill(['disabled_at' => now()])->save();

                return $account->refresh();
            },
        )->values();
    }

    /**
     * @param  Collection<int, ConnectedAccount>  $accounts
     * @return Collection<int, PostTarget>
     */
    private function publishedTargets(Workspace $workspace, ?User $author, Collection $accounts): Collection
    {
        $posts = [
            'Just shipped volume backups for Coolify. Long-awaited, finally here. '.self::POST_MARKER,
            'Working on a focused engagement inbox — lightweight triage for replies across platforms. '.self::POST_MARKER,
            'Self-hosting social tools should not mean juggling five native apps. '.self::POST_MARKER,
            'Keyboard-first workflows for creators: archive, reply, next. '.self::POST_MARKER,
            'Cloud API for programmatic scheduling is coming — MCP optional. '.self::POST_MARKER,
        ];

        $targets = collect();

        foreach ($posts as $index => $text) {
            /** @var Post $post */
            $post = Post::query()->create([
                'workspace_id' => $workspace->id,
                'account_set_id' => null,
                'author_id' => $author?->id,
                'base_text' => $text,
                'segments' => [$text],
                'mentions' => null,
                'status' => PostStatus::Published->value,
                'published_at' => now()->subDays(5 - $index)->subHours($index * 3),
            ]);

            foreach ($accounts as $accountIndex => $account) {
                $targets->push(PostTarget::query()->create([
                    'post_id' => $post->id,
                    'connected_account_id' => $account->id,
                    'platform' => $account->platform->value,
                    'sections' => [$text],
                    'auto_split' => false,
                    'status' => PostTargetStatus::Published->value,
                    'remote_id' => $this->remotePostId($account->platform, $index, $accountIndex),
                    'posted_at' => $post->published_at,
                ]));
            }
        }

        return $targets->values();
    }

    private function seedConversation(Workspace $workspace, PostTarget $target, int $index): int
    {
        $platform = $target->platform;
        $conversationId = $this->remoteReplyId($platform, $index, 'root');
        $author = $this->fakeAuthor($index);
        $createdAt = now()->subMinutes(30 + ($index * 17));
        $rows = 0;

        // Rotate shapes so the inbox has variety for filters + keyboard triage.
        $shape = $index % 10;

        $status = match (true) {
            $shape === 0 => ReplyStatus::Archived,
            $shape === 1, $shape === 2 => ReplyStatus::Responded,
            default => ReplyStatus::Pending,
        };

        $readAt = match (true) {
            $status === ReplyStatus::Archived => $createdAt->copy()->addMinutes(5),
            $status === ReplyStatus::Responded => $createdAt->copy()->addMinutes(8),
            $shape === 3, $shape === 4 => null, // unread
            default => $createdAt->copy()->addMinutes(12),
        };

        $rootText = self::SAMPLE_REPLIES[$index % count(self::SAMPLE_REPLIES)];

        PostTargetReply::query()->create([
            'workspace_id' => $workspace->id,
            'post_target_id' => $target->id,
            'platform' => $platform->value,
            'remote_reply_id' => $conversationId,
            'remote_cid' => $platform === Platform::Bluesky ? 'cid-'.Str::lower(Str::random(10)) : null,
            'parent_remote_id' => $target->remote_id,
            'conversation_remote_id' => $conversationId,
            'author_handle' => $author['handle'],
            'author_name' => $author['name'],
            'author_avatar_url' => $author['avatar'],
            'text' => $rootText,
            'remote_created_at' => $createdAt,
            'read_at' => $readAt,
            'status' => $status->value,
            'our_reply_remote_id' => $status === ReplyStatus::Responded
                ? $this->remoteReplyId($platform, $index, 'ours')
                : null,
            'liked_at' => $shape === 5 ? $createdAt->copy()->addMinutes(3) : null,
            'like_remote_id' => $shape === 5 ? 'like-'.$index : null,
            'is_ours' => false,
            'send_status' => null,
            'fetched_at' => now()->subMinutes(5),
        ]);
        $rows++;

        // Multi-message threads on every third conversation.
        if ($index % 3 === 0) {
            $followUpAt = $createdAt->copy()->addMinutes(20);
            PostTargetReply::query()->create([
                'workspace_id' => $workspace->id,
                'post_target_id' => $target->id,
                'platform' => $platform->value,
                'remote_reply_id' => $this->remoteReplyId($platform, $index, 'follow'),
                'remote_cid' => $platform === Platform::Bluesky ? 'cid-'.Str::lower(Str::random(10)) : null,
                'parent_remote_id' => $conversationId,
                'conversation_remote_id' => $conversationId,
                'author_handle' => $author['handle'],
                'author_name' => $author['name'],
                'author_avatar_url' => $author['avatar'],
                'text' => 'Also: '.self::SAMPLE_REPLIES[($index + 7) % count(self::SAMPLE_REPLIES)],
                'remote_created_at' => $followUpAt,
                'read_at' => $readAt === null ? null : $followUpAt->copy()->addMinutes(2),
                'status' => $status->value,
                'our_reply_remote_id' => null,
                'is_ours' => false,
                'send_status' => null,
                'fetched_at' => now()->subMinutes(4),
            ]);
            $rows++;
        }

        if ($status === ReplyStatus::Responded) {
            $oursAt = $createdAt->copy()->addMinutes(25);
            PostTargetReply::query()->create([
                'workspace_id' => $workspace->id,
                'post_target_id' => $target->id,
                'platform' => $platform->value,
                'remote_reply_id' => $this->remoteReplyId($platform, $index, 'ours'),
                'remote_cid' => $platform === Platform::Bluesky ? 'cid-'.Str::lower(Str::random(10)) : null,
                'parent_remote_id' => $conversationId,
                'conversation_remote_id' => $conversationId,
                'author_handle' => $target->account->handle ?? '@you',
                'author_name' => $target->account->display_name ?? 'You',
                'author_avatar_url' => $target->account->avatar_url,
                'text' => match ($index % 3) {
                    0 => 'Thanks for the feedback — this is on the roadmap!',
                    1 => 'Great question. Docs update coming this week.',
                    default => 'Appreciate you taking the time to write this.',
                },
                'remote_created_at' => $oursAt,
                'read_at' => $oursAt,
                'status' => ReplyStatus::Responded->value,
                'our_reply_remote_id' => null,
                'is_ours' => true,
                'send_status' => null,
                'fetched_at' => now()->subMinutes(3),
            ]);
            $rows++;
        }

        return $rows;
    }

    /**
     * @return array{handle: string, name: string, avatar: string}
     */
    private function fakeAuthor(int $index): array
    {
        $names = [
            'Code With Joe', 'Matt Blake', '0xf', 'Mario Palomera', 'Rick De Oliveira',
            'Dr Mahamadou Kante', 'Rifandani', 'Eric Tuon', 'Janek Skumpuntele',
            'Shahryar Tavakkoli', 'blockblane', 'Aditya Tripathi', 'Maelstrom', 'Farès',
            'rootkid', 'Moinul Moin', 'Ananya Pathak', 'Thomas', 'AHU', 'Beach Please',
            'Massi', 'Kristof Kowalski', 'Mohamed AbdElgwad', 'Lena Ortega', 'Samir K.',
            'Priya Nair', 'Jonah Wells', 'Hana Sato', 'Diego Martins', 'Nora Quinn',
        ];

        $name = $names[$index % count($names)];
        $handle = '@'.Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return [
            'handle' => $handle,
            'name' => $name,
            'avatar' => 'https://api.dicebear.com/9.x/thumbs/svg?seed='.urlencode($name),
        ];
    }

    private function remotePostId(Platform $platform, int $postIndex, int $accountIndex): string
    {
        return match ($platform) {
            Platform::X => (string) (1_700_000_000_000_000_000 + ($postIndex * 100) + $accountIndex),
            Platform::LinkedIn => 'urn:li:share:'.(7_000_000_000_000_000_000 + ($postIndex * 10) + $accountIndex),
            Platform::Bluesky => 'at://did:plc:acmeengagement/app.bsky.feed.post/dummy'.$postIndex.$accountIndex,
            default => 'remote-post-'.$platform->value.'-'.$postIndex.'-'.$accountIndex,
        };
    }

    private function remoteReplyId(Platform $platform, int $index, string $suffix): string
    {
        $slot = match ($suffix) {
            'root' => 0,
            'follow' => 1,
            'ours' => 2,
            default => 9,
        };

        return match ($platform) {
            Platform::X => (string) (1_800_000_000_000_000_000 + ($index * 10) + $slot),
            Platform::LinkedIn => 'urn:li:comment:'.(8_000_000_000_000_000_000 + ($index * 10) + $slot),
            Platform::Bluesky => 'at://did:plc:fan'.$index.'/app.bsky.feed.post/'.$suffix.$index,
            default => 'remote-reply-'.$platform->value.'-'.$index.'-'.$suffix,
        };
    }

    private function clearPrevious(Workspace $workspace): void
    {
        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->where('base_text', 'like', '%'.self::POST_MARKER.'%')
            ->get();

        foreach ($posts as $post) {
            $targetIds = $post->targets()->pluck('id');
            PostTargetReply::query()->whereIn('post_target_id', $targetIds)->delete();
            $post->targets()->delete();
            $post->delete();
        }
    }
}
