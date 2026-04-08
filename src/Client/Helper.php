<?php

namespace App\Client;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Helper
{
    const session_cache_name = 'sid';

    /**
     * @var string
     */
    protected $sid;

    /**
     * Make HTTP request
     *
     * @param  string  $url
     * @param  array   $options
     * @return bool|string
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function requestUrl(string $url, array $options = []): ?ResponseInterface
    {
        // NativeHttpClient (PHP streams) instead of CurlHttpClient because curl/OpenSSL 3.x
        // treats the Fritz!Box's missing TLS close_notify as a fatal error, while PHP streams
        // handle it as a normal EOF.
        $httpClient = new NativeHttpClient(
            [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'phritzbox',
                ],
            ]
        );

        try {
            $response = $httpClient->request('GET', $url, $options);
            // do a getContent to actually wait for the request to finish
            // and catch its exceptions
            $response->getContent();
        } catch (TransportExceptionInterface $e) {
            // Network-level failure (DNS, connection refused, SSL, timeout)
            throw new \RuntimeException('Could not reach Fritz!Box at '.$url.': '.$e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface $e) {
            // 4xx response
            if ($response->getStatusCode() === 403) {
                $this->deleteSid();
                throw new AccessDeniedHttpException('Access denied.');
            }

            return null;
        } catch (RedirectionExceptionInterface | ServerExceptionInterface $e) {
            return null;
        }

        return $response;
    }

    /**
     * Remove existing session ID if exists
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function deleteSid()
    {
        // if something went wrong, we delete the session ID, just in case
        $cache = new FilesystemAdapter();
        $cache->delete(self::session_cache_name);
    }

    /**
     * Request fritzbox session ID using challenge response method
     *
     * @return string|null                  A fritzbox session ID
     * @throws HttpRequestException         Basic connection problems
     * @throws InvalidResponseException     Response is not as expected
     * @throws InvalidResponseException     Login is blocked for x seconds
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getSid(): ?string
    {
        $cache = new FilesystemAdapter();
        $sid   = $cache->get(
            self::session_cache_name,
            function (ItemInterface $item) {
                // send initial request
                $response = $this->requestUrl($_ENV['APP_API_URL_LOGIN']);
                if ($response === null) {
                    throw new InvalidResponseException('Could not connect to Fritz!Box at '.$_ENV['APP_API_URL_LOGIN']);
                }
                $xml = simplexml_load_string($response->getContent());
                if (!$xml || !isset($xml->Challenge)) {
                    throw new InvalidResponseException('Unexpected HTTP response');
                }

                // extract challenge and SID
                $challenge = (string)$xml->Challenge;
                $sid       = (string)$xml->SID;
                if (preg_match('(^0+$)', $sid) && !empty($challenge)) {
                    // combine challenge with password to create cleartext password
                    $pass = $challenge.'-'.$_ENV['APP_API_PASSWORD'];

                    // hash UTF-16LE encoded password
                    $pass              = md5(mb_convert_encoding($pass, 'UTF-16LE'));
                    $challengeResponse = $challenge.'-'.$pass;
                    // send response to fritzbox
                    $query    = [
                        'username' => $_ENV['APP_API_USERNAME'],
                        'response' => $challengeResponse,
                    ];
                    $response = $this->requestUrl($_ENV['APP_API_URL_LOGIN'], ['query' => $query]);
                    if ($response === null) {
                        throw new InvalidResponseException('Could not connect to Fritz!Box at '.$_ENV['APP_API_URL_LOGIN']);
                    }
                    $xml = simplexml_load_string($response->getContent());
                    if (!$xml || !isset($xml->SID)) {
                        throw new InvalidResponseException('Unexpected HTTP response');
                    }

                    $sid       = (string)$xml->SID;
                    $blockTime = (string)$xml->BlockTime;
                    if (!preg_match('(^0+$)', $sid) && !empty($sid)) {
                        // valid SID received
                        return $sid;
                    }

                    if ($blockTime > 0) {
                        throw new InvalidResponseException(
                            'Challenge response failed. You are blocked for '.$blockTime.' seconds.'
                        );
                    }
                } else {
                    // we already have a valid SID
                    return $sid;
                }

                return null;
            }
        );

        return $sid;
    }

    /**
     * Invalidate and set SID
     *
     * @param  string  $sid
     */
    public function setSid(string $sid): void
    {
        $this->deleteSid();
        $this->sid = $sid;
    }

    /**
     * Calulate best representation of a number using unit prefixes
     *
     * @param  float   $milliValue      Unit has to be given in its milli (0.001) representation
     * @param  string  $unit            Unit name e.g. V, W, m, ...
     * @return array
     */
    public function bestFactor(float $milliValue, string $unit): array
    {
        // usually this method would round e.g. 1005 to 1.005k, which might get rounded to 1.00k.
        // In order to get more precision near prefix, we raise the barrier to 2000.
        $milliValue /= 2;

        $prefix = ['m', '', 'k', 'M', 'G', 'T', 'P'];
        $base   = 0;
        if ($milliValue > 0) {
            $base       = floor(log($milliValue, 1000));
            $milliValue = round($milliValue / pow(1000, $base), 3);
        }

        return ['value' => $milliValue * 2, 'unit' => $prefix[$base].$unit, 'factor' => pow(1000, $base)];
    }
}
