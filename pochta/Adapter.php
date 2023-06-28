<?php

namespace TransportCompanies\Pochta;

use Bitrix\Main\ArgumentNullException;
use \TransportCompanies\BaseAdapter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Adapter extends BaseAdapter
{
    protected const FROM_PLACE = '620007'; // ZIP
    private const TC_TYPE_PVZ_ID = '44910'; // TYPE_PVZ - POCHTA - ID
    private const TC_TYPE_PVZ_VALUE = 'POCHTA'; // TYPE_PVZ
    private const DEFAULT_PACKAGE_LENGTH = '40'; // длина упаковки для курьерки в см
    private const DEFAULT_PACKAGE_HEIGHT = '20'; // высота упаковки для курьерки в см
    private const DEFAULT_PACKAGE_WIDTH = '40'; // длина упаковки для курьерки в см
    private const TARIFF_PVZ_CODE = '3000'; // https://tariff.pochta.ru/post-calculator-api.pdf?323 - Посылка стандарт
    private const TARIFF_COURIER_CODE = '28030'; // https://tariff.pochta.ru/post-calculator-api.pdf?323 - Посылка курьер EMS

    protected static function getTcPvz(): array
    {
        return Connector::getPvz();
    }

    public static function getPvzDeliveryInfo(\stdClass $params): array
    {
        $pvzId = (int) $params->pvzId;
        $weight = (int) $params->weight;

        if(!$pvzId)
            throw new ArgumentNullException('pvzId');
        if(!$weight)
            throw new ArgumentNullException('weight');

        $toZip = self::getPvzPropertyValue(self::PROP_ZIP, $pvzId, self::TC_TYPE_PVZ_VALUE);

        return Connector::calculatePvz(
            self::TARIFF_PVZ_CODE,
            self::FROM_PLACE,
            $toZip,
            $weight,
        );

        //$deliveryInfo['paynds']; // с копейками
        //$deliveryInfo['delivery']['min'];
        //$deliveryInfo['delivery']['max'];
    }

    public static function getCourierDeliveryInfo(\stdClass $params): array
    {
        $locationId = (int) $params->locationId;
        $weight = (int) $params->weight;

        if(!$locationId)
            throw new ArgumentNullException('pvzId');
        if(!$weight)
            throw new ArgumentNullException('weight');

        // TODO: Implement getCourierDeliveryInfo() method.
    }

    private static function unsetFirstElementString($string)
    {
        $arString = explode(' ', $string);
        unset($arString[0]);

        return implode(' ', $arString);
    }

    public static function savePvz()
    {
        try {
            if(self::DEBUGGING) {
                $log = new Logger('name');
                $log->pushHandler(new StreamHandler($_SERVER['DOCUMENT_ROOT'].self::LOG_PATH.'pochta/log.txt', Logger::DEBUG));
                $log->alert('///////////////////// Новая выгрузка /////////////////////');
                $removedPvzCount = 0;
                $updatedPvzCount = 0;
                $addedPvzCount = 0;
            }

            $exceptionCodes = [
                0 => 'Нет пвз'
            ];

            $arPvz = self::getTcPvz();
            $arZpvPvz = self::getZpvPvz(self::TC_TYPE_PVZ_VALUE);

            if ((!is_array($arPvz)) || (count($arPvz) === 0))
                throw new \Exception($exceptionCodes[0]);

            foreach ($arPvz as $pvz) {

                $pvzId = $pvz['addressFias']['addGarCode'];

                $deliveryInfo = Connector::calculatePvz(
                    self::TARIFF_PVZ_CODE,
                    self::FROM_PLACE,
                    $pvz['address']['index'],
                    self::DEFAULT_WEIGHT
                );

                $city = self::unsetFirstElementString($pvz['address']['place']);

                $region = self::unsetFirstElementString($pvz['address']['region']);
                $search = array('Чувашская Республика -', 'Северная Осетия - Алания');
                $replace = array('Чувашская', 'Северная Осетия');
                $region = trim(str_replace($search, $replace, $region));

                $locationId = self::getLocationByCity($city, $region);

                if (!$locationId || self::isRC($locationId) == true)
                    continue;

                \CModule::IncludeModule('iblock');
                $el = new \CIBlockElement;

                $PROP = self::getDefaultProp(
                    $pvzId,
                    $locationId,
                    $pvz['latitude'],
                    $pvz['longitude'],
                    self::TC_TYPE_PVZ_ID,
                    '',
                    $deliveryInfo['paynds'] / 100,
                    $deliveryInfo['delivery']['min'],
                    $pvz['workTime']
                );

                $PROP[self::PROP_ZIP] = $pvz['address']['index'];

                $fullAddress  = '';

                if($region != $city)
                    $fullAddress .= $region .' ';

                $fullAddress .= $city.' ';
                $fullAddress .= $pvz['address']['street'].' ';
                $fullAddress .= $pvz['address']['house'];

                $previewText = $fullAddress.PHP_EOL;


                foreach ($pvz['workTime'] as $value) {
                    $previewText .= PHP_EOL . $value . PHP_EOL;

                }

                $arLoadProductArray = Array(
                    "NAME"              => $fullAddress,
                    "ACTIVE_FROM"       => date('d.m.Y H:i:s'),
                    "IBLOCK_SECTION_ID" => false,
                    "IBLOCK_ID"         => self::ZPV_STORE_IBLOCK_ID,
                    "ACTIVE"            => "Y",
                    "PREVIEW_TEXT"      => $previewText,
                    "PROPERTY_VALUES"   => $PROP,
                );

                // update
                if ($arZpvPvz[$pvzId]) {
                    if($el->Update($arZpvPvz[$pvzId]['ID'], $arLoadProductArray)) {
                        if(self::DEBUGGING) {
                            $log->alert('Элемент с ID ' . $arZpvPvz[$pvzId]['ID'] . ' Обновлен');
                            $updatedPvzCount++;
                        }
                    } elseif(self::DEBUGGING) {
                        $log->error('Элемент с ID '.$arZpvPvz[$pvzId]['ID'].' Не обновлен: '.$el->LAST_ERROR);
                    }

                    unset($arZpvPvz[$pvzId]);
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

                exit;
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
                $log->close();
            }
        }
    }
}