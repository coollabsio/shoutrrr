<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Posts\NextSlotResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NextSlotController extends Controller
{
    public function __construct(private readonly NextSlotResolver $resolver) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        return response()->json($this->resolver->summary($workspace));
    }
}
