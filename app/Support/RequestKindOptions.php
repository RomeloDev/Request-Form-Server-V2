<?php

namespace App\Support;

class RequestKindOptions
{
    /**
     * The accepted "Kind of Request" values.
     *
     * These exact strings are stored in request_forms.kind_of_request and
     * are mirrored by the frontend, so spelling and punctuation must not
     * change.
     *
     * @var list<string>
     */
    public const VALUES = [
        'Office Supplies',
        'New Registration',
        'Utility Bills (Water, Electric, Guard Salary)',
        'Renewal of Unit',
        'Meal / Diesel / Gasoline Allowance',
        'Transfer Ownership of Unit',
        'Shipment Fee',
        'FFE (New / Replacement)',
        'Notarial Fee',
        'Professional Fee',
        'Retaineers Fee',
        'Appliance Repair (e.g. Aircon Cleaning)',
        'MC Service (Office / Service Used)',
        'LTO Accreditation',
        'DTI Accreditation',
        'Cash Advance - Logistics',
        'Others',
    ];
}
