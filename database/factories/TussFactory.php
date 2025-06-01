<?php

namespace Database\Factories;

use App\Models\Tuss;
use Illuminate\Database\Eloquent\Factories\Factory;

class TussFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Tuss::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'code' => $this->faker->unique()->numerify('#####'),
            'description' => $this->faker->sentence(),
            'chapter' => $this->faker->numberBetween(1, 10),
            'group' => $this->faker->numberBetween(1, 5),
            'subgroup' => $this->faker->numberBetween(1, 3),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the TUSS procedure is inactive.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inactive()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
} 