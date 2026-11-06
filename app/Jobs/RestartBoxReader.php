<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RestartBoxReader implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        exec("pkill -9 -f 'boxes:reader LEC-L1'");
    }
}
