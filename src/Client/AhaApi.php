<?php

namespace App\Client;

use App\Device;
use SensioLabs\Security\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
     * @param  string       $command  AHA command
     * @param  string|null  $ain      Actor identification number
     * @param  string|null  $param    Set value
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
            // cached/given SID seems to be invalid. Delete cache and try to get new SID
            var_dump('Access denied. Request new SID...');
            $query['sid'] = $this->helper->getSid();
            $response     = $this->helper->requestUrl($_ENV['APP_API_URL_AHA'], ['query' => $query]);
        }

//        var_dump($response);

        return $response;
    }

    protected function basicCommand(string $command, string $ain = null, string $param = null): ?string
    {
        $response = $this->commandUrl($command, $ain, $param);

        if ($response === null) {
            return null;
        }

        $content = trim($response->getContent());

        return $content;
    }

    /**
     * Delivers AIN/MAC of all SmartHome outlets
     */
    public function getSwitchList(): ?array
    {
        $response = $this->commandUrl('getswitchlist');

        if ($response === null) {
            return null;
        }

        $content = trim($response->getContent());
        if (!empty($content)) {
            $content = explode(',', $content);
        } else {
            $content = [];
        }

        return $content;
    }

    /**
     * Switch on a SmartHome outlet
     */
    public function setSwitchOn(string $ain)
    {
        return $this->basicCommand('setswitchon', $ain);
    }

    /**
     * Switch off a SmartHome outlet
     */
    public function setSwitchOff(string $ain)
    {
        return $this->basicCommand('setswitchoff', $ain);
    }

    /**
     * Toggle power state off a SmartHome outlet
     */
    public function setSwitchToggle(string $ain)
    {
        return $this->basicCommand('setswitchtoggle', $ain);
    }

    /**
     * Determine availability of a SmartHome outlet
     *
     * If a device gets disconnected it state might need
     * a couple of minutes to be recognized.
     */
    public function getSwitchPresent(string $ain)
    {
        return $this->basicCommand('getswitchpresent', $ain);
    }

    /**
     * Get current power consumption off a SmartHome outlet
     *
     * Value reading is delayed by a few seconds.
     */
    public function getSwitchPower(string $ain)
    {
        return $this->basicCommand('getswitchpower', $ain);
    }

    /**
     * Get energy quantity delivered over a SmartHome outlet
     *
     * Value reflects consumption since first use of outlet
     * or last reset of energy statistics.
     */
    public function getSwitchEnergy(string $ain)
    {
        return $this->basicCommand('getswitchenergy', $ain);
    }

    /**
     * Get name of a SmartHome outlet
     */
    public function getSwitchName(string $ain)
    {
        return $this->basicCommand('getswitchname', $ain);
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

    /**
     * Deliver basic information about all SmartHome devices
     */
    public function getTemperature(string $ain)
    {
        return $this->basicCommand('gettemperature', $ain);
    }

    /**
     * Get setpoint temperature of a SmartHome smart radiator control
     */
    public function getSrcSetpoint(string $ain)
    {
        return $this->basicCommand('gethkrtsoll', $ain);
    }

    /**
     * Get setpoint for comfort temperature of a SmartHome smart radiator control
     */
    public function getSrcComfort(string $ain)
    {
        return $this->basicCommand('gethkrkomfort', $ain);
    }

    /**
     * Get setpoint for saving temperature of a SmartHome smart radiator control
     */
    public function getSrcSaving(string $ain)
    {
        return $this->basicCommand('gethkrabsenk', $ain);
    }

    /**
     * Set setpoint temperature of a SmartHome smart radiator control
     */
    public function setSrcSetpoint(string $ain, $setpoint)
    {
        $setpoint = max(8, min(28, (float)$setpoint));

        $setpoint = round($setpoint * 2);

        return $this->basicCommand('sethkrtsoll', $ain, $setpoint);
    }

    /**
     * Turn on a SmartHome smart radiator control
     */
    public function setSrcOn(string $ain)
    {
        return $this->basicCommand('sethkrtsoll', $ain, 254);
    }

    /**
     * Turn off a SmartHome smart radiator control
     */
    public function setSrcOff(string $ain)
    {
        return $this->basicCommand('sethkrtsoll', $ain, 253);
    }

    /**
     * Deliver basic information of a SmartHome device
     */
    public function getBasicDeviceStats(string $ain)
    {
        $response = $this->commandUrl('getbasicdevicestats', $ain);
        dump($response->getContent());

        try {
            $xml = simplexml_load_string($response->getContent());
            dump($xml);
        } catch (\Exception $e) {
            $this->helper->deleteSid();

            dump($response);
            throw $e;
        }
        if ($xml === false) {
            $this->helper->deleteSid();

            var_dump($response);

            throw new HttpException('Unknown response for getbasicdevicestats');
        }

        if (!$xml || $xml->getName() !== 'devicestats') {
            throw new InvalidResponseException('Device not available');
        }

        $statistics = [];
        foreach ($xml->children() as $category) {
            $name = $category->getName();
            switch ($name) {
                case 'temperature':
                    $convert = 10;
                    break;
            }
            if ($category->count()) {
                foreach ($category->children() as $stats) {
                    $attr = $stats->attributes();
                    // number of elements
                    $arr['count'] = (int)$attr['count'];
                    // time resolution in seconds
                    $arr['grid']         = (int)$attr['grid'];
                    $arr['values']       = array_map(
                        function ($value) use ($convert) {
                            return $value / $convert;
                        },
                        explode(',', (string)$stats)
                    );
                    $statistics[$name][] = $arr;
                }
            }
        }

        return $statistics;
    }
}
