<?php

use App\Models\User;

function pollingOwner(): User
{
    $owner = User::factory()->instanceOwner()->create();
    test()->actingAs($owner);

    return $owner;
}

/**
 * @param  array<string, int>  $overrides  dotted-path => value
 * @return array<string, mixed>
 */
function fullPollingPayload(array $overrides = []): array
{
    $payload = [
        'engagement' => [
            'enabled' => ['x' => true, 'bluesky' => true, 'linkedin' => true, 'facebook' => true, 'instagram' => true, 'threads' => true],
            'x' => 15, 'bluesky' => 15, 'linkedin' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
        ],
        'post_metrics' => [
            'enabled' => ['x' => true, 'bluesky' => true, 'linkedin' => true, 'facebook' => true, 'instagram' => true, 'threads' => true, 'discord' => true],
            'x' => 15, 'bluesky' => 15, 'linkedin' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15, 'discord' => 15,
        ],
        'account_metrics' => [
            'enabled' => ['x' => true, 'bluesky' => true, 'linkedin' => true, 'facebook' => true, 'instagram' => true, 'threads' => true],
            'x' => 15, 'bluesky' => 15, 'linkedin' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
        ],
    ];

    foreach ($overrides as $path => $value) {
        data_set($payload, $path, $value);
    }

    return $payload;
}

test('the polling page exposes per-section platform lists', function () {
    pollingOwner();

    test()->get(route('instance-settings.polling'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/instance-polling')
            ->where('sections.engagement', fn ($p) => collect($p)->pluck('platform')->all()
                === ['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads'])
            ->where('sections.post_metrics', fn ($p) => collect($p)->pluck('platform')->all()
                === ['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads', 'discord'])
            ->where('sections.account_metrics', fn ($p) => collect($p)->pluck('platform')->all()
                === ['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads']),
        );
});

test('the update request rejects a payload missing a supported platform', function () {
    pollingOwner();

    // engagement omits facebook/instagram/threads (now required) -> invalid.
    test()->put(route('instance-settings.polling.update'), [
        'engagement' => ['enabled' => ['x' => true, 'bluesky' => true, 'linkedin' => true], 'x' => 15, 'bluesky' => 15, 'linkedin' => 15],
        'post_metrics' => ['enabled' => ['x' => true, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true, 'discord' => true], 'x' => 15, 'bluesky' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15, 'discord' => 15],
        'account_metrics' => ['enabled' => ['x' => true, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true], 'x' => 15, 'bluesky' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15],
    ])->assertSessionHasErrors('engagement.enabled.facebook');
});

test('the update request rejects an out-of-range interval', function () {
    pollingOwner();

    test()->put(route('instance-settings.polling.update'), fullPollingPayload(['post_metrics.discord' => 4]))
        ->assertSessionHasErrors('post_metrics.discord');
});
