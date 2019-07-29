<?php


namespace App\Client;


class Helper
{


    static public function getSid()
    {
var_dump($_ENV['APP_API_URL_LOGIN']);
        //Sende initialen Request an Fritzbox
        $http_response = @file_get_contents($_ENV['APP_API_URL_LOGIN']);
        //Parse Antwort XML
        $xml = simplexml_load_string($http_response);
        //Antwort prüfen, ob ein xml-Object mit einem Challenge-Tag existiert
        if (!$xml || !$xml->Challenge) {
            die ("Error: Unerwartete Antwort oder Kommunikationsfehler!\n");
        }
        //extrahiere Challange und SID Tags aus XML
        $challenge = (string)$xml->Challenge;
        $sid = (string)$xml->SID;
        if (preg_match("/^[0]+$/", $sid) && $challenge) {
            $sid = "";
            //erstelle Klartext Password String
            $pass = $challenge . "-" . $_ENV['APP_API_PASSWORD'];
            //UTF-16LE encoding des Passwords ist erforderlich
            $pass = mb_convert_encoding($pass, "UTF-16LE");
            //abschliessend ein md5hash über alles
            $md5 = md5($pass);
            //Erstelle Response String
            $challenge_response = $challenge . "-" . $md5;
            //Sende Response zur Fritzbox
            $url = $_ENV['APP_API_URL_LOGIN'] . "?username=" . $_ENV['APP_API_USERNAME'] . "&response=" . $challenge_response;
            $http_response = file_get_contents($url);
            //parse Antwort XML
            $xml = simplexml_load_string($http_response);
            $sid = (string)$xml->SID;
            if ((strlen($sid) > 0) && !preg_match("/^[0]+$/", $sid)) {
                //is not null, bingo!
                return $sid;
            }
        } else {
            //nutze existierende SID wenn $sid ein hex string ist
            if ((strlen($sid) > 0) && (preg_match("/^[0-9a-f]+$/", $sid))) {
                return $sid;
            }
        }
        return null;
    }
}