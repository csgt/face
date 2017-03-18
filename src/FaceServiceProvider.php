<?php namespace Csgt\Face;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;

class FaceServiceProvider extends ServiceProvider {

	protected $defer = false;

	public function boot(Router $router) {
		$this->mergeConfigFrom(__DIR__ . '/config/csgtface.php', 'csgtface');

		AliasLoader::getInstance()->alias('Face','Csgt\Face\Face');

		$this->publishes([
      __DIR__.'/config/csgtface.php' => config_path('csgtface.php'),
    ], 'config');
	}

	public function register() {
		$this->app->singleton('face', function($app) {
    	return new Face;
  	});
	}

	public function provides() {
		return ['face'];
	}
}