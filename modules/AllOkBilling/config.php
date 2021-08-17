<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of modules. key must be uniq for modules
    'name' => 'all_ok_billing',
    // List of rules
    'rules' => [],

    //For work with another modules in system you can create class with public method.
    //For access to object of modules controller you must use ModuleInjector::getController(<name of modules>)
    'controller' => \WCM\AllOkBilling\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],


    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractModuleCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCM\AllOkBilling\Console\SyncDevices::class,
        \WCM\AllOkBilling\Console\SyncUsers::class,
        \WCM\AllOkBilling\Console\FlushUsers::class,
        \WCM\AllOkBilling\Console\AuthUser::class,
    ],

    //List of RPC commands
    //For create rpc method you must create class with method __invoke() with list of arguments
    //In __construct() you can set all dependencies if you need.
    'rpc' => [
    ],

    'database' => [
        'dsn' => _env('ALL_OK_BILLING_DATABASE_URL'),
        'username' => _env('ALL_OK_BILLING_DATABASE_USER'),
        'password' => _env('ALL_OK_BILLING_DATABASE_PASSWD'),
    ],
    'api_url' => _env('ALL_OK_BILLING_API_URL'),
];
