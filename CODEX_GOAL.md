# IDC 云产品销售系统 - Codex Goal 开发文档

## 项目概述

这是一个基于 Laravel 13 的云产品销售系统（类似 ZJMF 财务系统），采用模块化架构 + 插件系统设计。

**项目路径**: `/c/wwwroot/mf5/idcsystem`
**数据库**: `idcsystem` (MySQL 8.0, 用户: idcuser, 密码: idc@2024Secure)
**技术栈**: Laravel 13, PHP 8.4, MySQL 8.0, Redis, Blade 模板

## 已完成的工作

### ✅ 数据库架构（100%）
- 32 张核心表已创建并迁移成功
- 包含：用户、产品、订单、财务、工单、插件系统等完整表结构

### ✅ 模型层（100%）
所有 Eloquent 模型已创建，位于 `app/Modules/*/Models/`：

**用户域** (`app/Modules/User/Models/`)
- Client.php - 客户模型（含余额、信用额、认证）
- ClientGroup.php - 客户分组
- Contact.php - 联系人/子账户
- ClientOauth.php - 第三方登录关联

**管理域** (`app/Modules/Admin/Models/`)
- AdminUser.php - 管理员（使用 Spatie Permission）

**产品域** (`app/Modules/Product/Models/`)
- Product.php - 产品
- ProductGroup.php - 产品分组
- Pricing.php - 统一价格表（多货币多周期）

**订单域** (`app/Modules/Order/Models/`)
- Order.php - 订单
- Host.php - 产品实例（用户购买的服务）

**财务域** (`app/Modules/Finance/Models/`)
- Invoice.php - 账单
- InvoiceItem.php - 账单项目
- Account.php - 交易流水
- Credit.php - 余额变动日志
- Currency.php - 货币

**工单域** (`app/Modules/Ticket/Models/`)
- Ticket.php - 工单
- TicketReply.php - 工单回复
- TicketDepartment.php - 工单部门
- TicketStatus.php - 工单状态

### ✅ 插件系统核心（100%）
- 7 个插件接口契约（`app/Plugins/Contracts/`）
- AbstractPlugin 基类
- PluginManager 管理器
- Plugin 模型和 Facade

### ✅ 部分服务层
- ClientService.php - 客户管理服务
- AuthService.php - 认证服务

## 需要完成的工作（Codex Goal 任务）

### 🎯 目标 1：完成所有服务层（Services）

创建以下服务类，每个服务应包含完整的业务逻辑：

#### 产品域服务 (`app/Modules/Product/Services/`)

**ProductService.php**
```php
- create(array $data): Product  // 创建产品
- update(Product $product, array $data): bool
- delete(Product $product): bool
- checkStock(Product $product): bool  // 检查库存
- decrementStock(Product $product, int $qty = 1): bool
- getAvailableProducts(): Collection  // 获取可用产品列表
```

**PricingService.php**
```php
- setPricing(string $type, int $relId, int $currencyId, array $prices): Pricing
- getPricing(string $type, int $relId, int $currencyId): ?Pricing
- calculatePrice(Product $product, string $billingCycle, array $configOptions = []): float
```

#### 订单域服务 (`app/Modules/Order/Services/`)

**OrderService.php**
```php
- create(Client $client, array $items): Order  // 创建订单
- calculateTotal(array $items, ?string $promoCode = null): array
- markAsPaid(Order $order, string $paymentMethod, string $transId): bool
- cancel(Order $order, string $reason): bool
```

**HostService.php**
```php
- create(Order $order, Product $product, array $config): Host
- provision(Host $host): bool  // 开通服务（调用服务器模块插件）
- suspend(Host $host, string $reason): bool
- unsuspend(Host $host): bool
- terminate(Host $host): bool
- renew(Host $host, string $billingCycle): Invoice
```

**CartService.php**
```php
- add(Client $client, Product $product, array $config): array
- remove(Client $client, int $itemId): bool
- getCart(Client $client): array
- clear(Client $client): bool
- checkout(Client $client): Order
```

#### 财务域服务 (`app/Modules/Finance/Services/`)

**InvoiceService.php**
```php
- generate(Client $client, array $items): Invoice  // 生成账单
- addItem(Invoice $invoice, string $type, string $description, float $amount): InvoiceItem
- calculateTax(Invoice $invoice): float
- markAsPaid(Invoice $invoice, string $paymentMethod, string $transId): bool
- refund(Invoice $invoice, float $amount): bool
```

**PaymentService.php**
```php
- processPayment(Invoice $invoice, string $gateway, array $params): array
- handleCallback(string $gateway, array $data): bool  // 处理支付回调
- refund(Account $account, float $amount): bool
```

**BillingService.php**
```php
- generateRecurringInvoices(): int  // 生成续费账单（定时任务）
- sendDueReminders(): int  // 发送到期提醒
- suspendOverdueHosts(): int  // 暂停逾期服务
```

#### 工单域服务 (`app/Modules/Ticket/Services/`)

**TicketService.php**
```php
- create(Client $client, int $departmentId, string $subject, string $message): Ticket
- reply(Ticket $ticket, string $authorType, int $authorId, string $message): TicketReply
- changeStatus(Ticket $ticket, int $statusId): bool
- assign(Ticket $ticket, int $adminId): bool
- close(Ticket $ticket): bool
- rate(Ticket $ticket, int $rating): bool
```

### 🎯 目标 2：创建控制器和路由

#### 后台管理控制器 (`app/Http/Controllers/Admin/`)

**DashboardController.php**
- index() - 仪表盘（统计数据）

**ClientController.php**
- index() - 客户列表
- show($id) - 客户详情
- store() - 创建客户
- update($id) - 更新客户
- destroy($id) - 删除客户
- addCredit($id) - 充值余额

**ProductController.php**
- index() - 产品列表
- create() - 创建产品表单
- store() - 保存产品
- edit($id) - 编辑产品表单
- update($id) - 更新产品
- destroy($id) - 删除产品
- pricing($id) - 价格配置

**OrderController.php**
- index() - 订单列表
- show($id) - 订单详情
- approve($id) - 审核订单
- cancel($id) - 取消订单

**InvoiceController.php**
- index() - 账单列表
- show($id) - 账单详情
- markPaid($id) - 标记已支付
- refund($id) - 退款

**TicketController.php**
- index() - 工单列表
- show($id) - 工单详情
- reply($id) - 回复工单
- assign($id) - 分配工单
- close($id) - 关闭工单

**PluginController.php**
- index() - 插件列表
- install() - 安装插件
- uninstall($name) - 卸载插件
- enable($name) - 启用插件
- disable($name) - 禁用插件
- config($name) - 插件配置

#### 前台客户控制器 (`app/Http/Controllers/Client/`)

**AuthController.php**
- showLoginForm() - 登录页面
- login() - 登录处理
- showRegisterForm() - 注册页面
- register() - 注册处理
- logout() - 退出登录

**DashboardController.php**
- index() - 客户仪表盘

**ProductController.php**
- index() - 产品列表
- show($id) - 产品详情

**CartController.php**
- index() - 购物车
- add() - 添加到购物车
- remove($id) - 移除
- checkout() - 结算

**HostController.php**
- index() - 我的服务列表
- show($id) - 服务详情
- renew($id) - 续费

**InvoiceController.php**
- index() - 我的账单
- show($id) - 账单详情
- pay($id) - 支付账单

**TicketController.php**
- index() - 我的工单
- create() - 创建工单
- show($id) - 工单详情
- reply($id) - 回复工单

#### 路由配置

**routes/admin.php** - 后台路由（需要管理员认证）
```php
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::resource('clients', ClientController::class);
    Route::resource('products', ProductController::class);
    Route::resource('orders', OrderController::class);
    Route::resource('invoices', InvoiceController::class);
    Route::resource('tickets', TicketController::class);
    Route::resource('plugins', PluginController::class);
});
```

**routes/web.php** - 前台路由
```php
Route::get('/', [HomeController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::resource('hosts', HostController::class);
    Route::resource('invoices', InvoiceController::class);
    Route::resource('tickets', TicketController::class);
    Route::get('/cart', [CartController::class, 'index']);
});
```

### 🎯 目标 3：创建 Blade 视图模板

使用 Tailwind CSS + Alpine.js 创建视图，不做前后端分离。

#### 布局模板 (`resources/views/layouts/`)

**admin.blade.php** - 后台布局
- 顶部导航栏（LOGO、用户菜单）
- 左侧边栏（导航菜单）
- 主内容区域
- 引入 Tailwind CSS CDN 和 Alpine.js

**client.blade.php** - 前台布局
- 顶部导航栏
- 主内容区域
- 底部版权信息

#### 后台视图 (`resources/views/admin/`)

**dashboard.blade.php** - 仪表盘
- 统计卡片（客户数、订单数、收入、工单数）
- 最近订单列表
- 最近工单列表

**clients/index.blade.php** - 客户列表
- 搜索框
- 数据表格（用户名、邮箱、状态、余额、操作）
- 分页

**clients/show.blade.php** - 客户详情
- 基本信息
- 服务列表
- 账单列表
- 工单列表

**products/index.blade.php** - 产品列表
**products/create.blade.php** - 创建产品
**products/edit.blade.php** - 编辑产品

**orders/index.blade.php** - 订单列表
**orders/show.blade.php** - 订单详情

**invoices/index.blade.php** - 账单列表
**invoices/show.blade.php** - 账单详情

**tickets/index.blade.php** - 工单列表
**tickets/show.blade.php** - 工单详情

**plugins/index.blade.php** - 插件管理

#### 前台视图 (`resources/views/client/`)

**auth/login.blade.php** - 登录页面
**auth/register.blade.php** - 注册页面

**dashboard.blade.php** - 客户仪表盘
- 服务概览
- 待支付账单
- 最近工单

**products/index.blade.php** - 产品列表（展示可购买的产品）
**products/show.blade.php** - 产品详情（配置选项、价格、购买按钮）

**cart/index.blade.php** - 购物车
**cart/checkout.blade.php** - 结算页面

**hosts/index.blade.php** - 我的服务
**hosts/show.blade.php** - 服务详情（域名、用户名、密码、到期时间、续费按钮）

**invoices/index.blade.php** - 我的账单
**invoices/show.blade.php** - 账单详情（支付按钮）

**tickets/index.blade.php** - 我的工单
**tickets/create.blade.php** - 创建工单
**tickets/show.blade.php** - 工单详情（回复列表、回复表单）

### 🎯 目标 4：创建数据填充（Seeders）

#### DatabaseSeeder.php
```php
public function run(): void
{
    $this->call([
        CurrencySeeder::class,
        TicketStatusSeeder::class,
        TicketDepartmentSeeder::class,
        AdminUserSeeder::class,
        ClientGroupSeeder::class,
        DemoDataSeeder::class,  // 可选：演示数据
    ]);
}
```

#### CurrencySeeder.php
创建默认货币：
- CNY (人民币, ¥, 1.0000, 默认)
- USD (美元, $, 7.2000)
- EUR (欧元, €, 7.8000)

#### TicketStatusSeeder.php
创建工单状态：
- Open (打开, #28a745, 默认)
- Answered (已回复, #007bff)
- Customer Reply (客户回复, #ffc107)
- Closed (已关闭, #6c757d)

#### TicketDepartmentSeeder.php
创建工单部门：
- 技术支持
- 财务部门
- 销售咨询

#### AdminUserSeeder.php
创建默认管理员：
- 用户名: admin
- 邮箱: admin@example.com
- 密码: admin123456
- 分配超级管理员角色

#### ClientGroupSeeder.php
创建客户分组：
- 普通客户 (0% 折扣)
- VIP客户 (5% 折扣)
- 企业客户 (10% 折扣)

#### DemoDataSeeder.php（可选）
创建演示数据：
- 2-3 个产品分组
- 5-10 个产品（VPS、虚拟主机等）
- 每个产品的价格配置
- 2-3 个测试客户

### 🎯 目标 5：创建中间件

#### CheckClientStatus.php (`app/Http/Middleware/`)
检查客户状态（是否激活、是否被暂停）

#### CheckAdminPermission.php
检查管理员权限（使用 Spatie Permission）

### 🎯 目标 6：创建表单验证请求类

#### `app/Http/Requests/Admin/`
- StoreProductRequest.php
- UpdateProductRequest.php
- StoreClientRequest.php
- UpdateClientRequest.php

#### `app/Http/Requests/Client/`
- RegisterRequest.php
- LoginRequest.php
- CreateTicketRequest.php
- CheckoutRequest.php

### 🎯 目标 7：创建 API 资源类（可选）

如果需要 API 接口，创建资源类：
- ClientResource.php
- ProductResource.php
- OrderResource.php
- InvoiceResource.php
- TicketResource.php

## 开发规范

### 代码风格
- 遵循 PSR-12 编码规范
- 使用 PHP 8.4 类型提示（参数类型、返回类型）
- 所有公共方法必须有 PHPDoc 注释
- 使用依赖注入，不要在控制器中直接 new 对象

### 命名规范
- 控制器：单数名词 + Controller（ProductController）
- 模型：单数名词（Product）
- 服务：单数名词 + Service（ProductService）
- 视图：复数目录/动作名（products/index.blade.php）

### 数据库事务
涉及多表操作的方法必须使用数据库事务：
```php
DB::transaction(function () {
    // 操作
});
```

### 错误处理
- 使用 try-catch 捕获异常
- 返回统一的 JSON 格式（API）
- 使用 session flash 消息（Web）

### 安全
- 所有用户输入必须验证
- 使用 Laravel 的 CSRF 保护
- 密码使用 Hash::make() 加密
- SQL 使用 Eloquent ORM 防止注入

## 测试验证

完成后需要验证：
1. 运行 `php artisan serve` 启动服务器
2. 访问 `/admin` 后台登录（admin / admin123456）
3. 测试创建产品、客户、订单流程
4. 访问前台注册、登录、购买流程
5. 测试工单创建和回复
6. 测试插件安装和配置

## 参考资料

- Laravel 13 文档: https://laravel.com/docs/13.x
- Tailwind CSS: https://tailwindcss.com/docs
- Alpine.js: https://alpinejs.dev/
- Spatie Permission: https://spatie.be/docs/laravel-permission

## 注意事项

1. 所有文件使用 UTF-8 编码
2. 视图使用 Blade 模板引擎，不做前后端分离
3. 使用 Tailwind CSS CDN，无需 npm 构建
4. Redis 已配置，可用于缓存和队列
5. 数据库连接信息在 `.env` 文件中
6. 插件目录为 `plugins/`，按类型分类

---

**预计完成时间**: 4-6 小时
**优先级**: Services > Controllers > Views > Seeders