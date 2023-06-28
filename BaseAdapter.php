<?php

namespace TransportCompanies;

use Delivery\DeliveryAdapter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use TransportCompanies\Zpvd\Adapter;

abstract class BaseAdapter
{
    protected const ZPV_STORE_IBLOCK_ID = '######';
    protected const DEFAULT_WEIGHT = '3000'; //В граммах
    protected const FROM_PLACE = '###############';
    protected const DELIVERY_TYPE = 'PVZ'; //какой тип офиса ТК получать
    protected const COUNTRY_CODE =  'RU';
    protected const LOG_PATH = '/local/include/transportCompanies/';
    protected const DEBUGGING = false;
    private const ZPV_CITIES_IBLOCK_ID = '#######';
    protected const PROP_TC_PVZ = 'TC_PVZ_ID'; // свойство ID ТК ПВЗ
    protected const PROP_ZIP    = 'ZIP'; // свойство почтовый индекс
    private const PROP_PICKUP_POINT             = 'PICKUP_POINT'; // ПВЗ
    private const PROP_PICKUP_POINT_VALUE       = 44078;
    private const PROP_SHOW_IN_SHOPS_LIST       = 'SHOW_IN_SHOPS_LIST'; // Показывать в списке магазинов
    private const PROP_SHOW_IN_SHOPS_LIST_VALUE = 44685;
    private const PROP_LOCATION                 = 'PVZ_LOCATION'; // Местоположение
    private const PROP_SHOP_CITY                = 'SHOP_CITY'; // Город
    private const PROP_SHOP_MAP_COORDS          = 'SHOP_MAP_COORDS'; // Координаты на карте
    private const PROP_SHOP_MAP_ZOOM            = 'SHOP_MAP_ZOOM'; // Увеличение карты
    private const PROP_SHOP_MAP_ZOOM_VALUE      = 14;
    private const PROP_TYPE_PVZ                 = 'TYPE_PVZ'; // Тип ПВЗ
    private const PROP_PHONE                    = 'PHONE'; // Телефон
    private const PROP_PVZ_COST                 = 'PVZ_COST'; // Стоимость (ПВЗ)
    private const PROP_PVZ_DELIVERY_TIME        = 'PVZ_DELIVERY_TIME'; // Сроки доставки (ПВЗ)
    private const PROP_SCHEDULE                 = 'SCHEDULE'; // Время работы
    private const PROP_VET_LICENZE              = 'VET_LICENZE'; // Лицензия на вет-препараты
    private const PROP_MOBILE_APP_DESC          = 'MOBILE_APP_DESC'; // Описание для мобильного приложения
    private const TC_LIST = array('CDEK', 'OZON', 'KIT', 'POCHTA'); // активные ТК

    abstract protected static function getTcPvz(string $deliveryServiceCode);
    abstract public    static function getPvzDeliveryInfo(\stdClass $params);
    abstract public    static function getCourierDeliveryInfo(\stdClass $params);
    abstract public    static function savePvz(string $deliveryServiceCode, string $tcTypePvzId);

    protected static function getPickupPointsForSave(string $tcName): array
    {
        $result = [];

        $pickupPoints = self::getZpvPvz([
            'filter' => [
                '=PROPERTY_TYPE_PVZ_VALUE' => $tcName,
            ],
            'select' => [
                'NAME',
                'PREVIEW_TEXT',
                'PROPERTY_'.self::PROP_TYPE_PVZ,
                'PROPERTY_'.self::PROP_TC_PVZ,
            ],
        ]);

        foreach($pickupPoints as $pickupPoint) {
            if ($pickupPoint['PROPERTY_'.self::PROP_TC_PVZ.'_VALUE'])
                $result[$pickupPoint['PROPERTY_'.self::PROP_TC_PVZ.'_VALUE']] = $pickupPoint;
        }

        return $result;
    }

    protected static function getPickupPoints(string $tcName, int $locationId, int $serviceId): array
    {
        $pickupPoints = self::getZpvPvz([
            'filter' => [
                'ACTIVE'                                      => 'Y',
                '=PROPERTY_'.self::PROP_TYPE_PVZ.'_VALUE'     => $tcName,
                '=PROPERTY_'.self::PROP_LOCATION              => $locationId,
                '=PROPERTY_'.self::PROP_PICKUP_POINT.'_VALUE' => 'Y',
            ],
            'select' => [
                'NAME',
                'PREVIEW_TEXT',
                'PROPERTY_'.self::PROP_PICKUP_POINT,
                'PROPERTY_'.self::PROP_SHOP_MAP_COORDS,
                'PROPERTY_'.self::PROP_SCHEDULE,
                'PROPERTY_'.self::PROP_SHOP_CITY,
                'PROPERTY_'.self::PROP_SHOP_MAP_ZOOM,
                'PROPERTY_'.self::PROP_MOBILE_APP_DESC,
                'PROPERTY_'.self::PROP_VET_LICENZE,
                'PROPERTY_'.self::PROP_SHOW_IN_SHOPS_LIST,
                'PROPERTY_'.self::PROP_PHONE,
                'PROPERTY_'.self::PROP_PVZ_COST,
                'PROPERTY_'.self::PROP_PVZ_DELIVERY_TIME,
            ]
        ]);

        $result = [];

        foreach($pickupPoints as $pickupPoint) {
            $coords = $pickupPoint['PROPERTY_'.self::PROP_SHOP_MAP_COORDS.'_VALUE'];

            if (strpos($coords, ',') !== false) {
                $coords = explode(',', $coords);
                $coords = array_map(function($item){
                    return str_replace(' ', '', $item);
                }, $coords);
            }

            $pvzCost         = $pickupPoint['PROPERTY_'.self::PROP_PVZ_COST.'_VALUE'];
            $pvzDeliveryTime = $pickupPoint['PROPERTY_'.self::PROP_PVZ_DELIVERY_TIME.'_VALUE'];

            if ($tcName === 'ZAPOVEDNIK') {
                $params = new \stdClass();
                $params->pvzId = (int)$pickupPoint['ID'];
                $params->date  = DeliveryAdapter::getDateTime();

                $deliveryData = Adapter::getPvzDeliveryInfo($params);

                $pvzCost = 0;
                $pvzDeliveryTime = $deliveryData['DAYS'];
            }

            $result[] = [
                'SERVICE_ID'         => $serviceId,
                'ID'                 => (int)$pickupPoint['ID'],
                'NAME'               => (string)$pickupPoint['NAME'],
                'PREVIEW_TEXT'       => (string)$pickupPoint['PREVIEW_TEXT'],
                'PICKUP_POINT'       => $pickupPoint['PROPERTY_'.self::PROP_PICKUP_POINT.'_VALUE'],
                'CITY'               => $pickupPoint['PROPERTY_SHOP_CITY_VALUE'],
                'COORDS'             => $coords,
                'ZOOM'               => $pickupPoint['PROPERTY_'.self::PROP_SHOP_MAP_ZOOM.'_VALUE'],
                'VET_LICENSE'        => $pickupPoint['PROPERTY_VET_LICENZE_VALUE'],
                'SHOW_IN_SHOPS_LIST' => $pickupPoint['PROPERTY_'.self::PROP_SHOW_IN_SHOPS_LIST.'_VALUE'],
                'SCHEDULE'           => $pickupPoint['PROPERTY_'.self::PROP_SCHEDULE.'_VALUE'],
                'PHONE'              => $pickupPoint['PROPERTY_'.self::PROP_PHONE.'_VALUE'],
                'MOBILE_APP_DESC'    => $pickupPoint['PROPERTY_MOBILE_APP_DESC_VALUE'],
                'PVZ_COST'           => $pvzCost,
                'PVZ_DELIVERY_TIME'  => $pvzDeliveryTime,
            ];
        }

        return $result;
    }

    protected static function getZpvPvz(array $parameters)
    {
        try {
            $arOrder = ['SORT' => 'ASC'];
            $arFilter = [
                'IBLOCK_ID' => OUR_STORES_IBLOCK_ID,
            ];

            $arFilter = array_merge($arFilter, $parameters['filter'] ?? []);

            $arSelectFields = [
                'IBLOCK_ID',
                'ID',
            ];

            $arSelectFields = array_merge($arSelectFields, $parameters['select'] ?? []);

            $rsElements = \CIBlockElement::GetList($arOrder, $arFilter, false, false, $arSelectFields);

            while($element = $rsElements->GetNext())
            {
                yield $element;
            }
        } catch (\Exception $e) {
            if(self::DEBUGGING) {
                $log = new Logger('name');
                $log->pushHandler(new StreamHandler($_SERVER['DOCUMENT_ROOT'].self::LOG_PATH. 'log.txt', Logger::DEBUG));
                $log->alert('///////////////////// Получение ПВЗ из инфоблока /////////////////////');
                $log->critical($e->getMessage());
            }
        }
    }

    protected static function getDefaultProp(
        $pvzId,
        $locationId,
        $latitude,
        $longitude,
        $typePVZ,
        $phone,
        $deliveryCost,
        $deliveryTime,
        $workingTime
    )
    {
        $PROP = array();
        $PROP[self::PROP_PICKUP_POINT] = self::PROP_PICKUP_POINT_VALUE;
        $PROP[self::PROP_TC_PVZ] = $pvzId;
        $PROP[self::PROP_SHOW_IN_SHOPS_LIST] = self::PROP_SHOW_IN_SHOPS_LIST_VALUE;
        $PROP[self::PROP_LOCATION] = $locationId;
        $PROP[self::PROP_SHOP_MAP_COORDS] = $latitude.', '.$longitude;
        $PROP[self::PROP_SHOP_MAP_ZOOM] = self::PROP_SHOP_MAP_ZOOM_VALUE;
        $PROP[self::PROP_TYPE_PVZ] = $typePVZ;
        $PROP[self::PROP_PHONE] = $phone;
        $PROP[self::PROP_PVZ_COST] = $deliveryCost;
        $PROP[self::PROP_PVZ_DELIVERY_TIME] = $deliveryTime;
        $PROP[self::PROP_SCHEDULE] = $workingTime;

        return $PROP;
    }

    protected static function formatRegionName($name): string
    {
        $search = array('- Югра', 'авт. округ', 'обл.', 'респ.', ' край', '- Кузбасс');
        $name = trim(str_replace($search, '', $name));
        $name = str_replace('Республика Крым', 'Крым', $name);

        return $name;
    }

    protected static function formatLocationName($name): string
    {
        $search = array(' город', 'г.', 'поселокского типа');
        $replace = array('','', 'поселок');
        $name = trim(str_replace($search, $replace, $name));

        return $name;
    }

    protected static function getLocationByCity($locationName, $regionName='')
    {
        $locationName = self::formatLocationName($locationName);

        $arFilter = array('=NAME.LANGUAGE_ID' => LANGUAGE_ID);
        $arSelect = array('ID', 'NAME_RU' => 'NAME.NAME', 'TYPE_CODE' => 'TYPE.CODE');
        $arFilter['NAME.NAME'] = '%'.$locationName.'%';

        $res = \Bitrix\Sale\Location\LocationTable::getList(array(
            'filter' => $arFilter,
            'select' => $arSelect
        ));

        $arResult = array();
        while($item = $res->fetch()) {
            $arResult['ALL'][] = $item['ID'];

            if($item['NAME_RU'] == $locationName)
                $arResult['DIRECT'][] = $item['ID'];
        }

        $result = false;

        if($arResult['ALL'] && count($arResult['ALL']) == 1) {
            $result = $arResult['ALL'][0];
        } elseif($arResult['DIRECT'] && count($arResult['DIRECT']) == 1) {
            $result = $arResult['DIRECT'][0];
        } elseif($regionName) {

            $regionName = self::formatRegionName($regionName);

            $res = \Bitrix\Sale\Location\LocationTable::getList(array(
                'filter' => array(
                    '=ID' => $arResult['ALL'],
                    '=PARENTS.NAME.LANGUAGE_ID' => LANGUAGE_ID
                ),
                'select' => array(
                    'ID' => 'ID',
                    'I_ID' => 'PARENTS.ID',
                    'PARENT_NAME' => 'PARENTS.NAME.NAME',
                ),
                'order' => array(
                    'PARENTS.DEPTH_LEVEL' => 'asc'
                )
            ));

            while ($item = $res->fetch()) {
                if (strpos($item['PARENT_NAME'], $regionName) !== false) {
                    $result = $item['ID'];
                    break;
                }
            }
        } else {
            $result = $arResult['DIRECT'][array_key_last($arResult['DIRECT'])] ?: $arResult['ALL'][array_key_last($arResult['ALL'])];
        }

        return $result;
    }

    protected static function getCityByLocation($locationId)
    {
        $res = \Bitrix\Sale\Location\LocationTable::getList(array(
            'filter' => array('=NAME.LANGUAGE_ID' => LANGUAGE_ID, '=ID' => $locationId),
            'select' => array('NAME')
        ));

        return $res->fetch()['SALE_LOCATION_LOCATION_NAME_NAME'];
    }

    protected static function getPvzPropertyValue($propertyCode, $pvzId, $pvzType='')
    {
        $arFilter = array(
            'IBLOCK_ID' => self::ZPV_STORE_IBLOCK_ID,
            'ACTIVE' => 'Y',
            'ID' => $pvzId,
            'PROPERTY_'.self::PROP_TYPE_PVZ.'_VALUE' => $pvzType
        );
        $res = \CIBlockElement::GetList(array(), $arFilter, array('PROPERTY_'.$propertyCode));
        if ($ob = $res->GetNext())
            return $ob['PROPERTY_'.$propertyCode.'_VALUE'];
        else
            return false;
    }

    protected static function getPvzName($id)
    {
        $res = \CIBlockElement::GetByID($id);
        if($ob = $res->GetNext())
            return (string) $ob['NAME'];
        else
            return null;
    }

    protected static function getDate($days): \DateTime
    {
        $dateTime = new \DateTime();
        return $dateTime->add(new \DateInterval("P${days}D"));
    }

    protected static function getData($deliveryMin, $deliveryMax, $price, $pvzId=''): array
    {
        $data = array(
            'DATE'      => $deliveryMin ? self::getDate($deliveryMin) : null,
            'PRICE'     => (float) $price ?: null,
        );

        if ($deliveryMin == $deliveryMax) {
            $data['DELIVERY_MIN'] = $data['DAYS_INFO'] = $data['DAYS'] = $deliveryMin?: null;
        } else {
            if($deliveryMin && $deliveryMax) {
                $data['DAYS_INFO'] = "от %DELIVERY_MIN% до %DELIVERY_MAX% дней";
            } else {
                $data['DAYS_INFO'] = null;
            }

            $data['DELIVERY_MIN'] = $deliveryMin;
            $data['DELIVERY_MAX'] = $deliveryMax;
        }

        if($pvzId != '')
            $data['address'] = self::getPvzName($pvzId);

        return $data;
    }

    protected static function isRC(int $locationId)
    {
        $arFilter = array('IBLOCK_ID' => self::ZPV_CITIES_IBLOCK_ID, 'ACTIVE' => 'Y', 'PROPERTY_LOCATION_ID' => $locationId, 'PROPERTY_IS_RC_VALUE'=>'Да');
        $res = \CIBlockElement::GetList(array(), $arFilter);
        if($res->fetch()['ID'])
            return true;
        else
            return false;
    }

    public static function getTcTypeByLocation(int $locationId)
    {
        $arResult = array();
        $arFilter = array('IBLOCK_ID' => self::ZPV_STORE_IBLOCK_ID, 'ACTIVE' => 'Y', 'PROPERTY_PVZ_LOCATION' => $locationId);
        $res = \CIBlockElement::GetList(array(), $arFilter, array('PROPERTY_TYPE_PVZ'));

        while($arItem = $res->GetNext(true, false)) {
            $arResult[] = $arItem['PROPERTY_TYPE_PVZ_VALUE'];
        }

        return $arResult;
    }

    public static function setLocationId()
    {

        \CModule::IncludeModule('iblock');

        $arFilter = [
            'IBLOCK_ID' => self::ZPV_STORE_IBLOCK_ID,
            '=PROPERTY_TYPE_PVZ_VALUE' => 'ZAPOVEDNIK',
            '!PROPERTY_SHOP_CITY_VALUE' => false
        ];
        $arSelectFields = [
            'IBLOCK_ID',
            'ID',
            'PROPERTY_SHOP_CITY',
            'PROPERTY_'.self::PROP_LOCATION
        ];
        $rsElements = \CIBlockElement::GetList(array(), $arFilter, false, false, $arSelectFields);
        while ($arElement = $rsElements->GetNext()) {
            $locationId = self::getLocationByCity(trim($arElement['PROPERTY_SHOP_CITY_VALUE']));
            \CIBlockElement::SetPropertyValuesEx($arElement['ID'], false, array(self::PROP_LOCATION => $locationId));
        }
    }
}