<?php

namespace TransportCompanies\Pochta;

use GuzzleHttp\Client;

class  Connector
{
    private const API_LOGIN = '';
    private const API_PASS = '';
    private const API_TOKEN = 'AccessToken Z2L5N6f8c1Sg6ek6a9ONDiN9MU5jpZqG';
    private const API_URL = 'https://otpravka-api.pochta.ru/';
    private const API_VERSION = '1.0';
    private const API2_VERSION = 'v2';
    private const API2_URL = 'https://tariff.pochta.ru/';
    private const CALCULATE2_URL = '/calculate/tariff/delivery';
    private const PVZ_URL = '/unloading-passport/zip';
    private const CALCULATE_URL = '/tariff';
    private const COURIER_URL = '';
    private const FILE_DIR = __DIR__;
    private const FILE_NAME = 'pvz.zip';
    private static $client;
    private static $apiKey;

    private static function get_client()
    {
        if (self::$client === null)
            self::$client = new Client();
    }

    private static function get_key()
    {
        if (self::$apiKey === null)
            self::$apiKey = 'Basic ' . base64_encode(self::API_LOGIN.':'.self::API_PASS);

        return self::$apiKey;
    }

    private static function query($apiVersion,$requestMethod, $apiMethod, $queryParams=[], $exceptionCode='QUERY')
    {
        self::get_client();

        if($apiVersion == self::API_VERSION) {

            $apiKey = self::get_key();

            $requestUrl = self::API_URL.self::API_VERSION.$apiMethod;

            $headerParams = array(
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => self::API_TOKEN,
                'X-User-Authorization' => $apiKey
            );
        } else {

            $requestUrl = self::API2_URL.self::API2_VERSION.$apiMethod;

            $headerParams = array('Content-Type'  => 'application/json; charset=utf-8');
        }

        if($requestMethod == 'POST') {
            $response = self::$client->request($requestMethod, $requestUrl, [
                'headers' => $headerParams,
                'body' => json_encode($queryParams),
                'http_errors' => false
            ]);
        } elseif($requestMethod == 'GET_FILE') {
            $filePath = self::FILE_DIR .'/'.self::FILE_NAME;

            $response = self::$client->get($requestUrl, [
                'sink' => $filePath,
                'headers' => $headerParams,
                'query' => $queryParams,
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
            if($requestMethod == 'GET_FILE')
                return $filePath;

            $data = (string)$response->getBody();
            $data = json_decode($data, true);

            return $data;
        } else {
            throw new \ErrorException($exceptionCode);
        }
    }

    private static function unzip($filePath)
    {
        $zip = new \ZipArchive;
        if ($zip->open($filePath) === TRUE) {
            $fileName = $zip->getNameIndex(0);
            $zip->extractTo(self::FILE_DIR, $fileName);
            $zip->close();

            return __DIR__ . '/' . $fileName;
        } else
            throw new \ErrorException('UNZIP');
    }

    public static function getPvz()
    {
        $filePath = self::query(self::API_VERSION,'GET_FILE', self::PVZ_URL,[
            'type' => 'ALL'
        ],'getPvz'
        );

        $file = self::unzip($filePath);
        $data = file_get_contents($file);
        unlink($filePath);
        unlink($file);
        $data = json_decode($data, true);

        return $data ? $data['passportElements'] : [];
    }

    public static function calculatePvzOld($fromZip, $toZip, $weight, $length, $height, $width)
    {
        $response = self::query('POST', self::CALCULATE_URL,[
            "index-from" => $fromZip,
            "index-to" => $toZip,
            "mail-category"=> "ORDINARY", // Категория РПО
            "mail-type" => "POSTAL_PARCEL", // Вид РПО
            "mass" => $weight,
            "dimension" => [
                "height" => $height, // Линейная высота (сантиметры) (необязательный)
                "length" => $length, // Линейная длина (сантиметры) (необязательный)
                "width" => $width   // Линейная ширина (сантиметры) (необязательный)
            ],
            "fragile" => "false"  //Отметка "Осторожно/Хрупко" (необязательный)
        ],'calculatePvz'
        );

        return $response ?: [];
    }

    public static function calculatePvz($tariff, $fromZip, $toZip, $weight)
    {

        $response = self::query(self::API2_VERSION,'GET', self::CALCULATE2_URL,[
            'object'    => $tariff,
            'from'      => $fromZip,
            'to'        => $toZip,
            'weight'    => $weight,
            'pack'      => 30,
            'errorcode' => 0

        ],'calculatePvz'
        );

        return $response ?: [];
    }

    public static function calculateCourier($fromLocation, $city, $weight, $length, $height, $width)
    {

    }

}