<?php

namespace App\Client;

use App\Device;
use SensioLabs\Security\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class AhaApi
 *
 * @see     https://avm.de/fileadmin/user_upload/Global/Service/Schnittstellen/AHA-HTTP-Interface.pdf
 * @package App\Client
 */
class AhaApi
{
    /**
     * @var string
     */
    protected $sid;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * AhaApi constructor.
     *
     * @param  Helper  $helper
     */
    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * @param  string       $command
     * @param  string|null  $ain
     * @param  string|null  $param
     * @return ResponseInterface|null
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function commandUrl(string $command, string $ain = null, string $param = null): ?ResponseInterface
    {
        try {
            $sid = $this->helper->getSid();
        } catch (\Exception $e) {
            dump($e);

            return null;
        }

        $query = [
            'sid'       => $sid,
            'switchcmd' => $command,
        ];
        if (!empty($ain)) {
            $query['ain'] = $ain;
        }
        if (!empty($param)) {
            $query['param'] = $param;
        }

        try {
            $response = $this->helper->requestUrl($_ENV['APP_API_URL_AHA'], ['query' => $query]);
        } catch (AccessDeniedHttpException $e) {
            // get new SID and retry request
            $this->helper->deleteSid();
            var_dump('retry with new SID');
            $response = $this->helper->requestUrl($_ENV['APP_API_URL_AHA'], ['query' => $query]);
        }

//        var_dump($response);

        return $response;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getSwitchList()
    {
        $response = $this->commandUrl('getswitchlist');

        if($response === null) {
            return null;
        }

        $content  = trim($response->getContent());
        dump($content);
        if (!empty($content)) {
            $content = explode(',', $content);
        } else {
            $content = [];
        }

        return $content;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getTemperature(string $ain)
    {
        $response = $this->commandUrl('gettemperature', $ain);

        if($response === null) {
            return null;
        }

        $content  = trim($response->getContent());
        if (!empty($content)) {
            $content /= 10.0;
        }

        return $content;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getDeviceListInfos()
    {
        $response = $this->commandUrl('getdevicelistinfos');

        try {
            $xml = simplexml_load_string($response->getContent());
        } catch (\Exception $e) {
            $this->helper->deleteSid();

            dump($response);
            throw $e;
        }
        if ($xml === false) {
            $this->helper->deleteSid();

            var_dump($response);

            throw new HttpException('Unknown response for getdevicelistinfos');
        }

        if (!$xml || !$xml->device) {
            throw new InvalidResponseException('No devices available');
        }

        $devices = [];
        foreach ($xml->device as $device) {
            $devices[] = Device::xmlFactory($device);
        }

        return $devices;
    }
}
