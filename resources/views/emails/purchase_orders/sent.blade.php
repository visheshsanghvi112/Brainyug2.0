<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order {{ $purchaseOrder->order_number }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: Arial, sans-serif; color: #1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding: 24px;">
        <tr>
            <td align="center">
                <table role="presentation" width="700" cellspacing="0" cellpadding="0" style="width: 100%; max-width: 700px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="background: #0f172a; color: #ffffff; padding: 20px 24px;">
                            <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Purchase Order</h2>
                            <p style="margin: 6px 0 0; font-size: 14px; opacity: 0.9;">Order Number: {{ $purchaseOrder->order_number }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 20px 24px; font-size: 14px; line-height: 1.6;">
                            <p style="margin: 0 0 12px;">Dear {{ $purchaseOrder->supplier?->contact_person ?: $purchaseOrder->supplier?->name ?: 'Supplier' }},</p>
                            <p style="margin: 0 0 12px;">A new purchase order has been issued from {{ config('app.name') }}.</p>
                            <p style="margin: 0;">Please review the order details below and process it as per the required timeline.</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 24px 12px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; font-size: 13px;">
                                <tr>
                                    <td style="padding: 8px 10px; border: 1px solid #e5e7eb; background: #f9fafb; width: 35%;"><strong>Order Date</strong></td>
                                    <td style="padding: 8px 10px; border: 1px solid #e5e7eb;">{{ optional($purchaseOrder->order_date)->format('d-m-Y') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 10px; border: 1px solid #e5e7eb; background: #f9fafb;"><strong>Required Date</strong></td>
                                    <td style="padding: 8px 10px; border: 1px solid #e5e7eb;">{{ optional($purchaseOrder->required_date)->format('d-m-Y') ?: 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 10px; border: 1px solid #e5e7eb; background: #f9fafb;"><strong>Total Amount</strong></td>
                                    <td style="padding: 8px 10px; border: 1px solid #e5e7eb;">{{ number_format((float) $purchaseOrder->total_amount, 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 24px 20px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; font-size: 13px;">
                                <thead>
                                    <tr>
                                        <th align="left" style="padding: 10px; border: 1px solid #e5e7eb; background: #f3f4f6;">Product</th>
                                        <th align="right" style="padding: 10px; border: 1px solid #e5e7eb; background: #f3f4f6;">Qty</th>
                                        <th align="right" style="padding: 10px; border: 1px solid #e5e7eb; background: #f3f4f6;">Rate</th>
                                        <th align="right" style="padding: 10px; border: 1px solid #e5e7eb; background: #f3f4f6;">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($purchaseOrder->items as $item)
                                        <tr>
                                            <td style="padding: 10px; border: 1px solid #e5e7eb;">{{ $item->product?->product_name ?: 'N/A' }}</td>
                                            <td align="right" style="padding: 10px; border: 1px solid #e5e7eb;">{{ $item->qty_ordered }}</td>
                                            <td align="right" style="padding: 10px; border: 1px solid #e5e7eb;">{{ number_format((float) $item->rate, 2) }}</td>
                                            <td align="right" style="padding: 10px; border: 1px solid #e5e7eb;">{{ number_format((float) $item->line_total, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 24px 20px; font-size: 12px; color: #6b7280;">
                            <p style="margin: 0;">If you have any questions, please contact our procurement team.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
