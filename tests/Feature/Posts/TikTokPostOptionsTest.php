<?php

use App\Dto\Post\TikTokPostOptions;
use App\Enums\Platform;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\PublishPrecheck;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->tiktok = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok->value,
    ]);
    $this->post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
});

/**
 * @param  array<string, mixed>  $target
 * @return array<string, mixed>
 */
function tikTokDraftBody(Post $post, array $target): array
{
    return [
        'segments' => ['Behind the scenes'],
        'destination' => ['kind' => 'account', 'id' => $target['connected_account_id']],
        'targets' => [$target],
        'media_ids' => [],
        'expected_updated_at' => $post->fresh()->updated_at->toIso8601String(),
    ];
}

/**
 * @return array<string, mixed>
 */
function tikTokChoices(array $overrides = []): array
{
    return [
        'post_mode' => 'direct',
        'privacy_level' => 'FOLLOWER_OF_CREATOR',
        'allow_comment' => true,
        'allow_duet' => false,
        'allow_stitch' => true,
        'disclose_commercial' => true,
        'brand_organic' => true,
        'branded_content' => false,
        ...$overrides,
    ];
}

test('a new tiktok target starts with TikTok guideline defaults', function () {
    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $this->tiktok->id,
    ]))->assertOk()->assertJsonPath('post.targets.0.tiktok', [
        'post_mode' => 'direct',
        'privacy_level' => null,
        'allow_comment' => false,
        'allow_duet' => false,
        'allow_stitch' => false,
        'disclose_commercial' => false,
        'brand_organic' => false,
        'branded_content' => false,
    ]);
});

test('tiktok choices round-trip through save and the post view', function () {
    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $this->tiktok->id,
        'tiktok' => tikTokChoices(),
    ]))->assertOk()->assertJsonPath('post.targets.0.tiktok', tikTokChoices());

    $options = PostTarget::query()->where('post_id', $this->post->id)->sole()->tiktok_options;

    expect($options)->toBeInstanceOf(TikTokPostOptions::class)
        ->and($options->privacyLevel)->toBe(TikTokPrivacyLevel::FollowerOfCreator)
        ->and($options->allowComment)->toBeTrue()
        ->and($options->brandOrganic)->toBeTrue();
});

test('an update without tiktok choices keeps the saved ones', function () {
    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $this->tiktok->id,
        'tiktok' => tikTokChoices(['post_mode' => 'inbox']),
    ]))->assertOk();

    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $this->tiktok->id,
    ]))->assertOk()->assertJsonPath('post.targets.0.tiktok.post_mode', 'inbox');
});

test('targets on other platforms carry no tiktok choices', function () {
    $x = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X->value]);

    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $x->id,
    ]))->assertOk()->assertJsonPath('post.targets.0.tiktok', null);
});

test('tiktok choices are rejected on another platform', function () {
    $x = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X->value]);

    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $x->id,
        'tiktok' => tikTokChoices(),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['targets.0.tiktok']);
});

test('tiktok choices only accept the documented values', function (array $choices, string $field) {
    $this->actingAs($this->user)->putJson(route('posts.update', $this->post), tikTokDraftBody($this->post, [
        'connected_account_id' => $this->tiktok->id,
        'tiktok' => $choices,
    ]))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'unknown privacy level' => [tikTokChoices(['privacy_level' => 'EVERYONE']), 'targets.0.tiktok.privacy_level'],
    'unknown post mode' => [tikTokChoices(['post_mode' => 'publish']), 'targets.0.tiktok.post_mode'],
    'missing post mode' => [['privacy_level' => 'SELF_ONLY'], 'targets.0.tiktok.post_mode'],
    'non-boolean interaction' => [tikTokChoices(['allow_comment' => 'sometimes']), 'targets.0.tiktok.allow_comment'],
    'promotion without disclosure' => [tikTokChoices(['disclose_commercial' => false, 'brand_organic' => true]), 'targets.0.tiktok.disclose_commercial'],
    'private branded content' => [tikTokChoices(['privacy_level' => 'SELF_ONLY', 'branded_content' => true]), 'targets.0.tiktok.privacy_level'],
]);

test('the api accepts tiktok choices on create', function () {
    [, $workspace, $token] = issuedKey();
    $tiktok = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::TikTok->value]);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'From the API',
        'destination' => ['kind' => 'account', 'id' => $tiktok->id],
        'targets' => [['connected_account_id' => $tiktok->id, 'tiktok' => tikTokChoices(['post_mode' => 'inbox'])]],
    ])->assertCreated()->assertJsonPath('post.targets.0.tiktok.post_mode', 'inbox');

    expect(PostTarget::query()->where('connected_account_id', $tiktok->id)->sole()->tiktok_options->mode)
        ->toBe(TikTokPostMode::Inbox);
});

test('the api rejects inconsistent tiktok choices', function () {
    [, $workspace, $token] = issuedKey();
    $tiktok = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::TikTok->value]);
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'all'],
        'targets' => [
            ['connected_account_id' => $tiktok->id, 'tiktok' => tikTokChoices(['privacy_level' => 'SELF_ONLY', 'branded_content' => true])],
            ['connected_account_id' => $x->id, 'tiktok' => tikTokChoices()],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'targets.0.tiktok.privacy_level',
        'targets.1.tiktok',
    ]);
});

test('choices made before the first save are kept when the composer creates the draft', function () {
    $this->actingAs($this->user)->postJson(route('posts.store'), [
        'segments' => ['Behind the scenes'],
        'destination' => ['kind' => 'account', 'id' => $this->tiktok->id],
        'targets' => [['connected_account_id' => $this->tiktok->id, 'format' => 'feed', 'tiktok' => tikTokChoices()]],
    ])->assertCreated()->assertJsonPath('post.targets.0.tiktok', tikTokChoices());

    $options = PostTarget::query()->where('connected_account_id', $this->tiktok->id)->sole()->tiktok_options;

    expect($options->discloseCommercial)->toBeTrue()
        ->and($options->brandOrganic)->toBeTrue()
        ->and($options->privacyLevel)->toBe(TikTokPrivacyLevel::FollowerOfCreator);
});

test('the composer cannot create a draft with inconsistent tiktok choices', function () {
    $x = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X->value]);

    $this->actingAs($this->user)->postJson(route('posts.store'), [
        'segments' => ['Behind the scenes'],
        'destination' => ['kind' => 'all'],
        'targets' => [
            ['connected_account_id' => $this->tiktok->id, 'tiktok' => tikTokChoices(['disclose_commercial' => false])],
            ['connected_account_id' => $x->id, 'tiktok' => tikTokChoices()],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'targets.0.tiktok.disclose_commercial',
        'targets.1.tiktok',
    ]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

test('the precheck holds a direct post until the TikTok choices are complete', function () {
    PostMedia::factory()->for($this->post)->video()->create();
    PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $this->tiktok->id,
        'platform' => Platform::TikTok->value,
        'sections' => ['Behind the scenes'],
        'tiktok_options' => new TikTokPostOptions(discloseCommercial: true),
    ]);
    $precheck = app(PublishPrecheck::class);

    $blocked = $precheck->blockingTargets($this->post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['tiktok_privacy_required', 'tiktok_disclosure_incomplete'])
        ->and($precheck->describe($blocked[0]['issues'], Platform::TikTok))
        ->toContain('Choose who can view this TikTok post.')
        ->toContain('You need to indicate if your content promotes yourself, a third party, or both.');
});

test('the precheck lets an inbox draft through without choices', function () {
    PostMedia::factory()->for($this->post)->video()->create();
    PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $this->tiktok->id,
        'platform' => Platform::TikTok->value,
        'sections' => ['Behind the scenes'],
        'tiktok_options' => new TikTokPostOptions(mode: TikTokPostMode::Inbox),
    ]);

    expect(app(PublishPrecheck::class)->blockingTargets($this->post->fresh(['targets.account', 'media'])))->toBe([]);
});
