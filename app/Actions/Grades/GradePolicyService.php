<?php

namespace App\Actions\Grades;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

class GradePolicyService
{
    public function incDeadline(): Carbon
    {
        return now()->addDays(Config::integer('grades.servitech_v1.inc_deadline_days'));
    }
}
