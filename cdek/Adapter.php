<?php

namespace TransportCompanies\Cdek;

class Adapter extends \TransportCompanies\Metaship\Adapter
{
    public const TC_TYPE_PVZ_VALUE = 'CDEK';
    private const TC_TYPE_PVZ_ID   = '####'; // TYPE_PVZ - CDEK - ID

    public static function getPvzDeliveryInfo(\stdClass $params): array
    {
        $params->deliveryServiceCode = \LapayGroup\MetaShipSdk\Enum\DeliveryServiceCode::CDEK;

        return parent::getPvzDeliveryInfo($params);
    }

    public static function getCourierDeliveryInfo(\stdClass $params): array
    {
        $params->deliveryServiceCode = \LapayGroup\MetaShipSdk\Enum\DeliveryServiceCode::CDEK;

        return parent::getCourierDeliveryInfo($params);
    }

    public static function getZpvdPvz(int $locationId, int $serviceId)
    {
        return parent::getPickupPointsFromDb($locationId, $serviceId, self::TC_TYPE_PVZ_VALUE);
    }

    public static function uploadPickupPoints()
    {
        parent::savePvz(\LapayGroup\MetaShipSdk\Enum\DeliveryServiceCode::CDEK, self::TC_TYPE_PVZ_ID);
    }
}