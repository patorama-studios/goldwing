<?php
namespace App\Services;

require_once __DIR__ . '/../ThirdParty/fpdf/fpdf.php';

use FPDF;

class PdfInvoiceService
{
    public static function generate(array $invoice, array $order, array $items, array $user): ?string
    {
        $invoiceNumber = $invoice['invoice_number'] ?? '';
        if ($invoiceNumber === '') {
            return null;
        }
        $safeNumber = preg_replace('/[^A-Za-z0-9_.-]/', '_', $invoiceNumber);
        $fileName = $safeNumber . '.pdf';
        $relativePath = '/uploads/invoices/' . $fileName;
        $dir = __DIR__ . '/../../public_html/uploads/invoices';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filePath = $dir . '/' . $fileName;

        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(true, 20);

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, SettingsService::getGlobal('site.name', 'Australian Goldwing Association'), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, 'Tax Invoice', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Invoice Number: ' . $invoiceNumber, 0, 1);
        $pdf->Cell(0, 6, 'Invoice Date: ' . ($invoice['created_at'] ?? date('Y-m-d')), 0, 1);
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, 'Billed To', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, ($user['name'] ?? 'Member'), 0, 1);
        if (!empty($user['email'])) {
            $pdf->Cell(0, 6, $user['email'], 0, 1);
        }
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(100, 7, 'Item', 1, 0);
        $pdf->Cell(20, 7, 'Qty', 1, 0, 'C');
        $pdf->Cell(35, 7, 'Unit', 1, 0, 'R');
        $pdf->Cell(35, 7, 'Total', 1, 1, 'R');
        $pdf->SetFont('Arial', '', 10);

        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            $qty = (int) ($item['quantity'] ?? 0);
            $unitPrice = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');
            $lineTotal = number_format((float) ($item['unit_price'] ?? 0) * $qty, 2, '.', '');

            $pdf->Cell(100, 7, $name, 1, 0);
            $pdf->Cell(20, 7, (string) $qty, 1, 0, 'C');
            $pdf->Cell(35, 7, 'A$' . $unitPrice, 1, 0, 'R');
            $pdf->Cell(35, 7, 'A$' . $lineTotal, 1, 1, 'R');
        }

        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(155, 7, 'Subtotal', 1, 0, 'R');
        $pdf->Cell(35, 7, 'A$' . number_format((float) ($order['subtotal'] ?? 0), 2, '.', ''), 1, 1, 'R');
        $pdf->Cell(155, 7, 'Tax', 1, 0, 'R');
        $pdf->Cell(35, 7, 'A$' . number_format((float) ($order['tax_total'] ?? 0), 2, '.', ''), 1, 1, 'R');
        if ((float) ($order['shipping_total'] ?? 0) > 0) {
            $pdf->Cell(155, 7, 'Shipping', 1, 0, 'R');
            $pdf->Cell(35, 7, 'A$' . number_format((float) ($order['shipping_total'] ?? 0), 2, '.', ''), 1, 1, 'R');
        }
        $pdf->Cell(155, 7, 'Total', 1, 0, 'R');
        $pdf->Cell(35, 7, 'A$' . number_format((float) ($order['total'] ?? 0), 2, '.', ''), 1, 1, 'R');

        $pdf->Output('F', $filePath);

        return $relativePath;
    }
}
