<?php

namespace App\Client;

class HttpRequestException extends \RuntimeException
{
    public function __construct($curl, $url, $code = 0, \Exception $previous = null)
    {
//        $info = curl_getinfo($curl);
//        $message = 'Call to '.$info['url'].' ['.$info['http_code'].'] failed. No valid XML returned.';

        $message = curl_error($curl).' ['.curl_errno($curl).'] on '.$url;
        parent::__construct($message, $code, $previous);
    }
}
