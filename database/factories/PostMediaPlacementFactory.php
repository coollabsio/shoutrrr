<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostMediaPlacement> */
class PostMediaPlacementFactory extends Factory
{
    protected $model = PostMediaPlacement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_target_id' => PostTarget::factory(),
            'post_media_id' => PostMedia::factory(),
            'segment_ref' => '__head__',
            'position' => 0,
        ];
    }
}
