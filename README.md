# IDCSystem - 云产品销售管理系统

<div align="center">

![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql)
![Redis](https://img.shields.io/badge/Redis-7.x-DC382D?style=flat-square&logo=redis)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

一个功能完整、生产就绪的云产品销售管理系统（类似 WHMCS/ZJMF），基于 Laravel 13 构建。

[功能特性](#功能特性) • [技术栈](#技术栈) • [快速开始](#快速开始) • [文档](#文档) • [测试](#测试)

</div>

---

## 项目简介

IDCSystem 是一个现代化的云产品销售管理系统，专为 IDC/云服务提供商设计。系统采用模块化架构，支持插件扩展，提供完整的产品销售、订单管理、账单支付、工单系统、客户管理等功能。

**核心优势**：
- ✅ **生产就绪**：616 个测试用例，3127 个断言，测试覆盖率高
- 🚀 **现代技术栈**：Laravel 13 + PHP 8.3 + MySQL 8.0 + Redis
- 🧩 **模块化设计**：7 个核心业务模块，松耦合高内聚
- 🔌 **插件系统**：支付网关、服务器模块、邮件/短信、OAuth 等可插拔
- 🌍 **国际化**：多语言、多货币、区域税率支持
- 📊 **数据驱动**：完整的报表、统计、审计日志
- 🔒 **安全可靠**：2FA、RBAC、API 限流、GDPR 合规

---

## 功能特性

### 核心业务模块

#### 👥 用户管理
- 客户注册/登录（支持邮箱/手机号）
- 双因素认证（2FA）
- 客户分组与折扣
- 客户标签系统（VIP/高价值/风险）
- 客户信用评分与等级
- 推介计划（分销系统）
- 客户活动日志
- 第三方登录（微信 OAuth）

#### 📦 产品管理
- 产品分组（支持二级分类）
- 灵活的定价系统（多周期/多货币）
- 配置选项（下拉/单选/多选/文本）
- 产品附加项（Addons）
- 自定义字段
- 库存管理与预警
- 服务器组与服务器管理

#### 🛒 订单与账单
- 购物车系统
- 订单管理（状态机）
- 账单生成与支付
- 多支付网关（支付宝/微信/易支付）
- 余额支付与充值
- 信用额度管理
- 优惠码系统
- 发票申请
- 退款管理
- 区域税率规则

#### 🖥️ 服务管理
- 产品实例（Hosts）生命周期管理
- 服务暂停/恢复/终止
- 服务升级/降级
- 取消申请流程
- 服务用量监控与告警
- 域名管理（注册/续费/DNS）
- SSL 证书管理（付费/Let's Encrypt）

#### 🎫 工单系统
- 工单创建与回复
- 工单部门与状态管理
- 工单附件上传
- 工单 SLA 管理
- 工单自动分配（轮询/负载均衡）
- 预设回复模板

#### 📊 财务管理
- 交易流水记录
- 余额变动日志
- 财务对账系统
- 收支报表
- 多货币支持
- 合同管理

#### 🔔 通知系统
- 邮件通知（SMTP/插件）
- 短信通知（阿里云/插件）
- 站内消息中心
- 通知模板管理
- 客户通知偏好
- 批量邮件营销

### 高级功能

#### 🛠️ 后台管理
- 管理员与角色权限（RBAC）
- 管理员 2FA
- 系统设置（Key-Value）
- 邮件/短信模板管理
- 优惠码管理
- 公告系统
- 数据导出（CSV/Excel）
- 批量操作
- 批量导入工具

#### 📈 报表与统计
- 管理员仪表盘
- 收入趋势报表
- 客户增长报表
- 服务状态分布
- 产品销售排行
- SLA 统计报表
- 推介排行榜

#### 🔌 插件系统
- 支付网关插件（支付宝/微信/易支付）
- 服务器模块插件（cPanel/自定义）
- 邮件服务插件（SMTP）
- 短信服务插件（阿里云）
- OAuth 插件（微信登录）
- 验证码插件（图形验证码）
- 实名认证插件

#### 🔐 安全与合规
- 登录安全加固（失败锁定/异常提醒）
- API 限流与 CORS
- 审计日志
- GDPR 合规（数据导出/删除）
- 隐私政策同意记录
- API Token 权限管理

#### 🌐 国际化
- 多语言支持（中文/英文）
- 多货币支持
- 区域税率规则
- 语言切换器

#### 🔗 API 与集成
- RESTful API（Sanctum 认证）
- API 文档（Scramble/OpenAPI）
- Webhooks 系统
- API 支付接口

#### 🗄️ 运维工具
- 知识库/FAQ 系统
- 备份恢复系统（数据库/文件）
- 日志管理与清理
- 队列监控（Horizon）
- 健康检查端点

---

## 技术栈

### 后端
- **框架**：Laravel 13.x
- **语言**：PHP 8.3+
- **数据库**：MySQL 8.0
- **缓存/队列**：Redis 7.x
- **队列监控**：Laravel Horizon
- **认证**：Laravel Sanctum
- **权限**：Spatie Laravel Permission

### 前端
- **模板引擎**：Blade
- **CSS 框架**：Tailwind CSS（CDN）
- **JavaScript**：Alpine.js（CDN）
- **图表**：Chart.js（CDN）
- **无需构建**：纯 CDN 方案，开箱即用

### 开发工具
- **测试**：PHPUnit 12.x
- **代码风格**：Laravel Pint
- **API 文档**：Scramble
- **Excel 处理**：PhpSpreadsheet

---

## 项目结构

```
idcsystem/
├── app/
│   ├── Modules/                     # 核心业务模块
│   │   ├── User/                    # 用户域（客户/管理员/推介）
│   │   ├── Product/                 # 产品域（产品/定价/域名/SSL）
│   │   ├── Order/                   # 订单域（订单/购物车/服务）
│   │   ├── Finance/                 # 财务域（账单/支付/退款）
│   │   ├── Ticket/                  # 工单域（工单/SLA）
│   │   ├── Support/                 # 支持域（知识库）
│   │   └── Admin/                   # 后台管理域
│   │
│   ├── Plugins/                     # 插件核心框架
│   │   ├── Contracts/               # 插件接口
│   │   └── Core/                    # 插件管理器
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/               # 后台控制器
│   │   │   ├── Client/              # 前台控制器
│   │   │   └── Api/                 # API 控制器
│   │   └── Middleware/
│   │
│   └── Services/                    # 全局服务
│
├── plugins/                         # 插件目录
│   ├── Gateway/                     # 支付网关插件
│   ├── Server/                      # 服务器模块插件
│   ├── OAuth/                       # OAuth 插件
│   ├── Sms/                         # 短信插件
│   └── Email/                       # 邮件插件
│
├── database/
│   ├── migrations/                  # 76 个数据库迁移
│   └── seeders/                     # 初始数据填充
│
├── resources/
│   ├── views/
│   │   ├── admin/                   # 后台视图
│   │   ├── client/                  # 前台视图
│   │   └── emails/                  # 邮件模板
│   └── lang/                        # 多语言文件
│
├── routes/
│   ├── web.php                      # Web 路由
│   ├── admin.php                    # 后台路由
│   ├── api.php                      # API 路由
│   └── console.php                  # 定时任务
│
└── tests/                           # 616 个测试用例
    ├── Feature/                     # 功能测试
    └── Unit/                        # 单元测试
```

---

## 快速开始

### 环境要求

- PHP >= 8.3
- MySQL >= 8.0
- Redis >= 7.0
- Composer
- Node.js >= 18.x（可选，仅用于开发）

### 安装步骤

1. **克隆项目**

```bash
git clone https://github.com/yourusername/idcsystem.git
cd idcsystem
```

2. **安装依赖**

```bash
composer install
```

3. **配置环境**

```bash
cp .env.example .env
php artisan key:generate
```

编辑 `.env` 文件，配置数据库和 Redis：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=idcsystem
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

4. **运行迁移**

```bash
php artisan migrate --seed
```

5. **启动服务**

```bash
# 开发环境（包含队列、日志监控）
composer dev

# 或分别启动
php artisan serve
php artisan queue:work
php artisan horizon
```

6. **访问系统**

- 前台：http://localhost:8000
- 后台：http://localhost:8000/admin
- API 文档：http://localhost:8000/docs/api
- Horizon：http://localhost:8000/horizon

**默认管理员账号**：
- 用户名：admin
- 密码：password

---

## 测试

系统包含 616 个测试用例，覆盖所有核心功能。

```bash
# 运行所有测试
php artisan test

# 运行特定测试
php artisan test --filter=ProductTest

# 生成测试覆盖率报告
php artisan test --coverage
```

**测试统计**：
- 测试用例：616 tests
- 断言数量：3127 assertions
- 测试通过率：100%

---

## 配置

### 核心配置

系统配置文件位于 `config/` 目录：

- `config/idcsystem.php` - 系统核心配置
- `config/billing.php` - 账单配置（税率/宽限期/提醒策略）
- `config/ticket.php` - 工单配置（自动分配）
- `config/backup.php` - 备份配置（保留天数/云存储）
- `config/logging.php` - 日志配置（保留策略）

### 定时任务

系统依赖 Laravel Scheduler 执行定时任务。在服务器上添加一条 cron 记录：

```bash
* * * * * cd /path/to/idcsystem && php artisan schedule:run >> /dev/null 2>&1
```

**定时任务列表**：

```php
// 账单相关
billing:generate-recurring-invoices    // 每天生成续费账单
billing:send-due-reminders             // 每天发送到期提醒
billing:suspend-overdue-hosts          // 每天暂停逾期服务

// 域名与 SSL
domains:send-expiry-reminders          // 每天发送域名到期提醒
domains:auto-renew                     // 每天检查自动续费
ssl:auto-renew-letsencrypt            // 每天续签 Let's Encrypt 证书
ssl:send-expiry-reminders             // 每天发送证书到期提醒

// 工单
tickets:check-sla-breaches            // 每 15 分钟检查 SLA 超时

// 用量告警
usage:check-alerts                    // 每小时检查用量告警

// 邮件营销
campaigns:send-scheduled              // 每分钟发送已安排的邮件活动

// 取消申请
cancel:process-approved               // 每天处理已批准的取消申请

// 备份与日志
backup:database                       // 每天凌晨 2 点备份数据库
backup:files                          // 每周日凌晨 3 点备份文件
backup:cleanup                        // 每天凌晨 4 点清理过期备份
logs:cleanup                          // 每天凌晨 1 点清理过期日志

// 库存
stock:check-alerts                    // 每小时检查库存预警

// 财务
financial:generate-monthly-statement  // 每月 1 号生成财务报表

// 信用评分
credit:recalculate-scores             // 每月 1 号重新计算信用分
```

---

## 部署

### 生产环境部署

1. **优化自动加载**

```bash
composer install --optimize-autoloader --no-dev
```

2. **缓存配置**

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

3. **运行迁移**

```bash
php artisan migrate --force
```

4. **配置队列**

使用 Supervisor 管理队列进程：

```ini
[program:idcsystem-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/idcsystem/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/idcsystem/storage/logs/queue.log
stopwaitsecs=3600
```

5. **配置 Web 服务器**

Nginx 配置示例：

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/idcsystem/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 开发文档

完整的开发文档位于项目根目录：

- [第一阶段：项目初始化与基础架构](../IDCSystem第一阶段开发任务文档.md)
- [第二阶段：核心业务模块](../IDCSystem第二阶段开发任务文档.md)
- [第三阶段：支付与通知系统](../IDCSystem第三阶段开发任务文档.md)
- [第四阶段：高级功能](../IDCSystem第四阶段开发任务文档.md)
- [第五阶段：生产可用性补完](../IDCSystem第五阶段开发任务文档.md)
- [第六阶段：客户自助与系统扩展](../IDCSystem第六阶段开发任务文档.md)
- [第七阶段：生产成熟度与合规性](../IDCSystem第七阶段开发任务文档.md)
- [第八阶段：运营效率与精细化管理](../IDCSystem第八阶段开发任务文档.md)

### API 文档

启动项目后访问 `/docs/api` 查看自动生成的 OpenAPI 文档。

### 插件开发

参考 `plugins/` 目录下的示例插件：
- 支付网关：`plugins/Gateway/EpayAlipay/`
- 服务器模块：`plugins/Server/MockServer/`

---

## 项目统计

- **代码行数**：~20,000 行 PHP 代码
- **数据库表**：76 张表
- **测试用例**：616 tests / 3127 assertions
- **提交记录**：75 commits
- **开发周期**：8 个阶段
- **核心模块**：7 个业务域
- **插件数量**：10+ 个内置插件

---

## 贡献

欢迎贡献代码、报告问题或提出建议！

1. Fork 本仓库
2. 创建特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 开启 Pull Request

### 代码规范

项目使用 Laravel Pint 进行代码格式化：

```bash
./vendor/bin/pint
```

---

## 许可证

本项目采用 MIT 许可证。详见 [LICENSE](LICENSE) 文件。

---

## 致谢

- [Laravel](https://laravel.com) - 优雅的 PHP 框架
- [Tailwind CSS](https://tailwindcss.com) - 实用优先的 CSS 框架
- [Alpine.js](https://alpinejs.dev) - 轻量级 JavaScript 框架
- [Chart.js](https://www.chartjs.org) - 简单灵活的图表库

---

## 联系方式

- 项目主页：https://github.com/yourusername/idcsystem
- 问题反馈：https://github.com/yourusername/idcsystem/issues

---

<div align="center">

**⭐ 如果这个项目对你有帮助，请给个 Star！**

Made with ❤️ by IDCSystem Team

</div>
