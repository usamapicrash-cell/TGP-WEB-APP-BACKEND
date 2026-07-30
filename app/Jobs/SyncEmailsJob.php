<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Timeout Limit
    public $timeout = 60;

    public function middleware()
    {
        // Ek time par sirf 1 job chalayega, duplicate overlapping nahi hone dega
        return [new WithoutOverlapping('sync-emails-key')];
    }

    public function handle()
    {
        Artisan::call('sync:emails');
    }
}