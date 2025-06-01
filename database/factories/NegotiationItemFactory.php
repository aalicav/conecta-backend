<?php

namespace Database\Factories;

use App\Models\NegotiationItem;
use App\Models\Negotiation;
use App\Models\Tuss;
use Illuminate\Database\Eloquent\Factories\Factory;

class NegotiationItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = NegotiationItem::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'negotiation_id' => Negotiation::factory(),
            'tuss_id' => Tuss::factory(),
            'proposed_value' => $this->faker->randomFloat(2, 50, 1000),
            'approved_value' => null,
            'status' => 'pending',
            'notes' => $this->faker->sentence(),
            'responded_at' => null,
        ];
    }

    /**
     * Indicate that the item is approved.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function approved()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'approved',
                'approved_value' => $this->faker->randomFloat(2, 50, 1000),
                'responded_at' => now(),
            ];
        });
    }

    /**
     * Indicate that the item is rejected.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function rejected()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'rejected',
                'responded_at' => now(),
            ];
        });
    }

    /**
     * Indicate that the item has a counter offer.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function counterOffered()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'counter_offered',
                'approved_value' => $this->faker->randomFloat(2, 50, 1000),
                'responded_at' => now(),
            ];
        });
    }
} 