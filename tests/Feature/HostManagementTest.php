<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_view_host_detail(): void
    {
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertOk()
            ->assertSee($host->product->name);
    }

    public function test_client_can_create_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $this->assertDatabaseHas('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
    }

    public function test_client_cannot_create_duplicate_unpaid_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('billing_cycle');

        $this->assertSame(1, InvoiceItem::query()
            ->where('type', 'renewal')
            ->where('rel_id', $host->id)
            ->count());
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'renew_invoice_failed',
            'message' => '已有未支付的续费账单',
        ]);
    }

    public function test_client_cannot_create_renew_invoice_for_terminated_host(): void
    {
        Mail::fake();
        $host = $this->host(['status' => 'Terminated']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertForbidden();

        $this->assertDatabaseMissing('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
    }

    public function test_host_service_rechecks_latest_status_before_creating_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host();
        $staleHost = $host->fresh(['client', 'product']);
        $host->update(['status' => 'Terminated']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('当前服务状态不允许续费');

        try {
            app(\App\Modules\Order\Services\HostService::class)->renew($staleHost, 'monthly');
        } finally {
            $this->assertDatabaseMissing('invoice_items', [
                'type' => 'renewal',
                'rel_id' => $host->id,
            ]);
            $this->assertDatabaseHas('host_action_logs', [
                'host_id' => $host->id,
                'action' => 'renew_invoice_failed',
                'message' => '当前服务状态不允许续费',
            ]);
        }
    }

    public function test_paid_renew_invoice_extends_host_due_date(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);
        $oldDueDate = $host->next_due_date->copy();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        app(PaymentService::class)->processPayment(
            \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId),
            'manual_pay',
            []
        );
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-001',
        ]));

        $this->assertTrue($host->fresh()->next_due_date->greaterThan($oldDueDate));
    }

    public function test_paid_renew_invoice_uses_selected_billing_cycle(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['billing_cycle' => 'monthly', 'next_due_date' => now()->startOfSecond()]);
        $oldDueDate = $host->next_due_date->copy();
        Pricing::query()
            ->where('type', 'product')
            ->where('rel_id', $host->product_id)
            ->where('currency_id', $host->client->currency_id)
            ->update(['annually' => 500]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'annually'])
            ->assertRedirect();

        $item = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->firstOrFail();
        $this->assertSame('annually', $item->meta['billing_cycle'] ?? null);

        app(PaymentService::class)->processPayment(
            \App\Modules\Finance\Models\Invoice::query()->findOrFail($item->invoice_id),
            'manual_pay',
            []
        );
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $item->invoice_id,
            'amount' => 500.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-ANNUALLY-001',
        ]));

        $host->refresh();
        $this->assertSame('annually', $host->billing_cycle);
        $this->assertTrue($host->next_due_date->equalTo($oldDueDate->copy()->addYearNoOverflow()));
    }

    public function test_paid_renew_invoice_is_not_applied_twice(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $invoice = \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId);

        $this->assertTrue(app(\App\Modules\Finance\Services\InvoiceService::class)->markAsPaid($invoice, 'manual_pay', 'HOST-RENEW-ONCE'));
        $firstDueDate = $host->fresh()->next_due_date->copy();
        $this->assertFalse(app(\App\Modules\Finance\Services\InvoiceService::class)->markAsPaid($invoice->fresh(), 'manual_pay', 'HOST-RENEW-TWICE'));

        $this->assertTrue($host->fresh()->next_due_date->equalTo($firstDueDate));
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'HOST-RENEW-TWICE']);
    }

    public function test_apply_paid_invoice_ignores_unpaid_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);
        $oldDueDate = $host->next_due_date->copy();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $invoice = \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId);

        app(\App\Modules\Order\Services\HostService::class)->applyPaidInvoice($invoice);

        $this->assertTrue($host->fresh()->next_due_date->equalTo($oldDueDate));
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'renew_paid',
        ]);
    }

    public function test_apply_paid_invoice_is_idempotent_for_renewal_items(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $item = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->firstOrFail();
        $invoice = \App\Modules\Finance\Models\Invoice::query()->findOrFail($item->invoice_id);

        $this->assertTrue(app(\App\Modules\Finance\Services\InvoiceService::class)->markAsPaid($invoice, 'manual_pay', 'HOST-RENEW-IDEMPOTENT-1'));
        $firstDueDate = $host->fresh()->next_due_date->copy();

        app(\App\Modules\Order\Services\HostService::class)->applyPaidInvoice($invoice->fresh());

        $this->assertTrue($host->fresh()->next_due_date->equalTo($firstDueDate));
        $this->assertSame(1, \App\Models\HostActionLog::query()
            ->where('host_id', $host->id)
            ->where('action', 'renew_paid')
            ->where('meta->invoice_item_id', $item->id)
            ->count());
    }

    public function test_paid_renew_invoice_rechecks_latest_host_status_before_applying(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $oldDueDate = $host->fresh()->next_due_date->copy();
        app(PaymentService::class)->processPayment(
            \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId),
            'manual_pay',
            []
        );
        $host->update(['status' => 'Terminated']);

        $this->assertFalse(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-TERMINATED-001',
        ]));

        $this->assertSame('Unpaid', \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId)->status);
        $this->assertSame('Terminated', $host->fresh()->status);
        $this->assertTrue($host->fresh()->next_due_date->equalTo($oldDueDate));
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'HOST-RENEW-TERMINATED-001']);
    }

    public function test_paid_renew_invoice_unsuspends_suspended_host_through_server_module(): void
    {
        Mail::fake();
        $this->installManualPay();
        $this->installMockServer();
        $host = $this->host(['status' => 'Suspended']);
        $host->product->update(['server_type' => 'mock_server']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        app(PaymentService::class)->processPayment(
            \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId),
            'manual_pay',
            []
        );
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-UNSUSPEND-001',
        ]));

        $this->assertSame('Active', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'unsuspend',
        ]);
    }

    public function test_paid_renew_invoice_keeps_host_suspended_when_server_unsuspend_fails(): void
    {
        Mail::fake();
        $this->installManualPay();
        $this->installMockServer(['fail_unsuspend' => true]);
        $host = $this->host(['status' => 'Suspended']);
        $host->product->update(['server_type' => 'mock_server']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        app(PaymentService::class)->processPayment(
            \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId),
            'manual_pay',
            []
        );
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-UNSUSPEND-FAIL-001',
        ]));

        $this->assertSame('Suspended', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'unsuspend_failed',
            'message' => '服务器模块解除暂停失败',
        ]);
    }

    public function test_client_cannot_create_zero_amount_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'annually'])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('billing_cycle');

        $this->assertDatabaseMissing('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'renew_invoice_failed',
        ]);
    }

    public function test_host_service_rejects_renew_invoice_for_inactive_client(): void
    {
        Mail::fake();
        $host = $this->host();
        $host->client->update(['status' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('客户账号状态不允许续费');

        app(\App\Modules\Order\Services\HostService::class)->renew($host->fresh(['client', 'product']), 'monthly');
    }

    public function test_client_can_create_upgrade_invoice(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Pro VPS', 90);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect();

        $this->assertDatabaseHas('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
            'status' => 'Pending',
        ]);
        $this->assertDatabaseHas('invoice_items', ['type' => 'upgrade']);
    }

    public function test_paid_upgrade_invoice_rechecks_latest_host_status_before_applying(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host();
        $target = $this->product('Fresh Paid Upgrade VPS', 90);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()
            ->where('type', 'upgrade')
            ->whereHas('invoice', fn ($query) => $query->where('client_id', $host->client_id))
            ->value('invoice_id');
        app(PaymentService::class)->processPayment(
            \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId),
            'manual_pay',
            []
        );
        $host->update(['status' => 'Suspended']);

        $this->assertFalse(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 40.00,
            'status' => 'paid',
            'trans_id' => 'HOST-UPGRADE-SUSPENDED-001',
        ]));

        $this->assertSame('Unpaid', \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId)->status);
        $this->assertSame('Suspended', $host->fresh()->status);
        $this->assertNotSame($target->id, $host->fresh()->product_id);
        $this->assertDatabaseHas('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
            'status' => 'Pending',
        ]);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'HOST-UPGRADE-SUSPENDED-001']);
    }

    public function test_client_cannot_upgrade_to_current_product(): void
    {
        Mail::fake();
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $host->product_id])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseMissing('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $host->product_id,
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'upgrade_invoice_failed',
            'message' => '目标产品不能与当前产品相同',
        ]);
    }

    public function test_client_downgrade_completes_without_zero_amount_payment_dead_end(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Small VPS', 30);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect();

        $host->refresh();
        $this->assertSame($target->id, $host->product_id);
        $this->assertSame('30.00', (string) $host->recurring_amount);
        $this->assertDatabaseHas('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
            'type' => 'downgrade',
            'amount' => 0,
            'status' => 'Completed',
        ]);
        $this->assertDatabaseHas('invoices', [
            'client_id' => $host->client_id,
            'total' => 0,
            'status' => 'Paid',
            'payment_method' => 'no_payment_required',
        ]);
        $this->assertDatabaseHas('invoice_items', ['type' => 'downgrade']);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'downgrade_completed',
        ]);
    }

    public function test_client_cannot_upgrade_to_hidden_product(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Hidden VPS', 90);
        $target->update(['hidden' => true]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
        ]);
    }

    public function test_client_cannot_upgrade_to_product_without_valid_price_for_client_currency(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Unpriced VPS', 90);
        Pricing::query()
            ->where('type', 'product')
            ->where('rel_id', $target->id)
            ->where('currency_id', $host->client->currency_id)
            ->update(['monthly' => -1]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('product_id');

        $this->assertSame($host->product_id, $host->fresh()->product_id);
        $this->assertDatabaseMissing('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'upgrade_invoice_failed',
            'message' => '升级/降配目标未配置有效价格',
        ]);
    }

    public function test_client_cannot_upgrade_to_incompatible_product_type_or_server_module(): void
    {
        Mail::fake();
        $host = $this->host();
        $differentType = $this->product('Storage Package', 90);
        $differentType->update(['type' => 'storage']);
        $differentServer = $this->product('Other Server VPS', 90);
        $host->product->update(['server_type' => 'mock_server']);
        $differentServer->update(['server_type' => 'other_server']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $differentType->id])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('product_id');

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $differentServer->id])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseMissing('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $differentType->id,
        ]);
        $this->assertDatabaseMissing('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $differentServer->id,
        ]);
        $this->assertSame(2, \App\Models\HostActionLog::query()
            ->where('host_id', $host->id)
            ->where('action', 'upgrade_invoice_failed')
            ->where('message', '升级/降配目标产品与当前服务不兼容')
            ->count());
    }

    public function test_host_service_rechecks_latest_status_before_creating_upgrade_invoice(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Fresh Upgrade VPS', 90);
        $staleHost = $host->fresh(['client', 'product']);
        $host->update(['status' => 'Suspended']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('当前服务状态不允许升级/降配');

        try {
            app(\App\Modules\Order\Services\HostService::class)->createUpgradeInvoice($staleHost, $target);
        } finally {
            $this->assertDatabaseMissing('upgrades', [
                'host_id' => $host->id,
                'to_product_id' => $target->id,
            ]);
            $this->assertDatabaseHas('host_action_logs', [
                'host_id' => $host->id,
                'action' => 'upgrade_invoice_failed',
                'message' => '当前服务状态不允许升级/降配',
            ]);
        }
    }

    public function test_host_service_rechecks_latest_target_product_state_before_upgrade_invoice(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Stale Target VPS', 90);
        $staleTarget = $target->fresh();
        $target->update(['hidden' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('升级/降配目标产品不可购买');

        try {
            app(\App\Modules\Order\Services\HostService::class)->createUpgradeInvoice($host->fresh(['client', 'product']), $staleTarget);
        } finally {
            $this->assertDatabaseMissing('upgrades', [
                'host_id' => $host->id,
                'to_product_id' => $target->id,
            ]);
            $this->assertDatabaseHas('host_action_logs', [
                'host_id' => $host->id,
                'action' => 'upgrade_invoice_failed',
                'message' => '升级/降配目标产品不可购买',
            ]);
        }
    }

    public function test_client_cannot_create_duplicate_pending_upgrade_invoice(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Duplicate Pending VPS', 90);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('product_id');

        $this->assertSame(1, \App\Modules\Order\Models\Upgrade::query()
            ->where('host_id', $host->id)
            ->where('status', 'Pending')
            ->count());
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'upgrade_invoice_failed',
            'message' => '已有待处理的升级/降配账单',
        ]);
    }

    public function test_host_service_rejects_upgrade_invoice_for_inactive_client(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Inactive Upgrade VPS', 90);
        $host->client->update(['status' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('客户账号状态不允许升级/降配');

        app(\App\Modules\Order\Services\HostService::class)->createUpgradeInvoice($host->fresh(['client', 'product']), $target);
    }

    public function test_host_service_rejects_client_self_service_actions_for_inactive_client(): void
    {
        $host = $this->host(['status' => 'Active', 'auto_renew' => true]);
        $host->client->update(['status' => 2]);

        $this->assertFalse(app(\App\Modules\Order\Services\HostService::class)->reboot($host->fresh(['client', 'product'])));
        $this->assertFalse(app(\App\Modules\Order\Services\HostService::class)->cancelAutoRenew($host->fresh(['client', 'product'])));

        $this->assertTrue((bool) $host->fresh()->auto_renew);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'reboot_failed',
            'message' => '客户账号状态不允许重启服务',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'cancel_auto_renew_failed',
            'message' => '客户账号状态不允许取消自动续费',
        ]);
    }

    public function test_host_service_rejects_upgrade_invoice_for_non_active_host(): void
    {
        Mail::fake();
        $host = $this->host(['status' => 'Suspended']);
        $target = $this->product('Suspended Upgrade VPS', 90);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('当前服务状态不允许升级/降配');

        app(\App\Modules\Order\Services\HostService::class)->createUpgradeInvoice($host->fresh(['client', 'product']), $target);
    }

    public function test_host_service_rejects_unpurchasable_upgrade_target(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Hidden Service Target VPS', 90);
        $target->update(['hidden' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('升级/降配目标产品不可购买');

        app(\App\Modules\Order\Services\HostService::class)->createUpgradeInvoice($host->fresh(['client', 'product']), $target->fresh());
    }

    public function test_disallowed_action_does_not_change_host_state(): void
    {
        $host = $this->host(['status' => 'Terminated']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'reboot'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Terminated', $host->fresh()->status);
    }

    public function test_client_host_detail_only_shows_customer_self_service_actions(): void
    {
        $host = $this->host(['status' => 'Active']);

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertOk()
            ->assertSee('重启')
            ->assertSee('重置密码')
            ->assertSee('取消自动续费')
            ->assertDontSee('name="action" value="suspend"', false)
            ->assertDontSee('name="action" value="unsuspend"', false);
    }

    public function test_client_cannot_self_provision_unpaid_pending_host(): void
    {
        $host = $this->host(['status' => 'Pending'], ['status' => 'Pending']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'provision'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_client_host_detail_disables_provision_when_order_is_not_paid_even_if_invoice_is_paid(): void
    {
        $host = $this->host(['status' => 'Pending'], ['status' => 'Pending']);
        $invoice = \App\Modules\Finance\Models\Invoice::query()->create([
            'client_id' => $host->client_id,
            'invoice_number' => 'INV-HOST-PROVISION-MISMATCH',
            'subtotal' => 50,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 50,
            'status' => 'Paid',
            'due_date' => now(),
            'paid_at' => now(),
        ]);
        $host->order->update(['invoice_id' => $invoice->id]);

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.show', $host->fresh()))
            ->assertOk()
            ->assertSee('name="action" value="provision"', false)
            ->assertSee('disabled', false);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'provision'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_client_cannot_submit_backend_only_host_action(): void
    {
        $host = $this->host(['status' => 'Active']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'suspend'])
            ->assertSessionHasErrors('action');

        $this->assertSame('Active', $host->fresh()->status);
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'suspend_failed',
        ]);
    }

    public function test_admin_hosts_page_marks_and_filters_deleted_clients(): void
    {
        $host = $this->host(['status' => 'Active']);
        $host->client->delete();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.index', ['client_id' => $host->client_id]))
            ->assertOk()
            ->assertSee($host->fresh('client')->client?->username)
            ->assertSee('已删除');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee($host->fresh('client')->client?->username)
            ->assertSee('已删除');
    }

    private function host(array $overrides = [], array $orderOverrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Starter VPS', 50);
        $order = Order::query()->create(array_merge([
            'client_id' => $client->id,
            'order_number' => 'ORD-HOST-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ], $orderOverrides));

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'next_due_date' => now()->addMonth(),
            'next_invoice_date' => now()->addDays(23),
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides))->load(['client', 'product']);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'host-client-' . random_int(1000, 9999),
            'email' => 'host-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name, float $monthly): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '服务管理产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => $name,
            'description' => $name . ' 产品说明',
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => $monthly,
        ]);

        return $product;
    }

    private function installManualPay(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update(['config' => []]);
    }

    private function admin(): \App\Modules\Admin\Models\AdminUser
    {
        $admin = \App\Modules\Admin\Models\AdminUser::query()->create([
            'username' => 'host-admin-' . random_int(1000, 9999),
            'email' => 'host-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        \Spatie\Permission\Models\Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function installMockServer(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => $config]);
    }
}
