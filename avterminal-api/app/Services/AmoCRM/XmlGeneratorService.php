<?php

namespace App\Services\AmoCRM;

/**
 * Сервис для генерации XML файлов
 */
class XmlGeneratorService
{
    /**
     * Конвертировать массив в XML
     */
    public function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $subnode = $xml->addChild("$key");
                    $this->arrayToXml($value, $subnode);
                } else {
                    $subnode = $xml->addChild("item$key");
                    $this->arrayToXml($value, $subnode);
                }
            } else {
                $xml->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }

    /**
     * Генерировать XML для декларации пассажира
     */
    public function generatePassengerDeclarationXml(array $leadData, int $leadId): \SimpleXMLElement
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="windows-1251"?><AltaPassengerDeclaration/>');
        
        $this->addXmlData($xml, $leadData, $leadId);
        
        return $xml;
    }

    /**
     * Добавить данные в XML декларацию
     */
    private function addXmlData(\SimpleXMLElement $xml, array $data, int $leadId): void
    {
        $fields = [];
        foreach ($data['custom_fields'] as $field) {
            $fields[$field['field_name']] = $field['field_value'];
        }

        // DeclarantPerson
        $xml->addChild('DeclarantPerson_PersonSurname', $fields['Фамилия'] ?? '');
        $xml->addChild('DeclarantPerson_PersonName', $fields['Имя'] ?? '');
        $xml->addChild('DeclarantPerson_PersonMiddleName', $fields['Отчество'] ?? '');
        $xml->addChild('DeclarantPerson_BirthDate', $fields['День рождения'] ?? '');
        $xml->addChild('DeclarantPerson_INN', $fields['ИНН'] ?? '');

        // DeclarantPerson_PersonIdCard
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardCode', 'RU01001');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardName', 'ПАСПОРТ');
        $xml->addChild('DeclarantPerson_PersonIdCard_FullIdentityCardName', 'ПАСПОРТ ГРАЖДАНИНА РФ');
        $xml->addChild('DeclarantPerson_PersonIdCard_CountryCode', 'RU');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardSeries', $fields['Серия паспорта'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardNumber', $fields['Номер паспорта'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_OrganizationName', $fields['Кем выдан'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_IdentityCardDate', $fields['Дата выдачи'] ?? '');
        $xml->addChild('DeclarantPerson_PersonIdCard_IssuerCode', $fields['Код подразделения'] ?? '');

        // DeclarantPerson_Address
        $address = $xml->addChild('DeclarantPerson_Address');
        $address->addChild('AddressKindCode', '1');
        $address->addChild('Region', $fields['Субъект федерации'] ?? '');
        $address->addChild('District', $fields['Район'] ?? '');
        $address->addChild('Town', $fields['Город'] ?? '');
        $address->addChild('City', '');
        $address->addChild('StreetHouse', $fields['Улица'] ?? '');
        $address->addChild('House', $fields['Дом'] ?? '');
        $address->addChild('Room', $fields['Квартира'] ?? '');
        $address->addChild('CountryCode', 'RU');
        $address->addChild('CounryName', 'РОССИЯ');

        // TransportMeans
        $xml->addChild('TransportMeans_TransferPurposeCode', '1');
        $transportDetails = $xml->addChild('TransportMeans_TransportMeansDetails');
        $transportDetails->addChild('Mark', $fields['Марка'] ?? '');
        $transportDetails->addChild('Model', $fields['Модель'] ?? '');
        $transportDetails->addChild('VINID', $fields['VIN'] ?? '');
        $transportDetails->addChild('BodyID', $fields['VIN'] ?? '');
        $transportDetails->addChild('TransportModeCode', '30');
        $transportDetails->addChild('TransportMeansRegId', 'ОТСУТСТВУЕТ');
        $transportDetails->addChild('ChassisID', 'ОТСУТСТВУЕТ');
        $transportDetails->addChild('TypeIndicator', '1');
        $transportDetails->addChild('TransportKindName', mb_strtoupper('Автодорожный транспорт, ЗА ИСКЛЮЧЕНИЕМ транспортных средств, указанных под кодами 31, 32', 'UTF-8'));

        // MovingCode
        $xml->addChild('MovingCode', '3');

        // FilledPerson_SigningDetails
        $xml->addChild('FilledPerson_SigningDetails_PersonSurname', 'ПОЛУЭКТОВ');
        $xml->addChild('FilledPerson_SigningDetails_PersonName', 'ВИТАЛИЙ');
        $xml->addChild('FilledPerson_SigningDetails_PersonMiddleName', 'СЕРГЕЕВИЧ');
        $xml->addChild('FilledPerson_SigningDetails_PersonPost', 'ГЕНЕРАЛЬНЫЙ ДИРЕКТОР');

        // RoleCode
        $xml->addChild('RoleCode', '2');

        // SignatoryRepresentativeDetails
        $xml->addChild('SignatoryRepresentativeDetails_BrokerRegistryDocDetails_DocKindCode', '09034');
        $xml->addChild('SignatoryRepresentativeDetails_BrokerRegistryDocDetails_RegistrationNumberId', '1695');
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_DocKindCode', '11002');
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_PrDocumentName', 'ДОГОВОР С ТАМОЖЕННЫМ ПРЕДСТАВИТЕЛЕМ');
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_PrDocumentNumber', $leadId);
        $xml->addChild('SignatoryRepresentativeDetails_RepresentativeContractDetails_PrDocumentDate', date('Y-m-d'));

        // ElectronicDocumentSign
        $xml->addChild('ElectronicDocumentSign', 'ЭД');
    }
}
