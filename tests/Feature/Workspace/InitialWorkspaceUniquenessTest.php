<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Build a workspace the way a process that lost the race would: the `creating`
 * hook read an empty table, so it carries `is_initial = true` into the insert.
 */
function workspaceClaimingInitialFlag(): Workspace
{
    $workspace = new Workspace;

    $workspace->forceFill([
        'name' => 'Racing Workspace',
        'slug' => 'racing-workspace-'.Str::lower(Str::random(5)),
        'owner_id' => User::factory()->create()->id,
        'is_initial' => true,
    ]);

    return $workspace;
}

test('the first workspace on the instance is the initial one', function (): void {
    expect(Workspace::factory()->create()->is_initial)->toBeTrue()
        ->and(Workspace::factory()->create()->is_initial)->toBeFalse();
});

test('the database refuses a second initial workspace', function (): void {
    Workspace::factory()->create();
    $second = Workspace::factory()->create();

    expect(fn (): int => DB::table('workspaces')
        ->where('id', $second->id)
        ->update(['is_initial' => true]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a workspace that loses the race for the initial flag is created as non-initial', function (): void {
    $winner = Workspace::factory()->create();

    $loser = workspaceClaimingInitialFlag();
    $loser->save();

    expect($loser->exists)->toBeTrue()
        ->and($loser->is_initial)->toBeFalse()
        ->and($winner->refresh()->is_initial)->toBeTrue()
        ->and(Workspace::where('is_initial', true)->count())->toBe(1);
});

test('losing the race inside a transaction leaves the transaction usable', function (): void {
    $winner = Workspace::factory()->create();

    DB::transaction(function (): void {
        workspaceClaimingInitialFlag()->save();

        // Would fail with SQLSTATE[25P02] on PostgreSQL if the losing insert had
        // aborted the surrounding transaction instead of rolling back a savepoint.
        Workspace::query()->count();
    });

    expect($winner->refresh()->is_initial)->toBeTrue()
        ->and(Workspace::where('is_initial', true)->count())->toBe(1)
        ->and(Workspace::count())->toBe(2);
});
