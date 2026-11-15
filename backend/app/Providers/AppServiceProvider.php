<?php

namespace App\Providers;

use App\Payments\PayPalClient;
use App\Payments\PayPalGateway;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PayPalGateway::class, function (Application $app): PayPalGateway {
            abort_unless($app['config']->get('payment.paypal.enabled') === true, 403, 'PayPal Sandbox is disabled.');
            $returnUrl = $app['config']->get('payment.paypal.return_url');
            $cancelUrl = $app['config']->get('payment.paypal.cancel_url');
            foreach ([$returnUrl, $cancelUrl] as $url) {
                if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
                    || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                    || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                    throw new LogicException('Configure valid PayPal Sandbox return and cancel URLs.');
                }
            }

            return new PayPalGateway($app->make(PayPalClient::class), $returnUrl, $cancelUrl);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
