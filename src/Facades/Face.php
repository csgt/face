<?php
namespace Csgt\Face\Facades;

use Illuminate\Support\Facades\Facade;

class Face extends Facade
{
    protected static function getFacadeAccessor()
    {return 'face';}
}
