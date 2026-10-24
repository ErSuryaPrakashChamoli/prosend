<?php

namespace Database\Factories;

use App\Enums\EmailPurpose;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'category' => TemplateCategory::Newsletter,
            'purpose' => EmailPurpose::Marketing,
            'status' => TemplateStatus::Active,
            'subject' => fake()->sentence(5),
            'body' => '<p>'.fake()->paragraph().'</p>',
        ];
    }

    public function transactional(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => TemplateCategory::AccountNotification,
            'purpose' => EmailPurpose::Transactional,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TemplateStatus::Draft,
        ]);
    }
}
