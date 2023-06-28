<?php

namespace TransportCompanies\Ozon;

use Bitrix\Main\ArgumentNullException;
use \TransportCompanies\BaseAdapter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Adapter extends BaseAdapter
{
    protected const FROM_PLACE = '############'; // City name type string
    private const TC_TYPE_PVZ_ID = '#####'; // TYPE_PVZ - OZON - ID
    public const TC_TYPE_PVZ_VALUE = 'OZON'; // TYPE_PVZ
    private const DEFAULT_PACKAGE_LENGTH = '400'; // длина упаковки для курьерки в мм
    private const DEFAULT_PACKAGE_HEIGHT = '200'; // высота упаковки для курьерки в мм
    private const DEFAULT_PACKAGE_WIDTH = '400'; // длина упаковки для курьерки в мм
    private const DEFAULT_CITY = '#######'; // сохраняем ПВЗ только для этого города

    public static function getZpvdPvz(int $locationId)
    {
        return self::getZpvPvz(self::TC_TYPE_PVZ_VALUE, $locationId);
    }

    public static function savePvz()
    {
        try {
            if(self::DEBUGGING) {
                $log = new Logger('name');
                $log->pushHandler(new StreamHandler($_SERVER['DOCUMENT_ROOT'].self::LOG_PATH.'ozon/log.txt', Logger::DEBUG));
                $log->alert('///////////////////// Новая выгрузка /////////////////////');
                $removedPvzCount = 0;
                $updatedPvzCount = 0;
                $addedPvzCount = 0;
            }

            $exceptionCodes = [
                0 => 'Нет пвз'
            ];

            $arPvz = self::getTcPvz();
            $arZpvPvz = self::getPickupPointsForSave(self::TC_TYPE_PVZ_VALUE);
            $arDeliveryInfo = array();

            if ((!is_array($arPvz)) || (count($arPvz) === 0))
                throw new \Exception($exceptionCodes[0]);

            foreach ($arPvz as $pvz) {

                $city = $pvz['settlement'];
                $region = $pvz['region'];

                if(isset($arDeliveryInfo[$city])) {
                    $deliveryInfo = $arDeliveryInfo[$city];
                } else {
                    $deliveryInfo = Connector::calculatePvz(
                        self::FROM_PLACE,
                        $pvz['id'],
                        self::DEFAULT_WEIGHT
                    );
                    $arDeliveryInfo[$city] = $deliveryInfo;
                }

                $locationId = self::getLocationByCity($city, $region);

                if (!$locationId) //|| $this->isRC($locationId) == true) // временно - нужны только ПВЗ из города РЦ (Питер)
                    continue;

                \CModule::IncludeModule('iblock');
                $el = new \CIBlockElement;

                $PROP = self::getDefaultProp(
                    $pvz['id'],
                    $locationId,
                    $pvz['lat'],
                    $pvz['long'],
                    self::TC_TYPE_PVZ_ID,
                    $pvz['phone'],
                    $deliveryInfo['amount'] ?: '',
                    $deliveryInfo['days'] ?: '',
                    $pvz['workingHours']
                );

                $previewText  = '';
                $previewText .= $pvz['name'].PHP_EOL;

                if ($pvz['phone']) {
                    $previewText .= PHP_EOL.'тел.'.$pvz['phone'].PHP_EOL;
                }


                $i = 0;
                foreach ($pvz['workingHours'] as $value) {
                    if ($i++ === 5) {
                        break;
                    }


                    $date = new \DateTime($value['date']);
                    $previewText.=PHP_EOL.PHP_EOL.$date->format('d-m-Y').PHP_EOL;

                    foreach($value['periods'] as $hours) {
                        $times = [];
                        $times[] = $hours['min']['hours'];
                        $times[] = $hours['min']['minutes'];
                        $times[] = $hours['max']['hours'];
                        $times[] = $hours['max']['minutes'];

                        foreach($times as &$time) {
                            if ($time < 10) {
                                $time = '0'.$time;
                            }
                        }

                        list($minHour, $minMinutes, $maxHour, $maxMinutes) = $times;

                        $previewText.='С '.$minHour.':'.$minMinutes.' ';
                        $previewText.='До '.$maxHour.':'.$maxMinutes.PHP_EOL;
                    }

                }

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
                if ($arZpvPvz[$pvz['id']]) {
                    if($el->Update($arZpvPvz[$pvz['id']]['ID'], $arLoadProductArray)) {
                        if(self::DEBUGGING) {
                            $log->alert('Элемент с ID ' . $arZpvPvz[$pvz['id']]['ID'] . ' Обновлен');
                            $updatedPvzCount++;
                        }
                    } elseif(self::DEBUGGING) {
                        $log->error('Элемент с ID '.$arZpvPvz[$pvz['id']]['ID'].' Не обновлен: '.$el->LAST_ERROR);
                    }

                    unset($arZpvPvz[$pvz['id']]);
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
                $log->debug('Обновлено pvz: ' . $updatedPvzCount);
                $log->debug('Добавлено pvz: ' . $addedPvzCount);
                $log->debug('Удалено   pvz: ' . $removedPvzCount);
                $log->debug('Всего     pvz: ' . count($arPvz));
                $log->close();
            }
        }
    }

    protected static function getTcPvz()
    {
        return Connector::getPvz(self::DEFAULT_CITY);
    }

    /**
     * @throws ArgumentNullException
     */
    public static function getPvzDeliveryInfo(\stdClass $params): array
    {
        $pvzId = (int) $params->pvzId;
        $weight = (int) $params->weight;

        if(!$pvzId)
            throw new ArgumentNullException('pvzId');
        if(!$weight)
            throw new ArgumentNullException('weight');

        $ozonPvzId = self::getPvzPropertyValue(self::PROP_TC_PVZ, $pvzId, self::TC_TYPE_PVZ_VALUE);

        $arRes = array();
        if ($ozonPvzId) {
            $arRes = Connector::calculatePvz(
                self::FROM_PLACE,
                $ozonPvzId,
                $weight
            );
        }

        return self::getData($arRes['days'], $arRes['days'], $arRes['amount'], $pvzId);
    }

    /**
     * @throws ArgumentNullException
     */
    public static function getCourierDeliveryInfo(\stdClass $params): array
    {
        $locationId = (int) $params->locationId;
        $weight = (int) $params->weight;

        if(!$locationId)
            throw new ArgumentNullException('pvzId');
        if(!$weight)
            throw new ArgumentNullException('weight');

        $city = self::getCityByLocation($locationId);

        $arRes = array();
        if($city) {
            $arRes = Connector::calculateCourier(
                self::FROM_PLACE,
                $city,
                $weight,
                self::DEFAULT_PACKAGE_LENGTH,
                self::DEFAULT_PACKAGE_HEIGHT,
                self::DEFAULT_PACKAGE_WIDTH
            );
        }

        return self::getData($arRes['deliveryTermInDays'], $arRes['deliveryTermInDays'], $arRes['price']);
    }

}