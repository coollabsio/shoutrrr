<?php

namespace Database\Factories;

use App\Models\LegalPage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<LegalPage>
 */
class LegalPageFactory extends Factory
{
    #[Override]
    protected $model = LegalPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'slug' => fake()->unique()->slug(2, false).'-'.Str::lower(Str::random(5)),
            'terms_body' => '<h2>Terms</h2><p>By using this service you agree to the following terms.</p>',
            'terms_published_at' => now(),
            'privacy_body' => '<h2>Privacy</h2><p>We respect your privacy and only collect what we need.</p>',
            'privacy_published_at' => now(),
        ];
    }

    /**
     * Both documents drafted but not yet published.
     */
    public function unpublished(): static
    {
        return $this->state(fn (): array => [
            'terms_published_at' => null,
            'privacy_published_at' => null,
        ]);
    }

    /**
     * Only the terms document is published.
     */
    public function termsOnly(): static
    {
        return $this->state(fn (): array => [
            'privacy_published_at' => null,
        ]);
    }

    /**
     * Only the privacy document is published.
     */
    public function privacyOnly(): static
    {
        return $this->state(fn (): array => [
            'terms_published_at' => null,
        ]);
    }
}
