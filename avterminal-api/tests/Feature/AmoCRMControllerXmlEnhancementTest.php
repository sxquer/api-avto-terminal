<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\AmoCRMController;

class AmoCRMControllerXmlEnhancementTest extends TestCase
{
    /**
     * Test that the XML contains the new MovingCode node with value 3
     */
    public function test_xml_contains_new_moving_code_node()
    {
        // Create a SimpleXML element to test the addXmlData method
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="windows-1251"?><AltaPassengerDeclaration/>');
        
        // Mock data for testing
        $testData = [
            'custom_fields' => [
                ['field_name' => 'Фамилия', 'field_value' => 'ТЕСТОВ'],
                ['field_name' => 'Имя', 'field_value' => 'ТЕСТ'],
                ['field_name' => 'Марка', 'field_value' => 'TOYOTA'],
                ['field_name' => 'Модель', 'field_value' => 'CAMRY'],
                ['field_name' => 'VIN', 'field_value' => '1234567890'],
            ]
        ];
        
        // Create controller instance and call the private method using reflection
        $controller = new AmoCRMController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('addXmlData');
        $method->setAccessible(true);
        
        // Call the method
        $method->invoke($controller, $xml, $testData, 123);
        
        // Assert that MovingCode exists and has the correct value
        $movingCodeNodes = $xml->xpath('//MovingCode');
        $this->assertNotEmpty($movingCodeNodes, 'MovingCode node should exist');
        $this->assertEquals('3', (string)$movingCodeNodes[0], 'MovingCode should have value 3');
    }
    
    /**
     * Test that the XML contains the TransportKindName within TransportMeansDetails
     */
    public function test_xml_contains_transport_kind_name()
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="windows-1251"?><AltaPassengerDeclaration/>');
        
        $testData = [
            'custom_fields' => [
                ['field_name' => 'Марка', 'field_value' => 'TOYOTA'],
                ['field_name' => 'Модель', 'field_value' => 'CAMRY'],
                ['field_name' => 'VIN', 'field_value' => '1234567890'],
            ]
        ];
        
        $controller = new AmoCRMController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('addXmlData');
        $method->setAccessible(true);
        
        $method->invoke($controller, $xml, $testData, 123);
        
        // Assert that TransportKindName exists and has the correct uppercase value
        $transportKindNodes = $xml->xpath('//TransportMeans_TransportMeansDetails/TransportKindName');
        $this->assertNotEmpty($transportKindNodes, 'TransportKindName node should exist');
        $expectedValue = 'АВТОДОРОЖНЫЙ ТРАНСПОРТ, ЗА ИСКЛЮЧЕНИЕМ ТРАНСПОРТНЫХ СРЕДСТВ, УКАЗАННЫХ ПОД КОДАМИ 31, 32';
        $this->assertEquals($expectedValue, (string)$transportKindNodes[0], 'TransportKindName should have the correct uppercase value');
    }
    
    /**
     * Test that the XML contains DocKindCode in SignatoryRepresentativeDetails
     */
    public function test_xml_contains_doc_kind_code()
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="windows-1251"?><AltaPassengerDeclaration/>');
        
        $testData = [
            'custom_fields' => []
        ];
        
        $controller = new AmoCRMController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('addXmlData');
        $method->setAccessible(true);
        
        $method->invoke($controller, $xml, $testData, 123);
        
        // Assert that DocKindCode exists and has the correct value
        $docKindNodes = $xml->xpath('//SignatoryRepresentativeDetails_RepresentativeContractDetails_DocKindCode');
        $this->assertNotEmpty($docKindNodes, 'DocKindCode node should exist');
        $this->assertEquals('11002', (string)$docKindNodes[0], 'DocKindCode should have value 11002');
    }
    
    /**
     * Test that all three new nodes are present and maintain existing structure
     */
    public function test_xml_maintains_existing_structure_with_new_nodes()
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="windows-1251"?><AltaPassengerDeclaration/>');
        
        $testData = [
            'custom_fields' => [
                ['field_name' => 'Фамилия', 'field_value' => 'ИВАНОВ'],
                ['field_name' => 'Имя', 'field_value' => 'ИВАН'],
                ['field_name' => 'Марка', 'field_value' => 'BMW'],
                ['field_name' => 'Модель', 'field_value' => 'X5'],
                ['field_name' => 'VIN', 'field_value' => 'WBAXXX123456'],
            ]
        ];
        
        $controller = new AmoCRMController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('addXmlData');
        $method->setAccessible(true);
        
        $method->invoke($controller, $xml, $testData, 456);
        
        // Test that all three new nodes exist
        $this->assertNotEmpty($xml->xpath('//MovingCode'), 'MovingCode should exist');
        $this->assertNotEmpty($xml->xpath('//TransportMeans_TransportMeansDetails/TransportKindName'), 'TransportKindName should exist');
        $this->assertNotEmpty($xml->xpath('//SignatoryRepresentativeDetails_RepresentativeContractDetails_DocKindCode'), 'DocKindCode should exist');
        
        // Test that existing nodes still exist
        $this->assertNotEmpty($xml->xpath('//DeclarantPerson_PersonSurname'), 'Existing DeclarantPerson_PersonSurname should still exist');
        $this->assertNotEmpty($xml->xpath('//TransportMeans_TransportMeansDetails/Mark'), 'Existing Mark should still exist');
        $this->assertNotEmpty($xml->xpath('//ElectronicDocumentSign'), 'Existing ElectronicDocumentSign should still exist');
    }
}