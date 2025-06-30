<?php

namespace Omniglies\LaravelRag\Facades;

use Illuminate\Support\Facades\Facade;
use Omniglies\LaravelRag\Services\RagService;

class Rag extends Facade
{
    protected static function getFacadeAccessor()
    {
        return RagService::class;
    }
}