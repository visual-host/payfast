<?php

namespace IoDigital\Payfast;

use Illuminate\Support\ServiceProvider;

class PayfastServiceProvider extends ServiceProvider
{

    public function register()
    {
        $this->app->bind('IoDigital\Payfast\Contracts\PaymentProcessor', 'IoDigital\Payfast\Payfast');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/payfast.php' => config_path('payfast.php'),
        ]);

        $this->mergeConfigFrom(
            __DIR__ . '/config/payfast.php', 'payfast'
        );
    }
}