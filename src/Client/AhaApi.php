<?php

namespace App\Client;

use App\Device;
use SensioLabs\Security\Exception\HttpException;

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
     * AhaApi constructor.
     *
     * @param  string  $sid
     */
    public function __construct(string $sid)
    {
        $this->sid = $sid;
    }

    protected function commandUrl(string $command): \SimpleXMLElement
    {
        $response = Helper::requestUrl($_ENV['APP_API_URL_AHA'].'?sid='.$this->sid.'&switchcmd='.$command);
//        var_dump($response);
        try {
            $xml = simplexml_load_string($response);
        } catch (\Exception $e) {
            Helper::deleteSid();

            throw $e;
        }
        if ($xml === false) {
            Helper::deleteSid();

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
