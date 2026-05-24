<?php
$dir = __DIR__ . '/database/migrations/';
$files = glob($dir . '*.php');
$fileMap = [];
foreach ($files as $f) {
    preg_match('/\d{4}_\d{2}_\d{2}_\d+_create_(\w+)_table/', $f, $m);
    if ($m) $fileMap[$m[1]] = $f;
}

$migrations = [];

$migrations['settings'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'settings\', function (Blueprint $table) {
            $table->id();
            $table->string(\'key\', 100)->unique();
            $table->text(\'value\')->nullable();
            $table->string(\'group\', 50)->default(\'general\');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'settings\'); }
};';

$migrations['plugins'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'plugins\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100)->unique();
            $table->string(\'title\', 200);
            $table->string(\'type\', 50)->comment(\'gateway,oauth,sms,email,captcha,certification,server\');
            $table->string(\'version\', 20)->default(\'1.0.0\');
            $table->string(\'author\', 100)->nullable();
            $table->text(\'description\')->nullable();
            $table->tinyInteger(\'status\')->default(0)->comment(\'0:disabled 1:enabled\');
            $table->json(\'config\')->nullable();
            $table->timestamps();
            $table->index(\'type\');
            $table->index(\'status\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'plugins\'); }
};';

$migrations['hooks'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'hooks\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100)->unique();
            $table->text(\'description\')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'hooks\'); }
};';

$migrations['hook_listeners'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'hook_listeners\', function (Blueprint $table) {
            $table->id();
            $table->string(\'hook\', 100);
            $table->string(\'plugin\', 100);
            $table->string(\'class\', 255);
            $table->integer(\'priority\')->default(10);
            $table->timestamps();
            $table->index(\'hook\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'hook_listeners\'); }
};';

$migrations['client_groups'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'client_groups\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100);
            $table->decimal(\'discount_percent\', 5, 2)->default(0);
            $table->string(\'color\', 20)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'client_groups\'); }
};';

$migrations['clients'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'clients\', function (Blueprint $table) {
            $table->id();
            $table->string(\'username\', 50)->unique();
            $table->string(\'email\', 100)->unique();
            $table->string(\'password\', 255);
            $table->tinyInteger(\'status\')->default(0)->comment(\'0:inactive 1:active 2:closed\');
            $table->unsignedBigInteger(\'group_id\')->default(0);
            $table->string(\'company_name\', 100)->nullable();
            $table->string(\'phone_code\', 10)->default(\'86\');
            $table->string(\'phone\', 50)->nullable();
            $table->string(\'country\', 100)->nullable();
            $table->string(\'province\', 100)->nullable();
            $table->string(\'city\', 100)->nullable();
            $table->string(\'address\', 255)->nullable();
            $table->unsignedBigInteger(\'currency_id\')->default(1);
            $table->decimal(\'credit\', 12, 2)->default(0.00);
            $table->decimal(\'credit_limit\', 12, 2)->default(0.00);
            $table->boolean(\'two_factor_enabled\')->default(false);
            $table->string(\'two_factor_secret\', 255)->nullable();
            $table->timestamp(\'email_verified_at\')->nullable();
            $table->timestamp(\'last_login_at\')->nullable();
            $table->string(\'last_login_ip\', 50)->nullable();
            $table->string(\'remember_token\', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(\'status\');
            $table->index(\'group_id\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'clients\'); }
};';

$migrations['contacts'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'contacts\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->string(\'name\', 100)->nullable();
            $table->string(\'email\', 100)->nullable();
            $table->string(\'phone\', 50)->nullable();
            $table->json(\'permissions\')->nullable();
            $table->timestamps();
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'contacts\'); }
};';

$migrations['client_oauth'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'client_oauth\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->string(\'provider\', 50);
            $table->string(\'provider_user_id\', 255);
            $table->text(\'access_token\')->nullable();
            $table->text(\'refresh_token\')->nullable();
            $table->timestamp(\'expires_at\')->nullable();
            $table->timestamps();
            $table->unique([\'provider\', \'provider_user_id\']);
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'client_oauth\'); }
};';

$migrations['product_groups'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'product_groups\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'parent_id\')->default(0);
            $table->string(\'name\', 100);
            $table->text(\'description\')->nullable();
            $table->integer(\'sort_order\')->default(0);
            $table->boolean(\'hidden\')->default(false);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'product_groups\'); }
};';

$migrations['products'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'products\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'group_id\');
            $table->string(\'name\', 100);
            $table->text(\'description\')->nullable();
            $table->string(\'type\', 25)->comment(\'hosting,vps,dedicated,other\');
            $table->string(\'pay_type\', 50)->default(\'recurring\');
            $table->string(\'pay_method\', 20)->default(\'prepaid\');
            $table->string(\'auto_setup\', 20)->default(\'manual\');
            $table->string(\'server_type\', 50)->nullable();
            $table->unsignedBigInteger(\'server_group_id\')->default(0);
            $table->boolean(\'stock_control\')->default(false);
            $table->integer(\'stock_qty\')->default(0);
            $table->json(\'domain_config\')->nullable();
            $table->json(\'password_config\')->nullable();
            $table->boolean(\'hidden\')->default(false);
            $table->boolean(\'retired\')->default(false);
            $table->boolean(\'is_featured\')->default(false);
            $table->integer(\'sort_order\')->default(0);
            $table->string(\'api_type\', 50)->nullable();
            $table->unsignedBigInteger(\'upstream_api_id\')->default(0);
            $table->unsignedBigInteger(\'upstream_product_id\')->default(0);
            $table->string(\'upstream_price_type\', 20)->default(\'percent\');
            $table->decimal(\'upstream_price_value\', 10, 2)->default(120.00);
            $table->timestamps();
            $table->index(\'group_id\');
            $table->index(\'type\');
            $table->index(\'hidden\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'products\'); }
};';

$migrations['pricings'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'pricings\', function (Blueprint $table) {
            $table->id();
            $table->string(\'type\', 50)->comment(\'product,configoption\');
            $table->unsignedBigInteger(\'rel_id\');
            $table->unsignedBigInteger(\'currency_id\');
            $table->decimal(\'monthly\', 10, 2)->default(-1);
            $table->decimal(\'monthly_setup\', 10, 2)->default(0);
            $table->decimal(\'quarterly\', 10, 2)->default(-1);
            $table->decimal(\'quarterly_setup\', 10, 2)->default(0);
            $table->decimal(\'semiannually\', 10, 2)->default(-1);
            $table->decimal(\'semiannually_setup\', 10, 2)->default(0);
            $table->decimal(\'annually\', 10, 2)->default(-1);
            $table->decimal(\'annually_setup\', 10, 2)->default(0);
            $table->decimal(\'biennially\', 10, 2)->default(-1);
            $table->decimal(\'biennially_setup\', 10, 2)->default(0);
            $table->decimal(\'triennially\', 10, 2)->default(-1);
            $table->decimal(\'triennially_setup\', 10, 2)->default(0);
            $table->decimal(\'onetime\', 10, 2)->default(-1);
            $table->decimal(\'hourly\', 10, 2)->default(-1);
            $table->decimal(\'daily\', 10, 2)->default(-1);
            $table->timestamps();
            $table->unique([\'type\', \'rel_id\', \'currency_id\']);
            $table->index([\'type\', \'rel_id\']);
        });
    }
    public function down(): void { Schema::dropIfExists(\'pricings\'); }
};';

$migrations['config_groups'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'config_groups\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100);
            $table->text(\'description\')->nullable();
            $table->integer(\'sort_order\')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'config_groups\'); }
};';

$migrations['config_options'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'config_options\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'group_id\');
            $table->string(\'option_name\', 255);
            $table->tinyInteger(\'option_type\')->comment(\'1:dropdown 2:radio 3:checkbox 4:quantity\');
            $table->text(\'description\')->nullable();
            $table->boolean(\'hidden\')->default(false);
            $table->integer(\'sort_order\')->default(0);
            $table->timestamps();
            $table->foreign(\'group_id\')->references(\'id\')->on(\'config_groups\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'config_options\'); }
};';

$migrations['config_option_subs'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'config_option_subs\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'config_option_id\');
            $table->string(\'option_name\', 255);
            $table->integer(\'sort_order\')->default(0);
            $table->boolean(\'hidden\')->default(false);
            $table->timestamps();
            $table->foreign(\'config_option_id\')->references(\'id\')->on(\'config_options\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'config_option_subs\'); }
};';

$migrations['custom_fields'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'custom_fields\', function (Blueprint $table) {
            $table->id();
            $table->string(\'type\', 50)->comment(\'product,client\');
            $table->unsignedBigInteger(\'rel_id\');
            $table->string(\'field_name\', 100);
            $table->string(\'field_type\', 50)->comment(\'text,password,dropdown,textarea\');
            $table->text(\'description\')->nullable();
            $table->text(\'options\')->nullable();
            $table->boolean(\'required\')->default(false);
            $table->boolean(\'admin_only\')->default(false);
            $table->integer(\'sort_order\')->default(0);
            $table->timestamps();
            $table->index([\'type\', \'rel_id\']);
        });
    }
    public function down(): void { Schema::dropIfExists(\'custom_fields\'); }
};';

$migrations['custom_field_values'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'custom_field_values\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'field_id\');
            $table->unsignedBigInteger(\'rel_id\');
            $table->text(\'value\')->nullable();
            $table->timestamps();
            $table->foreign(\'field_id\')->references(\'id\')->on(\'custom_fields\')->onDelete(\'cascade\');
            $table->index(\'rel_id\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'custom_field_values\'); }
};';

$migrations['orders'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'orders\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->string(\'order_number\', 100)->unique();
            $table->string(\'status\', 30)->default(\'Pending\');
            $table->decimal(\'amount\', 10, 2)->default(0.00);
            $table->unsignedBigInteger(\'currency_id\')->default(1);
            $table->string(\'payment_method\', 50)->nullable();
            $table->timestamp(\'paid_at\')->nullable();
            $table->string(\'promo_code\', 100)->nullable();
            $table->decimal(\'promo_value\', 10, 2)->default(0.00);
            $table->unsignedBigInteger(\'invoice_id\')->default(0);
            $table->text(\'notes\')->nullable();
            $table->text(\'admin_notes\')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(\'client_id\');
            $table->index(\'status\');
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'orders\'); }
};';

$migrations['hosts'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'hosts\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->unsignedBigInteger(\'order_id\');
            $table->unsignedBigInteger(\'product_id\');
            $table->unsignedBigInteger(\'server_id\')->default(0);
            $table->string(\'domain\', 255)->nullable();
            $table->string(\'username\', 255)->nullable();
            $table->string(\'password\', 255)->nullable();
            $table->string(\'billing_cycle\', 50);
            $table->decimal(\'first_payment_amount\', 10, 2)->default(0.00);
            $table->decimal(\'recurring_amount\', 10, 2)->default(0.00);
            $table->timestamp(\'registered_at\')->nullable();
            $table->timestamp(\'next_due_date\')->nullable();
            $table->timestamp(\'next_invoice_date\')->nullable();
            $table->timestamp(\'termination_date\')->nullable();
            $table->string(\'status\', 20)->default(\'Pending\');
            $table->boolean(\'auto_renew\')->default(false);
            $table->text(\'suspend_reason\')->nullable();
            $table->text(\'notes\')->nullable();
            $table->text(\'admin_notes\')->nullable();
            $table->timestamps();
            $table->index(\'client_id\');
            $table->index(\'product_id\');
            $table->index(\'status\');
            $table->index(\'next_due_date\');
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\');
            $table->foreign(\'order_id\')->references(\'id\')->on(\'orders\');
            $table->foreign(\'product_id\')->references(\'id\')->on(\'products\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'hosts\'); }
};';

$migrations['host_config_options'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'host_config_options\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'host_id\');
            $table->unsignedBigInteger(\'config_option_id\');
            $table->unsignedBigInteger(\'config_option_sub_id\')->default(0);
            $table->integer(\'qty\')->default(1);
            $table->timestamps();
            $table->foreign(\'host_id\')->references(\'id\')->on(\'hosts\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'host_config_options\'); }
};';

$migrations['promo_codes'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'promo_codes\', function (Blueprint $table) {
            $table->id();
            $table->string(\'code\', 100)->unique();
            $table->string(\'type\', 30)->comment(\'percentage,fixed\');
            $table->decimal(\'value\', 10, 2);
            $table->string(\'applies_to\', 50)->default(\'all\');
            $table->json(\'product_ids\')->nullable();
            $table->integer(\'max_uses\')->default(0);
            $table->integer(\'used_count\')->default(0);
            $table->boolean(\'once_per_client\')->default(false);
            $table->timestamp(\'starts_at\')->nullable();
            $table->timestamp(\'expires_at\')->nullable();
            $table->boolean(\'active\')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'promo_codes\'); }
};';

$migrations['upgrades'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'upgrades\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'host_id\');
            $table->string(\'type\', 20)->comment(\'upgrade,downgrade\');
            $table->unsignedBigInteger(\'from_product_id\')->nullable();
            $table->unsignedBigInteger(\'to_product_id\')->nullable();
            $table->decimal(\'amount\', 10, 2)->default(0.00);
            $table->string(\'status\', 30)->default(\'Pending\');
            $table->timestamp(\'completed_at\')->nullable();
            $table->timestamps();
            $table->foreign(\'host_id\')->references(\'id\')->on(\'hosts\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'upgrades\'); }
};';

$migrations['invoices'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'invoices\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->string(\'invoice_number\', 100)->unique();
            $table->decimal(\'subtotal\', 10, 2)->default(0.00);
            $table->decimal(\'tax\', 10, 2)->default(0.00);
            $table->decimal(\'tax_rate\', 5, 2)->default(0.00);
            $table->decimal(\'credit_used\', 10, 2)->default(0.00);
            $table->decimal(\'total\', 10, 2)->default(0.00);
            $table->string(\'status\', 20)->default(\'Unpaid\');
            $table->string(\'payment_method\', 50)->nullable();
            $table->timestamp(\'due_date\')->nullable();
            $table->timestamp(\'paid_at\')->nullable();
            $table->text(\'notes\')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(\'client_id\');
            $table->index(\'status\');
            $table->index(\'due_date\');
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'invoices\'); }
};';

$migrations['invoice_items'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'invoice_items\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'invoice_id\');
            $table->string(\'type\', 50)->comment(\'product,setup,upgrade,addon\');
            $table->string(\'description\', 255);
            $table->decimal(\'amount\', 10, 2);
            $table->unsignedBigInteger(\'rel_id\')->default(0);
            $table->timestamps();
            $table->foreign(\'invoice_id\')->references(\'id\')->on(\'invoices\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'invoice_items\'); }
};';

$migrations['accounts'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'accounts\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->unsignedBigInteger(\'invoice_id\')->default(0);
            $table->string(\'type\', 20)->comment(\'credit,debit\');
            $table->decimal(\'amount\', 10, 2);
            $table->decimal(\'fee\', 10, 2)->default(0.00);
            $table->string(\'payment_method\', 50)->nullable();
            $table->string(\'gateway_trans_id\', 255)->nullable();
            $table->string(\'description\', 255)->nullable();
            $table->tinyInteger(\'refunded\')->default(0);
            $table->timestamps();
            $table->index(\'client_id\');
            $table->index(\'invoice_id\');
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'accounts\'); }
};';

$migrations['credits'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'credits\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'client_id\');
            $table->string(\'type\', 20)->comment(\'add,deduct\');
            $table->decimal(\'amount\', 10, 2);
            $table->decimal(\'balance\', 10, 2);
            $table->string(\'description\', 255)->nullable();
            $table->string(\'rel_type\', 50)->nullable();
            $table->unsignedBigInteger(\'rel_id\')->default(0);
            $table->timestamps();
            $table->index(\'client_id\');
            $table->foreign(\'client_id\')->references(\'id\')->on(\'clients\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'credits\'); }
};';

$migrations['ticket_departments'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'ticket_departments\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100);
            $table->string(\'email\', 100)->nullable();
            $table->text(\'auto_response\')->nullable();
            $table->boolean(\'allow_client_open\')->default(true);
            $table->boolean(\'require_login\')->default(true);
            $table->integer(\'sort_order\')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'ticket_departments\'); }
};';

$migrations['ticket_statuses'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'ticket_statuses\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 50);
            $table->string(\'color\', 20)->default(\'#888888\');
            $table->boolean(\'show_client\')->default(true);
            $table->boolean(\'is_default\')->default(false);
            $table->integer(\'sort_order\')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'ticket_statuses\'); }
};';

$migrations['tickets'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'tickets\', function (Blueprint $table) {
            $table->id();
            $table->string(\'ticket_number\', 50)->unique();
            $table->unsignedBigInteger(\'client_id\')->nullable();
            $table->unsignedBigInteger(\'department_id\');
            $table->unsignedBigInteger(\'status_id\')->default(1);
            $table->unsignedBigInteger(\'assigned_to\')->default(0);
            $table->string(\'subject\', 255);
            $table->text(\'message\');
            $table->string(\'priority\', 20)->default(\'Medium\');
            $table->tinyInteger(\'rating\')->nullable();
            $table->timestamps();
            $table->index(\'client_id\');
            $table->index(\'department_id\');
            $table->index(\'status_id\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'tickets\'); }
};';

$migrations['ticket_replies'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'ticket_replies\', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger(\'ticket_id\');
            $table->string(\'author_type\', 20)->comment(\'client,admin\');
            $table->unsignedBigInteger(\'author_id\');
            $table->text(\'message\');
            $table->string(\'attachment\', 255)->nullable();
            $table->timestamps();
            $table->foreign(\'ticket_id\')->references(\'id\')->on(\'tickets\')->onDelete(\'cascade\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'ticket_replies\'); }
};';

$migrations['admin_users'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'admin_users\', function (Blueprint $table) {
            $table->id();
            $table->string(\'username\', 50)->unique();
            $table->string(\'email\', 100)->unique();
            $table->string(\'password\', 255);
            $table->string(\'real_name\', 100)->nullable();
            $table->string(\'phone\', 50)->nullable();
            $table->tinyInteger(\'status\')->default(1);
            $table->timestamp(\'last_login_at\')->nullable();
            $table->string(\'last_login_ip\', 50)->nullable();
            $table->string(\'remember_token\', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists(\'admin_users\'); }
};';

$migrations['email_templates'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'email_templates\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100)->unique();
            $table->string(\'subject\', 255);
            $table->longText(\'body\');
            $table->boolean(\'enabled\')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'email_templates\'); }
};';

$migrations['sms_templates'] = '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'sms_templates\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 100)->unique();
            $table->text(\'content\');
            $table->boolean(\'enabled\')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists(\'sms_templates\'); }
};';

$count = 0;
foreach ($migrations as $tableName => $content) {
    if (isset($fileMap[$tableName])) {
        file_put_contents($fileMap[$tableName], $content);
        echo "Written: $tableName\n";
        $count++;
    } else {
        echo "NOT FOUND: $tableName\n";
    }
}
echo "\nTotal written: $count\n";