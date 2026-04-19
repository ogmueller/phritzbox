<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Api;

use App\Client\AhaApi;
use App\Device;
use App\Entity\SmartDevice;
use App\Service\ProductImageMap;
use App\Service\SmartDeviceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/devices')]
class DeviceController extends AbstractController
{
    public function __construct(
        private readonly AhaApi $ahaApi,
        private readonly SmartDeviceService $smartDeviceService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $devices = $this->ahaApi->getDeviceListInfos();
            $this->smartDeviceService->syncDevices($devices);
            $data = array_map(fn (Device $d) => $this->serializeDevice($d), $devices);
        } catch (\Throwable) {
            // Fritz!Box unreachable — fall back to cached device metadata
            $cached = $this->smartDeviceService->getAllCached();
            $data = array_map(fn (SmartDevice $sd) => $this->serializeCachedDevice($sd), $cached);
        }

        return $this->json($data);
    }

    #[Route('/{ain}/xml', methods: ['GET'], priority: 10)]
    public function xml(string $ain): JsonResponse
    {
        try {
            $xml = $this->ahaApi->getDeviceXml($ain);
        } catch (\Throwable) {
            return $this->json(['error' => 'Could not fetch device XML'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['ain' => $ain, 'xml' => $xml]);
    }

    #[Route('/{ain}', methods: ['GET'])]
    public function show(string $ain): JsonResponse
    {
        try {
            $devices = $this->ahaApi->getDeviceListInfos();
            $this->smartDeviceService->syncDevices($devices);

            foreach ($devices as $device) {
                if ($device->getIdentifier() === $ain) {
                    return $this->json($this->serializeDevice($device));
                }
            }
        } catch (\Throwable) {
            // Fritz!Box unreachable — try cached data
            $cached = $this->smartDeviceService->findByAin($ain);
            if ($cached !== null) {
                return $this->json($this->serializeCachedDevice($cached));
            }
        }

        return $this->json(['error' => 'Device not found'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/{ain}/on', methods: ['POST'], priority: 10)]
    public function turnOn(string $ain): JsonResponse
    {
        $state = $this->ahaApi->setSwitchOn($ain);

        return $this->json(['ain' => $ain, 'state' => $state === '1' ? 'on' : 'error']);
    }

    #[Route('/{ain}/off', methods: ['POST'], priority: 10)]
    public function turnOff(string $ain): JsonResponse
    {
        $state = $this->ahaApi->setSwitchOff($ain);

        return $this->json(['ain' => $ain, 'state' => $state === '0' ? 'off' : 'error']);
    }

    #[Route('/{ain}/setpoint', methods: ['PUT'], priority: 10)]
    public function setSetpoint(string $ain, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $temperature = $body['temperature'] ?? null;

        if ($temperature === null) {
            return $this->json(['error' => 'temperature is required'], Response::HTTP_BAD_REQUEST);
        }

        $raw = $this->ahaApi->setSrcSetpoint($ain, (float) $temperature);

        return $this->json(['ain' => $ain, 'rawValue' => $raw, 'celsius' => (int) $raw / 2]);
    }

    private function serializeDevice(Device $device): array
    {
        $data = [
            'ain' => $device->getIdentifier(),
            'name' => $device->getName(),
            'manufacturer' => $device->getManufacturer(),
            'productName' => $device->getProductName(),
            'firmwareVersion' => $device->getFirmwareVersion(),
            'present' => $device->isPresent(),
            'functionBitMask' => $device->getFunctionBitMask(),
            'productImage' => ProductImageMap::getImageUrl($device->getProductName()),
            'source' => 'live',
            'features' => [
                'outlet' => $device->hasOutlet(),
                'thermostat' => $device->hasThermostat(),
                'powerMeter' => $device->hasPowerMeter(),
                'temperatureSensor' => $device->hasTemperature(),
            ],
            'temperature' => null,
            'outlet' => null,
            'powerMeter' => null,
            'thermostat' => null,
        ];

        if ($device->hasTemperature()) {
            /** @var Device\Feature\Temperature $f */
            $f = $device->feature(Device::FEATURE_TEMPERATURE_SENSOR);
            $data['temperature'] = [
                'celsius' => $f->getTemperatureCelsius(),
                'offset' => $f->getTemperatureOffset(),
            ];
        }

        if ($device->hasOutlet()) {
            /** @var Device\Feature\Outlet $f */
            $f = $device->feature(Device::FEATURE_OUTLET);
            $data['outlet'] = [
                'state' => $f->isSwitchState() ? 'on' : 'off',
                'mode' => $f->getSwitchMode(),
                'lock' => $f->isSwitchLock(),
                'deviceLock' => $f->isSwitchDeviceLock(),
            ];
        }

        if ($device->hasPowerMeter()) {
            /** @var Device\Feature\PowerMeter $f */
            $f = $device->feature(Device::FEATURE_POWER_METER);
            $data['powerMeter'] = [
                'voltage' => $f->getPowerMeterVoltage(),
                'power' => $f->getPowerMeterPower(),
                'energy' => $f->getPowerMeterEnergy(),
            ];
        }

        return $data;
    }

    private function serializeCachedDevice(SmartDevice $sd): array
    {
        $bitMask = $sd->getFunctionBitMask();

        return [
            'ain' => $sd->getAin(),
            'name' => $sd->getName(),
            'manufacturer' => $sd->getManufacturer(),
            'productName' => $sd->getProductName(),
            'firmwareVersion' => $sd->getFirmwareVersion(),
            'present' => null,
            'functionBitMask' => $bitMask,
            'productImage' => ProductImageMap::getImageUrl($sd->getProductName()),
            'source' => 'cached',
            'features' => [
                'outlet' => ($bitMask & Device::FUNCTION_BIT_OUTLET) > 0,
                'thermostat' => ($bitMask & Device::FUNCTION_BIT_THERMOSTAT) > 0,
                'powerMeter' => ($bitMask & Device::FUNCTION_BIT_POWER_METER) > 0,
                'temperatureSensor' => ($bitMask & Device::FUNCTION_BIT_TEMPERATURE_SENSOR) > 0,
            ],
            'temperature' => null,
            'outlet' => null,
            'powerMeter' => null,
            'thermostat' => null,
        ];
    }
}
