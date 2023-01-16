<?php
namespace Csgt\Face;

use Illuminate\Routing\Router;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;

class FaceServiceProvider extends ServiceProvider
{

    protected $defer = false;

    public function boot(Router $router)
    {
        AliasLoader::getInstance()->alias('Face', 'Csgt\Face\Face');
    }

    public function register()
    {
        $this->app->singleton('face', function ($app) {
            return new Face;
        });
    }

    public function provides()
    {
        return ['face'];
    }
}
