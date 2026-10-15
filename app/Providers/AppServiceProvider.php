<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword; // <--- Ye import karein

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset link ko React frontend par redirect karne ke liye
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return "https://app.theglasspeople.com/reset-password?token={$token}&email=" . urlencode($user->email);
        });
    }
}