<?php

namespace Database\Seeders;

use App\Services\Templates\SampleTemplates;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Add the sample templates that do not exist yet.
     */
    public function run(SampleTemplates $samples): void
    {
        $samples->install();
    }
}
