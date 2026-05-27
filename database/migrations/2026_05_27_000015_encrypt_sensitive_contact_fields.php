<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->text('phone')->nullable()->change();
            if (!Schema::hasColumn('clients', 'phone_hash')) {
                $table->string('phone_hash', 64)->nullable()->after('phone')->index();
            }
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->text('phone')->nullable()->change();
        });

        Schema::table('invoice_receipts', function (Blueprint $table): void {
            $table->text('bank_account')->nullable()->change();
            $table->text('company_phone')->nullable()->change();
        });

        $this->encryptTableColumn('clients', 'phone', true);
        $this->encryptTableColumn('contacts', 'phone');
        $this->encryptTableColumn('invoice_receipts', 'bank_account');
        $this->encryptTableColumn('invoice_receipts', 'company_phone');
    }

    public function down(): void
    {
        $this->decryptTableColumn('invoice_receipts', 'company_phone');
        $this->decryptTableColumn('invoice_receipts', 'bank_account');
        $this->decryptTableColumn('contacts', 'phone');
        $this->decryptTableColumn('clients', 'phone', true);

        Schema::table('invoice_receipts', function (Blueprint $table): void {
            $table->string('bank_account')->nullable()->change();
            $table->string('company_phone')->nullable()->change();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('phone', 50)->nullable()->change();
        });

        Schema::table('clients', function (Blueprint $table): void {
            if (Schema::hasColumn('clients', 'phone_hash')) {
                $table->dropColumn('phone_hash');
            }
            $table->string('phone', 50)->nullable()->change();
        });
    }

    private function encryptTableColumn(string $table, string $column, bool $withHash = false): void
    {
        DB::table($table)->orderBy('id')->chunk(100, function ($rows) use ($table, $column, $withHash): void {
            foreach ($rows as $row) {
                $value = $row->{$column};
                if ($value === null || $value === '' || $this->isEncrypted((string) $value)) {
                    continue;
                }

                $payload = [$column => Crypt::encryptString((string) $value)];
                if ($withHash) {
                    $payload['phone_hash'] = hash('sha256', (string) $value);
                }

                DB::table($table)->where('id', $row->id)->update($payload);
            }
        });
    }

    private function decryptTableColumn(string $table, string $column, bool $withHash = false): void
    {
        DB::table($table)->orderBy('id')->chunk(100, function ($rows) use ($table, $column, $withHash): void {
            foreach ($rows as $row) {
                $value = $row->{$column};
                if ($value === null || $value === '' || !$this->isEncrypted((string) $value)) {
                    continue;
                }

                $payload = [$column => Crypt::decryptString((string) $value)];
                if ($withHash) {
                    $payload['phone_hash'] = null;
                }

                DB::table($table)->where('id', $row->id)->update($payload);
            }
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
