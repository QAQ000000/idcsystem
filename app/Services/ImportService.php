<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class ImportService
{
    public const TYPES = ['clients', 'products', 'invoices'];

    public function import(ImportJob $job): void
    {
        $job->update([
            'status' => 'processing',
            'started_at' => now(),
            'errors' => [],
        ]);

        $success = 0;
        $failed = 0;
        $errors = [];

        try {
            $rows = $this->readCsv($job->file_path);
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                try {
                    match ($job->type) {
                        'clients' => $this->importClients($row),
                        'products' => $this->importProducts($row),
                        'invoices' => $this->importInvoices($row),
                        default => throw new InvalidArgumentException('不支持的导入类型。'),
                    };
                    $success++;
                } catch (Throwable $exception) {
                    $failed++;
                    $errors[] = [
                        'line' => $line,
                        'message' => $exception->getMessage(),
                        'row' => $row,
                    ];
                }
            }

            $job->update([
                'status' => $failed > 0 ? 'failed' : 'completed',
                'total_rows' => count($rows),
                'success_count' => $success,
                'failed_count' => $failed,
                'errors' => $errors,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $errors[] = ['line' => 0, 'message' => $exception->getMessage()];
            $job->update([
                'status' => 'failed',
                'success_count' => $success,
                'failed_count' => $failed > 0 ? $failed : 1,
                'errors' => $errors,
                'completed_at' => now(),
            ]);
        }
    }

    public function importClients(array $row): ?Client
    {
        $data = $this->validate($row, [
            'username' => ['required', 'string', 'max:50', 'unique:clients,username'],
            'email' => ['required', 'email', 'max:100', 'unique:clients,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'state_code' => ['nullable', 'string', 'max:10'],
        ]);

        return Client::query()->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'company_name' => $data['company'] ?? null,
            'address' => $data['address'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'state_code' => $data['state_code'] ?? null,
            'currency_id' => $this->defaultCurrency()->id,
            'status' => 1,
        ]);
    }

    public function importProducts(array $row): ?Product
    {
        $data = $this->validate($row, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'group_id' => ['required', 'integer', 'exists:product_groups,id'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'onetime', 'hourly', 'daily'])],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
        ]);

        return DB::transaction(function () use ($data) {
            $product = Product::query()->create([
                'group_id' => (int) $data['group_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => 'hosting',
                'pay_type' => 'recurring',
                'pay_method' => 'prepaid',
                'auto_setup' => 'manual',
                'stock_control' => array_key_exists('stock_qty', $data) && $data['stock_qty'] !== null && $data['stock_qty'] !== '',
                'stock_qty' => (int) ($data['stock_qty'] ?? 0),
                'hidden' => false,
                'retired' => false,
            ]);

            Pricing::query()->create([
                'type' => 'product',
                'rel_id' => $product->id,
                'currency_id' => $this->defaultCurrency()->id,
                $data['billing_cycle'] => round((float) $data['price'], 2),
            ]);

            return $product;
        });
    }

    public function importInvoices(array $row): ?Invoice
    {
        $data = $this->validate($row, [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'invoice_number' => ['nullable', 'string', 'max:100', 'unique:invoices,invoice_number'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => ['nullable', Rule::in(['Unpaid', 'Paid', 'Cancelled'])],
            'due_date' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($data) {
            $amount = round((float) $data['amount'], 2);
            $status = $data['status'] ?? 'Unpaid';
            $invoice = Invoice::query()->create([
                'client_id' => (int) $data['client_id'],
                'invoice_number' => $data['invoice_number'] ?: $this->nextInvoiceNumber(),
                'subtotal' => $amount,
                'tax' => 0,
                'tax_rate' => 0,
                'credit_used' => 0,
                'total' => $amount,
                'status' => $status,
                'payment_method' => $data['payment_method'] ?? null,
                'due_date' => $data['due_date'] ?? now(),
                'paid_at' => $status === 'Paid' ? ($data['paid_at'] ?? now()) : null,
                'notes' => '批量导入',
            ]);

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'type' => 'import',
                'description' => $data['description'] ?? '批量导入账单',
                'amount' => $amount,
                'rel_id' => 0,
            ]);

            return $invoice;
        });
    }

    public static function template(string $type): array
    {
        return match ($type) {
            'clients' => [
                ['username', 'email', 'password', 'phone', 'company', 'address', 'country_code', 'state_code'],
                ['testuser', 'test@example.com', 'password123', '13800138000', '测试公司', '测试地址', 'CN', 'BJ'],
            ],
            'products' => [
                ['name', 'description', 'group_id', 'price', 'billing_cycle', 'stock_qty'],
                ['虚拟主机', '基础虚拟主机', '1', '99.00', 'monthly', '100'],
            ],
            'invoices' => [
                ['client_id', 'invoice_number', 'amount', 'status', 'due_date', 'paid_at', 'payment_method', 'description'],
                ['1', 'INV-IMPORT-001', '99.00', 'Unpaid', now()->toDateString(), '', '', '迁移账单'],
            ],
            default => throw new InvalidArgumentException('不支持的导入类型。'),
        };
    }

    private function readCsv(string $filePath): array
    {
        if (!Storage::disk('local')->exists($filePath)) {
            throw new InvalidArgumentException('导入文件不存在。');
        }

        $handle = fopen(Storage::disk('local')->path($filePath), 'rb');
        if (!$handle) {
            throw new InvalidArgumentException('导入文件无法读取。');
        }

        $headers = null;
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(fn ($header) => trim($this->stripBom((string) $header)), $line);
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($line[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($headers === null) {
            throw new InvalidArgumentException('CSV 文件不能为空。');
        }

        return $rows;
    }

    private function validate(array $row, array $rules): array
    {
        $validator = Validator::make($row, $rules);
        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        return $validator->validated();
    }

    private function defaultCurrency(): Currency
    {
        return Currency::query()->where('is_default', true)->first()
            ?: Currency::query()->first()
            ?: Currency::query()->create(['code' => 'CNY', 'prefix' => '¥', 'exchange_rate' => 1, 'is_default' => true]);
    }

    private function nextInvoiceNumber(): string
    {
        return 'INV-IMPORT-' . now()->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }
}
