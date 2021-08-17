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

class FlushUsers extends AbstractModuleCommand
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
        $this->setName('flush-users')
            ->setDescription("Flush all synced users from all-ok-billing");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->controller
            ->initDB()
            ->setConsoleOutput($output)
            ->flushUsers()
            ->flushGroups();
        return self::SUCCESS;
    }

}