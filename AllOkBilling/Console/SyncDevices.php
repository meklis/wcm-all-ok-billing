<?php


namespace WCM\AllOkBilling\Console;


use DI\Annotation\Inject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Models\User\User;
use WCAA\Modules\AbstractModuleCommand;
use WCAA\Storage\Devices\DeviceStorage;
use WCM\AllOkBilling\Controllers\Controller;

class SyncDevices extends AbstractModuleCommand
{
    /**
     * @Inject
     * @var User
     */
    protected $user;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('sync-devices')
            ->setDescription("Sync devices with all-ok-billing");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->controller
            ->initDB()
            ->setConsoleOutput($output)
            ->syncAccesses()
            ->syncDevices();
        return self::SUCCESS;
    }

}