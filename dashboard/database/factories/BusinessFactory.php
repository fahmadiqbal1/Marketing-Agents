<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id'       => User::factory(),
            'name'          => fake()->company(),
            'industry'      => fake()->randomElement([
                'healthcare', 'fitness', 'restaurant', 'retail', 'technology',
                'real_estate', 'beauty', 'education', 'automotive', 'finance',
            ]),
            'website'       => fake()->url(),
            'phone'         => fake()->phoneNumber(),
            'address'       => fake()->address(),
            'brand_voice'   => fake()->randomElement([
                'Professional yet friendly',
                'Casual and approachable',
                'Expert and authoritative',
                'Warm and caring',
                'Bold and energetic',
            ]),
        ];
    }

    /**
     * Set a specific industry.
     */
    public function industry(string $industry): static
    {
        return $this->state(fn (array $attributes) => [
            'industry' => $industry,
        ]);
    }
}
