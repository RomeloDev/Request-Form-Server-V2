<?php

namespace App\Support;

class RequestCurrencies
{
    public const PHP = 'PHP';

    public const EUR = 'EUR';

    public const USD = 'USD';

    /**
     * Every currency the API accepts, in display order.
     *
     * Form Requests pass this list to Rule::in().
     *
     * @var list<string>
     */
    public const VALUES = [
        self::PHP,
        self::EUR,
        self::USD,
    ];
}
