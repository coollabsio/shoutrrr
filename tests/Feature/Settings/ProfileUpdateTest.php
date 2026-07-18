<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    config(['auth.email_verification.enabled' => true]);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('profile photo can be uploaded', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.public_images' => null,
    ]);
    Storage::fake('s3');

    $user = User::factory()->create();
    $photo = UploadedFile::fake()->image('avatar.jpg');

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => $photo,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->avatar_path)->toStartWith('profile-photos/');
    expect($user->avatar)->toContain($user->avatar_path);
    Storage::disk('s3')->assertExists($user->avatar_path);
});

test('profile photo uses the configured public image disk', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.public_images' => 'public-images',
        'filesystems.disks.public-images.url' => 'https://cdn.shoutrrr.com',
        'filesystems.disks.public-images.visibility' => 'public',
    ]);
    Storage::fake('s3');
    Storage::fake('public-images', ['url' => 'https://cdn.shoutrrr.com']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('avatar.jpg'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->avatar_path)->toStartWith('profile-photos/');
    expect($user->avatar)->toBe("https://cdn.shoutrrr.com/{$user->avatar_path}");
    Storage::disk('public-images')->assertExists($user->avatar_path);
    Storage::disk('s3')->assertMissing($user->avatar_path);
});

test('profile photo must be an image', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->create('avatar.txt', 1, 'text/plain'),
        ])
        ->assertSessionHasErrors('photo')
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    config(['auth.email_verification.enabled' => true]);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('email verification status is unchanged when mail delivery verification is disabled', function () {
    config(['auth.email_verification.enabled' => false]);

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->fresh());
});
