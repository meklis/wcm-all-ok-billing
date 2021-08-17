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

class AuthUser extends AbstractModuleCommand
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
        //For all modules console commands added prefix - modules name
        $this->setName('user-auth')
            ->addArgument('username', InputArgument::REQUIRED, 'Username on all-ok-billing')
            ->addArgument('password', InputArgument::REQUIRED, "Password of username")
            ->setDescription("Check working auth over all-ok-billing");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $response = $this->controller
            ->initDB()
            ->setConsoleOutput($output)
            ->billingAuth($input->getArgument('username'), $input->getArgument('password'));
        $response['user'] = $response['user']->getAsArray();
        $output->writeln($this->toJson($response));
        return self::SUCCESS;
    }

}