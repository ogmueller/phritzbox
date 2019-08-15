<?php

namespace App\Client;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;
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
        $httpClient = HttpClient::create(
            [
                'http_version' => '2.0',
                'timeout'      => 30,
                'headers'      => [
                    'User-Agent' => 'phritzbox',
                ],
            ]
        );

        try {
            $response = $httpClient->request('GET', $url, $options);
            // do a getContent to actually wait for the request to finish
            // and catch its exceptions
            $response->getContent();
        } catch (TransportExceptionInterface    // When a network error occurs
        | RedirectionExceptionInterface         // On a 3xx and the "max_redirects" option has been reached
        | ClientExceptionInterface              // On a 4xx
        | ServerExceptionInterface $e           // On a 5xx
        ) {
            $responseCode = $response->getStatusCode();
            if ($responseCode === 403) {
                $this->deleteSid();
                throw new AccessDeniedHttpException('Access denied.');
            }

            // TODO: this should be logged
            dump($e);

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
                // a session should expire after 60min, but we re-validate it after 55min
                $item->expiresAfter(3300);

                // send initial request
                $response = $this->requestUrl($_ENV['APP_API_URL_LOGIN']);
                $xml      = simplexml_load_string($response->getContent());
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
}
