<?php

namespace LaravelSmartOCR\Facades;

use Illuminate\Support\Facades\Facade;

class SmartOCR extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'smart-ocr';
    }
}