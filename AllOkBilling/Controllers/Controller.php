<?php


namespace WCM\AllOkBilling\Controllers;


use Curl\Curl;
use DI\Annotation\Inject;
use GuzzleHttp\Psr7\Request;
use Monolog\Logger;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\RPC\RpcMethods;
use WCAA\Controllers\SwitcherCore;
use WCAA\Controllers\SwitcherCoreRPC;
use WCAA\Exceptions\WildCoreException;
use WCAA\Infrastructure\ModuleInjector;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Models\User\User;
use WCAA\Models\User\UserGroup;
use WCAA\Modules\AbstractModuleController;
use WCAA\RPC\Client;
use WCAA\RPC\Exceptions\ServerErrorException;
use WCAA\RPC\RpcObject;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserGroupStorage;
use WCAA\Storage\UserStorage;

/**
 * Class Controller
 * @package WCM\AllOkBilling
 */
class Controller extends AbstractModuleController
{

    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $accessStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var UserGroupStorage
     */
    protected $userGroupStorage;

    /**
     * @var \PDO
     */
    protected $pdo;


    /**
     * @Inject
     * @var App
     */
    protected $app;


    /**
     * @var OutputInterface
     */
    protected $output;

    public function __construct(ModuleInjector $moduleInjector, Logger $logger)
    {
        parent::__construct($moduleInjector, $logger);
    }
    public function initDB() {
        $conf = $this->moduleConfig['database'];
        if(!$conf['dsn'] || !$conf['username']) {
            throw new \InvalidArgumentException("DSN and username is required parameters");
        }
        $this->pdo = new \PDO($conf['dsn'], $conf['username'], $conf['password'],[
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION, //turn on errors in the form of exceptions
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, //make the default fetch be an associative array
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        ]);
        return $this;
    }

    public function billingAuth($username, $password) {
        $client = new \GuzzleHttp\Client([
            'timeout' => 10,
            'base_uri' => $this->moduleConfig['api_url']
        ]);
        $response =$client->post('/users/auth', [
           'json' => [
               'username' =>  $username,
               'password' => $password,
           ]
        ]);
        if($response->getStatusCode() !== 200) {
            throw new \Exception("Server returned error ({$response->getStatusCode()}): {$response->getReasonPhrase()}");
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if(!isset($data['data'])) {
            throw new WildCoreException("Problem check auth on billing server");
        }
        $user = $data['data'];
        $localUser = $this->userStorage->getUserByLogin($user['info']['login']);
        if($localUser === null) {
            throw new ServerErrorException("User with login {$username} not found in wildcore system, try later");
        }
        return  [
            'billing_response' => $user,
            'user' =>  $localUser,
            'token' =>  $user['auth_token']
        ];
    }

    public function setConsoleOutput(OutputInterface $output) {
        $this->output = $output;
        return $this;
    }

    public function flushUsers() {
        foreach ($this->userStorage->fetchAll() as $user) {
            if(isset($user->getSettings()['sync']['source']) && $user->getSettings()['sync']['source'] === 'all-ok-billing') {
                $this->userStorage->delete($user);
            }
        }
        return $this;
    }

    public function flushGroups() {
        foreach ($this->userGroupStorage->fetchAll() as $l) {
            if(isset($l->getParams()['sync']['source']) && $l->getParams()['sync']['source'] === 'all-ok-billing') {
                $this->userGroupStorage->delete($l);
            }
        }
        return $this;
    }

    public function flushDevices() {
        foreach ($this->deviceStorage->fetchAll() as $l) {
            if(isset($l->getParams()['sync']['source']) && $l->getParams()['sync']['source'] === 'all-ok-billing') {
                $this->deviceStorage->delete($l);
            }
        }
        return $this;
    }
    public function flushDeviceAccesses() {
        foreach ($this->accessStorage->fetchAll() as $l) {
            if(isset($l->getParams()['sync']['source']) && $l->getParams()['sync']['source'] === 'all-ok-billing') {
                $this->accessStorage->delete($l);
            }
        }
        return $this;
    }

    public function syncGroups()
    {
        $groups = [];
        foreach ($this->userGroupStorage->fetchAll() as $group) {
            if (isset($group->getParams()['sync']['id'])) {
                $groups[$group->getParams()['sync']['id']] = $group;
            }
        }
        $data = $this->pdo->query("SELECT `id`, `position` as `name` FROM emplo_positions WHERE `show` = 1")->fetchAll(\PDO::FETCH_ASSOC);
        $existedGroups = [];
        foreach ($data as $d) {
            $existedGroups[$d['id']] = $d;

            $permissions = [];
            if($d['name'] == 'Администратор') {
                $rules = $this->app->conf('api.auth.rules');
                foreach ($rules as $rule) {
                    $permissions[] = $rule['key'];
                }
            }

            //Проверка, что группа существует
            if (isset($groups[$d['id']])) {
                $group = $groups[$d['id']];
                //Группа существует, проверка на изменения
                if ($group->getName() === $d['name']) {
                    continue;
                }
                $group->setName("{$d['name']}")
                    ->setDescription("Sync from all-ok-billing")
                    ->setDisplay(true)
                    ->setPermissions($permissions)
                    ->setParams(['sync' => [
                        'id' => $d['id'],
                        'source' => 'all-ok-billing',
                        'last_update' => time(),
                    ]]);
                $this->userGroupStorage->update($group);
            } else {
                //Доступ не существует, нужно добавить
                $group = (new UserGroup())
                    ->setName("{$d['name']}")
                    ->setDescription("Sync from all-ok-billing")
                    ->setPermissions($permissions)
                    ->setDisplay(true)
                    ->setParams(['sync' => [
                        'id' => $d['id'],
                        'source' => 'all-ok-billing',
                        'last_update' => time(),
                    ]]);
                $this->userGroupStorage->add($group);
            }
        }
        //Удаление несуществующих групп
        foreach ($groups as $group) {
            if (!isset($group->getParams()['sync']['source']) || $group->getParams()['sync']['source'] !== 'all-ok-billing') continue;
            if (!isset($existedGroups[$group->getParams()['sync']['id']])) {
                $group->setDisplay(false);
                $this->userGroupStorage->update($group);
            }
        }
        return $this;
    }
    public function syncUsers() {
        $data = $this->pdo->query("SELECT 
                id,
                name, 
                login,
                position position_id
                FROM employees
                WHERE `display` = 1 ")->fetchAll(\PDO::FETCH_ASSOC);
        $existedUsers = [];
        foreach ($data as $d) {
            $existedUsers[$d['login']] = $d;
            $user = $this->userStorage->getUserByLogin($d['login']);
            if ($user !== null) {
                //Группа существует, проверка на изменения
                if ($user->getName() === $d['name'] &&
                    isset($user->getGroup()->getParams()['sync']['id']) &&
                    $user->getGroup()->getId() == $this->_getUserGroupBySourceId($d['position_id'])->getId() &&
                    $user->getLogin() === $d['login']
                   ) {
                    continue;
                }
                $settings = $user->getSettings();
                $settings['sync'] =  [
                    'id' => $d['id'],
                    'source' => 'all-ok-billing',
                    'last_update' => time(),
                ];
                $user->setName("{$d['name']}")
                    ->setLogin($d['login'])
                    ->setStatus(User::STATUS_ENABLED)
                    ->setGroup($this->_getUserGroupBySourceId($d['position_id']))
                    ->setSettings($settings);
                $this->userStorage->update($user);
            } else {
                //Доступ не существует, нужно добавить
                $user = (new User())
                    ->setLogin($d['login'])
                    ->setStatus(User::STATUS_ENABLED)
                    ->setGroup($this->_getUserGroupBySourceId($d['position_id']))
                    ->setName("{$d['name']}")
                    ->setSettings(['sync' => [
                        'id' => $d['id'],
                        'source' => 'all-ok-billing',
                        'last_update' => time(),
                    ]]);
                $this->userStorage->add($user);
            }
        }
        //Отключение удаленных пользователей
        foreach ($this->userStorage->fetchAll() as $user) {
            if (!isset($user->getSettings()['sync']['source']) || $user->getSettings()['sync']['source'] !== 'all-ok-billing') continue;
            if (!isset($existedUsers[$user->getLogin()])) {
                $user->setStatus(User::STATUS_DISABLED);
                $this->userStorage->update($user);
            }
        }
        return $this;
    }

    public function syncAccesses()
    {
        $accesses = [];
        foreach ($this->accessStorage->fetchAll() as $access) {
            if (isset($access->getParams()['sync']['id'])) {
                $accesses[$access->getParams()['sync']['id']] = $access;
            }
        }
        $data = $this->pdo->query("SELECT id, login, password, community from equipment_access")->fetchAll(\PDO::FETCH_ASSOC);
        $existedAccesses = [];
        foreach ($data as $d) {
            $existedAccesses[$d['id']] = $d;
            //Проверка, что доступ существует
            if (isset($accesses[$d['id']])) {
                $access = $accesses[$d['id']];
                //Доступ существует, проверка на изменения
                if ($access->getLogin() === $d['login'] &&
                    $access->getPassword() === $d['password'] &&
                    $access->getCommunity() === $d['community']) {
                    continue;
                }
                $access->setName("{$d['login']} (AllOkBilling sync)")
                    ->setLogin($d['login'])
                    ->setPassword($d['password'])
                    ->setCommunity($d['community'])
                    ->setParams(['sync' => [
                        'id' => $d['id'],
                        'source' => 'all-ok-billing',
                        'last_update' => time(),
                    ]]);
                $this->accessStorage->update($access);
            } else {
                //Доступ не существует, нужно добавить
                $access = (new DeviceAccess())
                    ->setName("{$d['login']} (AllOkBilling sync)")
                    ->setLogin($d['login'])
                    ->setPassword($d['password'])
                    ->setCommunity($d['community'])
                    ->setParams(['sync' => [
                        'id' => $d['id'],
                        'source' => 'all-ok-billing',
                        'last_update' => time(),
                    ]]);
                $this->accessStorage->add($access);
            }
        }
        //Удаление несуществующих доступов
        foreach ($accesses as $access) {
            if (!isset($access->getParams()['sync']['source']) || $access->getParams()['sync']['source'] !== 'all-ok-billing') continue;
            if (!isset($existedAccesses[$access->getParams()['sync']['id']])) {
                $this->accessStorage->delete($access);
            }
        }
        return $this;
    }

    public function syncDevices()
    {
        $data = $this->pdo->query("
        SELECT e.id, 
            e.ip, 
            e.mac, 
            e.access, 
            CONCAT(h.full_addr, ', под. ', e.entrance) addr,
            m.`name` model,
            e.description description 
            FROM `equipment` e 
            JOIN addr h on h.id = e.house
            JOIN equipment_models m on m.id = e.model
        ")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($data as $d) {
            try {
                $dev = $this->deviceStorage->getByIp($d['ip']);
            } catch (\Exception $e) {
                $dev = null;
            }
            if ($dev) {
                //Проверка, актуальности устройства
                if (!($d['ip'] === $dev->getIp() &&
                    $d['addr'] === $dev->getName() &&
                    $d['model'] === $dev->getModel()->getName() &&
                    $d['mac'] === $dev->getMac() &&
                    isset($dev->getAccess()->getParams()['sync']['id']) &&
                    $d['access'] === $dev->getAccess()->getParams()['sync']['id'] &&
                    $d['description'] === $dev->getDescription()
                )) {
                    try {
                        $params = $dev->getParams();
                        $params['sync'] = [
                            'id' => $d['id'],
                            'source' => 'all-ok-billing',
                            'last_update' => time(),
                        ];
                        $dev->setName($d['addr'])
                            ->setParams($params)
                            ->setDescription($d['description'])
                            ->setIp($d['ip'])
                            ->setMac($d['mac'])
                            ->setAccess($this->_getAccessBySourceId($d['access']))
                            ->setModel($this->modelStorage->getByName($d['model']));
                        $this->deviceStorage->update($dev);
                    } catch (\Exception $e) {
                        if($this->output) {
                            $this->output->writeln("Error update device: " . $e->getMessage());
                        }
                    }
                }
            } else {
                try {
                    $dev = new Device();
                    $params['sync'] = [
                        'id' => $d['id'],
                        'source' => 'all-ok-billing',
                        'last_update' => time(),
                    ];
                    $model = $this->modelStorage->getByName($d['model']);
                    if(!$model) {
                        throw new \Exception("Model with name {$d['model']} not found in wildcore-agent");
                    }
                    $dev->setName($d['addr'])
                        ->setParams($params)
                        ->setDescription($d['description'])
                        ->setIp($d['ip'])
                        ->setMac($d['mac'])
                        ->setAccess($this->_getAccessBySourceId($d['access']))
                        ->setModel($model);
                    $this->deviceStorage->add($dev);
                } catch (\Exception $e) {
                    if($this->output) {
                        $this->output->writeln("Error add device with ip {$d['ip']}: " . $e->getMessage());
                    }
                }
            }
        }
        return $this;
    }

    protected function _getAccessBySourceId($id)
    {
        $accesses = $this->accessStorage->fetchAll();
        foreach ($accesses as $access) {
            if (isset($access->getParams()['sync']['id']) && $access->getParams()['sync']['id'] == $id) {
                return $access;
            }
        }
        throw new \Exception("Access with id $id not found in params.sync");
    }
    protected function _getUserGroupBySourceId($id)
    {
        $groups = $this->userGroupStorage->fetchAll();
        foreach ($groups as $group) {
            if (isset($group->getParams()['sync']['id']) && $group->getParams()['sync']['id'] == $id) {
                return $group;
            }
        }
        throw new \Exception("Group with id $id not found in params.sync");
    }

}