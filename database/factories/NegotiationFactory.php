<?php

namespace Database\Factories;

use App\Models\Negotiation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NegotiationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Negotiation::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'status' => 'draft',
            'approval_level' => null,
            'formalization_status' => null,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
            'notes' => $this->faker->text(),
            'creator_id' => User::factory(),
        ];
    }

    /**
     * Indicate that the negotiation is pending approval.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function pendingApproval()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'submitted',
                'approval_level' => 'pending_approval',
            ];
        });
    }

    /**
     * Indicate that the negotiation is approved.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function approved()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'approved',
                'approval_level' => null,
                'formalization_status' => 'pending_aditivo',
                'approved_by' => User::factory(),
                'approved_at' => now(),
            ];
        });
    }

    /**
     * Indicate that the negotiation is rejected.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function rejected()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'rejected',
                'approval_level' => null,
                'rejected_by' => User::factory(),
                'rejected_at' => now(),
            ];
        });
    }
} 