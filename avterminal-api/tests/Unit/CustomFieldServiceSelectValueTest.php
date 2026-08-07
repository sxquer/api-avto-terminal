<?php

namespace Tests\Unit;

use App\Services\AmoCRM\CustomFieldService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CustomFieldServiceSelectValueTest extends TestCase
{
    public function test_select_can_be_sent_by_exact_text_without_enum_id(): void
    {
        $value = $this->createSelectValue('Транзит завершен');

        $this->assertSame('Транзит завершен', $value['value']);
        $this->assertNull($value['enum_id']);
    }

    public function test_existing_integer_enum_ids_are_preserved(): void
    {
        $value = $this->createSelectValue(1238357);

        $this->assertNull($value['value']);
        $this->assertSame(1238357, $value['enum_id']);
    }

    public function test_checkbox_values_are_sent_as_booleans(): void
    {
        $reflection = new ReflectionClass(CustomFieldService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(CustomFieldService::class, 'createCheckboxValueCollection');

        $this->assertTrue($method->invoke($service, true)->first()->toApi()['value']);
        $this->assertFalse($method->invoke($service, false)->first()->toApi()['value']);
    }

    private function createSelectValue(int|string $value): array
    {
        $reflection = new ReflectionClass(CustomFieldService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(CustomFieldService::class, 'createSelectValueCollection');
        $collection = $method->invoke($service, $value);

        return $collection->first()->toApi();
    }
}
