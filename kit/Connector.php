<?php

namespace TransportCompanies\Kit;

use GuzzleHttp\Client;
use SimpleXMLElement;

class Connector
{
    private const API_LOGIN = '';
    private const API_PASS = '';
    private const API_TOWN_FROM = '';
    private const API_EXTRA_CODE = '217';
    private const API_URL = 'https://home.courierexe.ru/api/';
    private const API_PVZ_LIMIT = '5000';
    private const CALC_PRICE_TYPE = 'CUSTOMER';
    private static $client;

    private static function get_client()
    {
        if (self::$client === null)
            self::$client = new Client(
                ['base_uri' => self::API_URL]
            );
    }

    private static function createXml($startTag)
    {
        $xml = simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?><'. $startTag .'/>', "SimpleXMLElement", LIBXML_NOBLANKS);

        $auth = $xml->addChild('auth', ' ');
        $auth->addAttribute('login', self::API_LOGIN);
        $auth->addAttribute('pass', self::API_PASS);
        $auth->addAttribute('extra', self::API_EXTRA_CODE);

        return $xml;
    }

    private static function response($xml, $responseType)
    {
        self::get_client();

        $sendData = [
            'body'    => $xml->asXML(),
            'headers' => [
                'Content-Type' => 'text/xml; charset=UTF8',
            ],
        ];

        $response = self::$client->request('POST', '', $sendData);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $data = $response->getBody()->getContents();

            if($responseType == 'XML') {
                $data = simplexml_load_string($data);
                $data = json_encode($data);
            }

            $data = json_decode($data, true);
            return $data;
        } else {
            throw new \ErrorException($statusCode);
        }
    }

    public static function getPvz()
    {
        $xml = self::createXml('pvzlist');

        $limit = $xml->addChild('limit');
        $limit->addChild('limitfrom',0);
        $limit->addChild('limitcount',self::API_PVZ_LIMIT);

        $data = self::response($xml, 'XML');

        return $data['pvz'];
    }

    public static function calculate($serviceType, $city, $address, $kitPvzId, $weight)
    {
        $xml = self::createXml('calculator');

        $order = $xml->addChild('order');
        $order->addChild('pricetype',self::CALC_PRICE_TYPE);

        $receiver = $order->addChild('receiver');
        $receiver->addChild('pvz', $kitPvzId);
        $town = $receiver->addChild('town', $city);
        $town = $receiver->addChild('address', $address);
        $town->addAttribute('country', 'RU');

        $order->addChild('weight', $weight);
        $order->addChild('service', $serviceType);
//        $order->addChild('paytype',''); // CASH,CARD,OTHER,NO
//        $order->addChild('price','');
//        $order->addChild('deliveryprice','');

        $data = self::response($xml, 'XML');

        return $data['calc'];

    }

    public static function getAllTariffs($serviceType)
    {
        $xml = self::createXml('tariffs');

        $xml->addChild('townfrom',self::API_TOWN_FROM);
        $xml->addChild('service', $serviceType);
        $xml->addChild('mainonly', 1);

        $data = self::response($xml, 'JSON');

        return $data['tariffs'];
    }
}