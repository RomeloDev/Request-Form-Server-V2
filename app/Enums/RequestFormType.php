<?php

namespace App\Enums;

enum RequestFormType: string
{
    case StockRequisition = 'Stock Requisition Slip';
    case PurchaseOrder = 'Purchase Order Requisition Slip';
    case CashDisbursement = 'Cash Disbursement Requisition Slip';
    case CheckIssuance = 'Check Issuance Requisition Slip';
    case CashAdvance = 'Application For Cash Advance';
    case ExpenseLiquidation = 'Liquidation of Actual Expense';
    case Refund = 'Refund Request';
    case Discount = 'Discount Requisition Form';
}
