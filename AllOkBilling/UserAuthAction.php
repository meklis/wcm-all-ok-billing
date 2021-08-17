<?php


namespace WCM\AllOkBilling;


use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DI\Annotation\Inject;
use Exception;
use WCAA\Api\Actions\Action;
use WCAA\App;
use WCAA\Controllers\Auth;
use WCAA\Models\SystemAction;
use WCAA\Models\User\UserAuthKey;
use WCAA\Storage\SystemActionsStorage;
use WCAA\Storage\UserAuthKeyStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;
use WCM\AllOkBilling\Controllers\Controller;

class UserAuthAction extends Action
{

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $keyStorage;

    /**
     * @return Response
     * @throws HttpBadRequestException
     * @throws HttpUnauthorizedException
     */

    protected function action(): Response
    {
        $data = $this->getFormData();
        if (!isset($data['login']) || !isset($data['password'])) {
            throw new HttpBadRequestException($this->request, "Login and password are required fields");
        }
        $userData = null;
        try {
            $userData = $this->controller->billingAuth($data['login'], $data['password']);
        } catch (Exception $e) {
            throw new HttpUnauthorizedException($this->request, $e->getMessage());
        }
        $user = $userData['user'];
        $key = (new UserAuthKey())
            ->setUser($user)
            ->setExpiredAt(
                date("Y-m-d H:i:s", App::getInstance()->conf('api.auth.key_expired_sec') + time())
            )->setStatus(UserAuthKey::STATUS_ACTIVE)
            ->setUserAgent($this->request->getHeaderLine('User-Agent'))
            ->setRemoteAddr($this->request->getServerParams()['REMOTE_ADDR']);

        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_NONE);
        $dd = new DeviceDetector($this->request->getHeaderLine('User-Agent'));
        $dd->parse();

        if ($dd->isBot()) {
            $key->setDeviceInfo([
                'bot' => $dd->getBot(),
                'client' => null,
                'os_info' => null,
                'device' => null,
                'brand' => null,
                'model' => null,
            ]);
        } else {
            $key->setDeviceInfo([
                'bot' => null,
                'client' => $dd->getClient(),
                'os_info' => $dd->getOs(),
                'device' => $dd->getDeviceName(),
                'brand' => $dd->getBrandName(),
                'model' => $dd->getModel(),
            ]);
        }
        $this->systemActionsStorage->add(
            (new SystemAction())
                ->setUser($user)
                ->setStatus(SystemAction::STATUS_SUCCESS)
                ->setMessage("User success logined from IP {$key->getRemoteAddr()} over billing system")
                ->setAction('user:logged_in')
                ->setMeta(['dev_info' => $key->getDeviceInfo(), 'remote_addr' => $key->getRemoteAddr(), 'key_expired_at' => $key->getExpiredAt()])
        );
        $this->keyStorage->add($key);

        return $this->respondWithData($key->getAsArray());
    }

}