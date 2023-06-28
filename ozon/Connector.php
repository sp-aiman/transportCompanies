<?php

namespace TransportCompanies\Ozon;

use GuzzleHttp\Client;

class Connector
{
    private const CLIENT_ID = '#############'; // ID
    private const CLIENT_SECRET = '##########'; // password
    private const GRANT_TYPE = 'client_credentials'; // type
    private const AUTH_URL = 'https://xapi.ozon.ru/principal-auth-api/connect/token';
    private const API_URL = 'https://xapi.ozon.ru/principal-integration-api/';
    private const API_VERSION = 'v1';
    private const PVZ_URL = '/delivery/variants';
    private const PRICE_URL = '/delivery/calculate';
    private const TIME_URL = '/delivery/time';
    private const COURIER_URL = '/delivery/calculate/information';
    private static $client;
    private static $token;

    private static function get_client()
    {
        if (self::$client === null)
            self::$client = new Client();
    }

    private static function set_token()
    {
        self::get_client();

        $response = self::$client->post(self::AUTH_URL, [
            'form_params' => [
                'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET,
                'grant_type' => self::GRANT_TYPE
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            $token = json_decode((string)$response->getBody(), true);
            self::$token = "Bearer ${token['access_token']}";
        } else {
            throw new \ErrorException('STATUS');
        }
    }

    private static function get_token(): string
    {
        if (self::$token === null)
            self::set_token();

        return self::$token;
    }

    private static function query($requestMethod, $apiMethod, $queryParams=[], $exceptionCode='QUERY')
    {
        $token = self::get_token();

        $requestUrl = self::API_URL.self::API_VERSION.$apiMethod;

        $headersParams = array(
            'Content-Type'  => 'application/json; charset=utf-8',
            'authorization' => $token
        );

        if($requestMethod == 'POST') {
            $response = self::$client->request($requestMethod, $requestUrl, [
                'headers' => $headersParams,
                'body' => json_encode($queryParams),
                'http_errors' => false
            ]);
        } else {
            $response = self::$client->request($requestMethod, $requestUrl, [
                'headers' => $headersParams,
                'query' => $queryParams,
                'http_errors' => false
            ]);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $data = (string)$response->getBody();
            $data = json_decode($data, true);
            return $data;
        } elseif ($statusCode === 401) {
            self::set_token();
            self::query($requestMethod, $apiMethod, $queryParams, $exceptionCode);
        } else {
            throw new \ErrorException($exceptionCode);
        }

        return false;
    }

    public static function getPvz($cityName='')
    {
        $response = self::query('GET', self::PVZ_URL,[
                'payloadIncludes.includeWorkingHours' => 'true',
                'cityName'                            => $cityName,
        ],'getPvz'
        );

        return $response ? $response['data'] : [];
    }

    public static function calculatePvz($fromLocation, $pvzId, $weight)
    {
        $response = self::query('GET', self::PRICE_URL, [
            'deliveryVariantId' => $pvzId,
            'weight'            => $weight,
            'fromPlaceId'       => $fromLocation,
        ], 'CALCULATE_PRICE'
        );
        $data['amount'] = $response ? $response['amount'] : 0.0;

        $response = self::query('GET', self::TIME_URL, [
            'deliveryVariantId' => $pvzId,
            'fromPlaceId'       => $fromLocation,
        ], 'CALCULATE_TIME'
        );
        $data['days'] =  $response ? $response['days'] : 0;

        return $data;
    }

    public static function calculateCourier($fromLocation, $city, $weight, $length, $height, $width)
    {
        $response = self::query('POST', self::COURIER_URL,[
            'fromPlaceId'        => $fromLocation,
            'destinationAddress' => $city,
            'packages' => [
                [
                    'dimensions' => [
                        'weight' => $weight,
                        'length' => $length,
                        'height' => $height,
                        'width' => $width,
                    ],
                ]
            ]
        ],'CALCULATE_COURIER'
        );

        $response = $response['deliveryInfos'][0];

        if($response['deliveryType'] == 'Courier')
            return $response;
        else
            return [];
    }

}