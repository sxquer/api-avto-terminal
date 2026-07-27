<?php

namespace App\Exceptions\AmoCRM;

use RuntimeException;

class MultipleLeadsFoundForVinException extends RuntimeException
{
    public function __construct(string $vin, array $leadIds)
    {
        parent::__construct(sprintf(
            'Найдено несколько сделок с VIN %s в подключенных воронках: %s',
            $vin,
            implode(', ', $leadIds)
        ));
    }
}
