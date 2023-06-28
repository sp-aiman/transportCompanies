<?php

namespace TransportCompanies\Cdek;

use GuzzleHttp\Client;

class Connector
{
    private const ACCOUNT_ID = '';
    private const SECURE_PASSWORD = '';
    private const GRANT_TYPE = 'client_credentials';
    private const AUTH_URL = 'https://api.cdek.ru/v2/oauth/token';
    private const PVZ_URL = 'http://api.cdek.ru/v2/deliverypoints';
    private const CALCULATE_URL = 'https://api.cdek.ru/v2/calculator/tariff';
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
                'client_id' => self::ACCOUNT_ID,
                'client_secret' => self::SECURE_PASSWORD,
                'grant_type' => self::GRANT_TYPE
            ],
            'http_errors' => false
        ]);

        if ($response->getStatusCode() === 200) {
            $token = json_decode((string)$response->getBody(), true);
            self::$token = "Bearer ${token['access_token']}";
        }
    }

    private static function get_token(): string
    {
        if (self::$token === null)
            self::set_token();

        return self::$token;
    }

    private static function query($requestMethod, $requestUrl, $queryParams=[], $exceptionCode='QUERY')
    {
        $token = self::get_token();

        $headerParams = array(
            'Content-Type'  => 'application/json; charset=utf-8',
            'authorization' => $token
        );

        if($requestMethod == 'POST') {
            $response = self::$client->request($requestMethod, $requestUrl, [
                'headers' => $headerParams,
                'body' => json_encode($queryParams),
                'http_errors' => false
            ]);
        } else {
            $response = self::$client->request($requestMethod, $requestUrl, [
                'headers' => $headerParams,
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
            self::query($requestMethod, $requestUrl, $queryParams, $exceptionCode);
        } else {
            throw new \ErrorException($exceptionCode);
        }

        return false;
    }


    public static function getPvz($queryParams=[])
    {
        $response = self::query(
            'GET',
            self::PVZ_URL,
            $queryParams,
            'getPvz'
        );

        return $response ?: [];
    }

    public static function calculate($tariffCode, $fromLocation, $cityCode, $postalCode, $address, $weight)
    {
        $response = self::query('POST', self::CALCULATE_URL,[
            'tariff_code' => $tariffCode,
            'from_location' => array(
                'address' => $fromLocation
            ),
            'to_location' => array(
                'code'          => $cityCode,
                'postal_code'   => $postalCode,
                'address'       => $address
            ),
            'packages' => array(
                'weight' => $weight
            )
        ],'CALCULATE'
        );

        return $response ?: [];
    }
}