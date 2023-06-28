<?php

namespace TransportCompanies\Kit;

use Bitrix\Main\ArgumentNullException;
use \TransportCompanies\BaseAdapter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Adapter extends BaseAdapter
{
    private const TC_TYPE_PVZ_ID = '45023'; // TYPE_PVZ - KIT - ID
    public const TC_TYPE_PVZ_VALUE = 'KIT'; // TYPE_PVZ
    private const TARIFF_PVZ_CODE = '1';
    private const TARIFF_COURIER_CODE = '4';
    protected const DEFAULT_WEIGHT = '3';

    public static function getZpvdPvz(int $locationId, int $serviceId): array
    {
        return self::getPickupPoints(self::TC_TYPE_PVZ_VALUE, $locationId, $serviceId);
    }

    private static function getTariffs() :array
    {
        $arRes = Connector::getAllTariffs(self::TARIFF_PVZ_CODE);
        $arResult = array();

        foreach($arRes as $arItem) {
            $town = $arItem['towntoname'];
            $arResult[$town]['price'] = $arItem['pricesnew']['before'][1]['price'] ?: '';
            $arResult[$town]['mindeliverydays'] = $arItem['deliveryPeriodMin'];
        }

        return $arResult;
    }

    public static function savePvz()
    {
        try {
            if(self::DEBUGGING) {
                $log = new Logger('name');
                $log->pushHandler(new StreamHandler($_SERVER['DOCUMENT_ROOT'].self::LOG_PATH.'kit/log.txt', Logger::DEBUG));
                $log->alert('///////////////////// Новая выгрузка /////////////////////');
                $removedPvzCount = 0;
                $updatedPvzCount = 0;
                $addedPvzCount = 0;
                $unknownPvzCount = 0;
            }

            $exceptionCodes = [
                0 => 'Нет пвз'
            ];

            $arPvz = self::getTcPvz();
            $arZpvPvz = self::getPickupPointsForSave(self::TC_TYPE_PVZ_VALUE);
            $arDeliveryInfo = self::getTariffs();

            if ((!is_array($arPvz)) || (count($arPvz) === 0))
                throw new \Exception($exceptionCodes[0]);

            foreach ($arPvz as $pvz) {

                if (isset($deliveryInfo['errors']) && self::DEBUGGING)
                    $log->alert('Ошибка - '.$deliveryInfo['errors']['message'].' адрес - '.$pvz['location']['address_full']);

                $deliveryInfo = $arDeliveryInfo[$pvz['town']];

                $locationId = self::getLocationByCity($pvz['town']);

                if(strpos($pvz['address'], $pvz['town']) === false)
                    $pvz['name'] = $pvz['town'] . ' ' . $pvz['address'];
                else
                    $pvz['name'] = $pvz['address'];

                if (!$locationId || self::isRC($locationId) == true) {
                    if(self::DEBUGGING)
                        $unknownPvzCount++;

                    continue;
                }

                \CModule::IncludeModule('iblock');
                $el = new \CIBlockElement;

                $workingTime = explode('</p>', $pvz['worktime'])[1];
                if($workingTime)
                    $pvz['worktime'] = $workingTime;

                $PROP = self::getDefaultProp(
                    $pvz['code'],
                    $locationId,
                    $pvz['latitude'],
                    $pvz['longitude'],
                    self::TC_TYPE_PVZ_ID,
                    $pvz['phone'],
                    $deliveryInfo['price'] ?: '',
                    $deliveryInfo['mindeliverydays'] ?: '',
                    strip_tags($pvz['worktime'])
                );

                $previewText  = '';
                $previewText .= $pvz['name'].PHP_EOL;

                if($pvz['phone']) {
                    $previewText .= PHP_EOL.'тел.'.$pvz['phone'].PHP_EOL;
                }

                $previewText .= PHP_EOL. $pvz['worktime'] .PHP_EOL;

                $arLoadProductArray = Array(
                    "NAME"              => $pvz['name'],
                    "ACTIVE_FROM"       => date('d.m.Y H:i:s'),
                    "IBLOCK_SECTION_ID" => false,
                    "IBLOCK_ID"         => self::ZPV_STORE_IBLOCK_ID,
                    "ACTIVE"            => "Y",
                    "PREVIEW_TEXT"      => $previewText,
                    "PROPERTY_VALUES"   => $PROP,
                );

                // update
                if ($arZpvPvz[$pvz['code']]) {
                    if($el->Update($arZpvPvz[$pvz['code']]['ID'], $arLoadProductArray)) {
                        if(self::DEBUGGING) {
                            $log->alert('Элемент с ID ' . $arZpvPvz[$pvz['code']]['ID'] . ' Обновлен');
                            $updatedPvzCount++;
                        }
                    } elseif(self::DEBUGGING) {
                        $log->error('Элемент с ID '.$arZpvPvz[$pvz['code']]['ID'].' Не обновлен: '.$el->LAST_ERROR);
                    }

                    unset($arZpvPvz[$pvz['code']]);
                } else {
                    if($newElement = $el->Add($arLoadProductArray)) {
                        if(self::DEBUGGING) {
                            $log->alert('ID Нового элемента: ' . $newElement);
                            $addedPvzCount++;
                        }
                    } elseif(self::DEBUGGING) {
                        $log->error('Элемент не создан: '.$el->LAST_ERROR);
                    }
                }

            }

            foreach ($arZpvPvz as $arItem) {
                \CIBlockElement::Delete($arItem['ID']);
                if(self::DEBUGGING)
                    $removedPvzCount++;
            }
                
        } catch (\Exception $e) {
            if(self::DEBUGGING)
                $log->critical($e->getMessage());
        } finally {
            if(self::DEBUGGING) {
                $log->debug('Обновлено      pvz: ' . $updatedPvzCount);
                $log->debug('Добавлено      pvz: ' . $addedPvzCount);
                $log->debug('Удалено        pvz: ' . $removedPvzCount);
                $log->debug('Неопределенных pvz: ' . $unknownPvzCount);
                $log->debug('Всего API ТК   pvz: ' . count($arPvz));
                $log->close();
            }
        }
    }

    protected static function getTcPvz()
    {
        return Connector::getPvz();
    }

    /**
     * @throws ArgumentNullException
     */
    public static function getPvzDeliveryInfo(\stdClass $params): array
    {
        $pvzId = (int) $params->pvzId;
        $weight = (int) $params->weight / 1000;

        if(!$pvzId)
            throw new ArgumentNullException('pvzId');
        if(!$weight)
            throw new ArgumentNullException('weight');

        $kitPvzId = self::getPvzPropertyValue(self::PROP_TC_PVZ, $pvzId, self::TC_TYPE_PVZ_VALUE);

        $arRes = array();
        if($kitPvzId) {
            $arRes = Connector::calculate(
                self::TARIFF_PVZ_CODE,
                '',
                '',
                $kitPvzId,
                $weight
            );
        }

        return self::getData($arRes['mindeliverydays'], $arRes['maxdeliverydays'], $arRes['price'], $pvzId);
    }

    /**
     * @throws ArgumentNullException
     */
    public static function getCourierDeliveryInfo(\stdClass $params): array
    {
        $locationId = (int) $params->locationId;
        $street     = (string) $params->street;
        $house      = (string) $params->house;
        $weight     = (int) $params->weight / 1000;

        if(!$locationId)
            throw new ArgumentNullException('pvzId');
        if(!$street)
            throw new ArgumentNullException('street');
        if(!$house)
            throw new ArgumentNullException('house');
        if(!$weight)
            throw new ArgumentNullException('weight');

        $city = self::getCityByLocation($locationId);

        $address = implode(', ', array($street, $house));

        $arRes =array();
        if($city) {
            $arRes = Connector::calculate(
                self::TARIFF_COURIER_CODE,
                $city,
                $address,
                '',
                $weight
            );
        }

        return self::getData($arRes['mindeliverydays'], $arRes['maxdeliverydays'], $arRes['price']);
    }

    public static function testPvzLocation()
    {
        $arPvz = self::getTcPvz();
        $log = new Logger('name');
        $log->pushHandler(new StreamHandler($_SERVER['DOCUMENT_ROOT'].self::LOG_PATH.'kit/log.txt', Logger::DEBUG));
        $log->alert('///////////////////// Начало отладки местоположения /////////////////////');

        foreach ($arPvz as $pvz) {

            $formatCityName = self::formatLocationName($pvz['town']);
            $locationId = self::getLocationByCity($pvz['town']);

            if(!$locationId)
                $log->debug('; '.$pvz['town'] .'; '. $formatCityName.'; ');

        }

        $log->alert('///////////////////// Конец отладки местоположения /////////////////////');
        $log->close();
    }
}