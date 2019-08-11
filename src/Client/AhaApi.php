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

    protected function commandUrl(string $command): \SimpleXMLElement
    {
        $sid = $this->helper->getSid();
        try {
            $response = $this->helper->requestUrl($_ENV['APP_API_URL_AHA'].'?sid='.$sid.'&switchcmd='.$command);
        } catch (AccessDeniedHttpException $e) {
            // get new SID and retry request
            $this->helper->deleteSid();
            var_dump('retry with new SID');
            $response = $this->helper->requestUrl($_ENV['APP_API_URL_AHA'].'?sid='.$sid.'&switchcmd='.$command);
        }

//        var_dump($response);
        try {
            $xml = simplexml_load_string($response);
        } catch (\Exception $e) {
            Helper::deleteSid();

            throw $e;
        }
        if ($xml === false) {
            Helper::deleteSid();

            var_dump($response);

            throw new HttpException('Unknown response for '.$command);
        }

//        var_dump($xml);

        return $xml;
    }

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getDeviceListInfos()
    {
        $xml = $this->commandUrl('getdevicelistinfos');

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
