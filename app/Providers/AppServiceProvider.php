<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for(
            'customer-login',
            function (Request $request) {
                $phone = Str::lower(
                    trim(
                        (string) $request->input(
                            'phone'
                        )
                    )
                );

                return Limit::perMinute(5)
                    ->by(
                        $phone
                        .'|'
                        .$request->ip()
                    );
            }
        );
    }
}