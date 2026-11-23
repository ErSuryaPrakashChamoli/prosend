<?php

namespace Database\Factories;

use App\Enums\AutomationActionType;
use App\Enums\OutreachEvent;
use App\Models\AutomationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    /**
     * Tag prospects who reply.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Tag repliers',
            'trigger' => OutreachEvent::EmailReplied,
            'conditions' => [],
            'actions' => [['type' => AutomationActionType::AddTag->value, 'data' => ['tag' => 'replied']]],
        ];
    }
}
