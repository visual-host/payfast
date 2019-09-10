<?php

namespace VisualHost\Payfast;

use Illuminate\Support\ServiceProvider;

class PayfastServiceProvider extends ServiceProvider
{

    public function register()
    {
        $this->app->bind('VisualHost\Payfast\Contracts\PaymentProcessor', 'VisualHost\Payfast\Payfast');
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