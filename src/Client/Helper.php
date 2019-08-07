<?php

namespace App\Client;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class Helper
{
    const session_cache_name = 'sid';

    /**
     * Make HTTP request
     *
     * @param  string  $url
     *
     * @return bool|string
     * @throws HttpRequestException
     */
    static public function requestUrl(string $url)
    {
        $curl = curl_init();
        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => 'phritzbox',
            ]
        );
        $response = curl_exec($curl);

        if (curl_errno($curl)) {
//            var_dump($response);
            throw new HttpRequestException($curl, $url);
        }

        curl_close($curl);

        return $response;
    }

    /**
     * Remove existing session ID if exists
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    static public function deleteSid()
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
     */
    static public function getSid(): ?string
    {
        $cache = new FilesystemAdapter();
        $sid   = $cache->get(
            self::session_cache_name,
            function (ItemInterface $item) {
                // a session should expire after 60min, but we re-validate it after 55min
                $item->expiresAfter(3300);

                // send initial request
                $response = self::requestUrl($_ENV['APP_API_URL_LOGIN']);
                $xml      = simplexml_load_string($response);
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
                    $url      = $_ENV['APP_API_URL_LOGIN'].'?username='.$_ENV['APP_API_USERNAME'].'&response='.$challengeResponse;
                    $response = self::requestUrl($url);

                    $xml = simplexml_load_string($response);
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
}
