<?php
namespace App\Services;

class InvoiceService
{
    public static function createForOrder(array $order): ?array
    {
        $pdo = Database::connection();
        $settings = [
            'generate_pdf' => SettingsService::getGlobal('payments.stripe.generate_pdf', true) ? 1 : 0,
            'invoice_email_template' => (string) SettingsService::getGlobal('payments.stripe.invoice_email_template', ''),
        ];

        // Route store orders to the STORE sequence (STORE-YYYY-NNNNN) and
        // memberships to the MEM/INV sequence. Before this fix nextInvoiceNumber()
        // was always called without an order_type, so store orders ended up with
        // membership-prefix invoice numbers — clashing with the goldwing_invoice_number
        // already stamped on the Stripe invoice by StoreInvoiceService.
        // See migration 2026_06_09_settings_payments_store_invoice_prefix.sql.
        $orderType = strtolower((string) ($order['order_type'] ?? 'membership'));
        $invoiceNumber = PaymentSettingsService::nextInvoiceNumber((int) $order['channel_id'], $orderType);
        if ($invoiceNumber === '') {
            return null;
        }

        $taxBreakdown = json_encode(['gst' => (float) ($order['tax_total'] ?? 0)]);

        $stmt = $pdo->prepare('INSERT INTO invoices (invoice_number, order_id, user_id, currency, subtotal, tax_total, total, tax_breakdown_json, created_at) VALUES (:invoice_number, :order_id, :user_id, :currency, :subtotal, :tax_total, :total, :tax_breakdown_json, NOW())');
        $stmt->execute([
            'invoice_number' => $invoiceNumber,
            'order_id' => $order['id'],
            'user_id' => $order['user_id'],
            'currency' => $order['currency'] ?? 'AUD',
            'subtotal' => $order['subtotal'] ?? 0,
            'tax_total' => $order['tax_total'] ?? 0,
            'total' => $order['total'] ?? 0,
            'tax_breakdown_json' => $taxBreakdown,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $invoice = [
            'id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'order_id' => $order['id'],
            'user_id' => $order['user_id'],
            'currency' => $order['currency'] ?? 'AUD',
            'subtotal' => $order['subtotal'] ?? 0,
            'tax_total' => $order['tax_total'] ?? 0,
            'total' => $order['total'] ?? 0,
            'created_at' => date('Y-m-d'),
        ];

        $fileId = null;
        if ((int) ($settings['generate_pdf'] ?? 1) === 1) {
            $user = self::getUser($order['user_id']);
            $items = OrderService::getOrderItems((int) $order['id']);
            $relativePath = PdfInvoiceService::generate($invoice, $order, $items, $user ?? []);
            if ($relativePath) {
                $stmt = $pdo->prepare('INSERT INTO files (owner_type, owner_id, file_path, mime, label, created_at) VALUES (:owner_type, :owner_id, :file_path, :mime, :label, NOW())');
                $stmt->execute([
                    'owner_type' => 'invoice',
                    'owner_id' => $invoiceId,
                    'file_path' => $relativePath,
                    'mime' => 'application/pdf',
                    'label' => 'Tax Invoice',
                ]);
                $fileId = (int) $pdo->lastInsertId();
                MediaService::registerUpload([
                    'path' => $relativePath,
                    'file_type' => 'application/pdf',
                    'type' => 'pdf',
                    'title' => 'Tax Invoice ' . ($invoiceNumber ?? ''),
                    'uploaded_by_user_id' => (int) ($order['user_id'] ?? 0),
                    'source_context' => 'payments',
                    'source_table' => 'files',
                    'source_record_id' => $fileId,
                ]);
                $stmt = $pdo->prepare('UPDATE invoices SET pdf_file_id = :file_id WHERE id = :id');
                $stmt->execute(['file_id' => $fileId, 'id' => $invoiceId]);
            }
        }

        self::sendInvoiceEmail($order, $invoice, $fileId, $settings);

        $invoice['pdf_file_id'] = $fileId;
        return $invoice;
    }

    private static function sendInvoiceEmail(array $order, array $invoice, ?int $fileId, array $settings): void
    {
        $user = self::getUser($order['user_id']);
        if (!$user || empty($user['email'])) {
            return;
        }
        $downloadLink = '';
        if ($fileId) {
            $stmt = Database::connection()->prepare('SELECT file_path FROM files WHERE id = :id');
            $stmt->execute(['id' => $fileId]);
            $file = $stmt->fetch();
            if ($file && !empty($file['file_path'])) {
                $downloadLink = BaseUrlService::buildUrl($file['file_path']);
            }
        }

        $subject = 'Tax Invoice ' . ($invoice['invoice_number'] ?? '');
        $totalFormatted = 'A$' . number_format((float) ($invoice['total'] ?? 0), 2, '.', '');
        $invoiceDate = $invoice['created_at'] ?? date('Y-m-d');
        $template = trim((string) ($settings['invoice_email_template'] ?? ''));
        if ($template !== '') {
            $body = strtr($template, [
                '{{invoice_number}}' => e($invoice['invoice_number'] ?? ''),
                '{{invoice_date}}' => e($invoiceDate),
                '{{total}}' => e($totalFormatted),
                '{{download_url}}' => e($downloadLink),
                '{{download_link}}' => $downloadLink !== '' ? '<a href="' . e($downloadLink) . '">Download your tax invoice</a>' : '',
            ]);
        } else {
            $body = '<p>Thank you for your payment.</p>'
                . '<p>Invoice Number: ' . e($invoice['invoice_number'] ?? '') . '</p>'
                . '<p>Total: ' . e($totalFormatted) . '</p>'
                . ($downloadLink !== '' ? '<p><a href="' . e($downloadLink) . '">Download your tax invoice</a></p>' : '');
        }

        EmailService::send($user['email'], $subject, $body);
    }

    private static function getUser(int $userId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
