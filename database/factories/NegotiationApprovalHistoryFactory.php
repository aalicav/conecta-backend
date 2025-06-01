<?php

namespace Database\Factories;

use App\Models\NegotiationApprovalHistory;
use App\Models\Negotiation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NegotiationApprovalHistoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = NegotiationApprovalHistory::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'negotiation_id' => Negotiation::factory(),
            'action' => $this->faker->randomElement(['submit_for_approval', 'approve', 'reject']),
            'user_id' => User::factory(),
            'notes' => $this->faker->sentence(),
        ];
    }

    /**
     * Indicate that this is a submission for approval.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function submission()
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => 'submit_for_approval',
                'notes' => 'Submitted for approval',
            ];
        });
    }

    /**
     * Indicate that this is an approval.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function approval()
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => 'approve',
                'notes' => 'Approved by approver',
            ];
        });
    }

    /**
     * Indicate that this is a rejection.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function rejection()
    {
        return $this->state(function (array $attributes) {
            return [
                'action' => 'reject',
                'notes' => 'Rejected by approver',
            ];
        });
    }
} 