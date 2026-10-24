<?php

namespace Database\Factories;

use App\Enums\ConsentStatus;
use App\Enums\SegmentMatch;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'match' => SegmentMatch::All,
            'rules' => [
                ['type' => 'consent', 'data' => ['status' => ConsentStatus::Granted->value]],
            ],
        ];
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $rules
     */
    public function rules(array $rules, SegmentMatch $match = SegmentMatch::All): static
    {
        return $this->state(fn (array $attributes) => [
            'rules' => $rules,
            'match' => $match,
        ]);
    }
}
