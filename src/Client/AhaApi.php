<?php

namespace App\Client;

use App\Device;
use SensioLabs\Security\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
     * @return string|null
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function commandUrl(string $command, string $ain = null, string $param = null): ?string
    {
        try {
            $sid = $this->helper->getSid();
        } catch (\Exception $e) {
            dump($e);

            return null;
        }

        $url = $_ENV['APP_API_URL_AHA'].'?sid='.urlencode($sid).'&switchcmd='.urlencode($command);
        if (!empty($ain)) {
            $url .= '&ain='.urlencode($ain);
        }
        if (!empty($param)) {
            $url .= '&param='.urlencode($param);
        }

        try {
            $response = $this->helper->requestUrl($url);
        } catch (AccessDeniedHttpException $e) {
            // get new SID and retry request
            $this->helper->deleteSid();
            var_dump('retry with new SID');
            $response = $this->helper->requestUrl($url);
        }

//        var_dump($response);

        return $response;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getTemperature(string $ain)
    {
        $response = $this->commandUrl('gettemperature', $ain);
        $response = trim($response);
        dump($response);
        if (!empty($response)) {
            $response /= 10.0;
        }

        return $response;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getSwitchList()
    {
        $xml = $this->commandUrl('getswitchlist');

        if (!$xml || !$xml->device) {
            throw new InvalidResponseException('No devices available');
        }
        dump($xml);

        $devices = [];
        foreach ($xml->device as $device) {
            $devices[] = Device::xmlFactory($device);
        }

        return $devices;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getDeviceListInfos()
    {
        $response = $this->commandUrl('getdevicelistinfos');

        try {
            $xml = simplexml_load_string($response);
        } catch (\Exception $e) {
            $this->helper->deleteSid();

            dump($response);
            throw $e;
        }
        if ($xml === false) {
            $this->helper->deleteSid();

            var_dump($response);

            throw new HttpException('Unknown response for '.$command);
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
