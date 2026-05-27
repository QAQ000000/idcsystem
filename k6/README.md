# k6 Performance Tests

Load test scripts for core IDCSystem endpoints using [k6](https://k6.io/).

## Prerequisites

Install k6: https://k6.io/docs/getting-started/installation/

## Scripts

| Script | Description | VUs / Duration |
|--------|-------------|----------------|
| `admin_login.js` | Admin login endpoint | 10 VUs / 30s |
| `product_list_public.js` | Public product list page | Ramp 0→20→0 over 50s |
| `api_products.js` | API product list (Bearer token) | 20 VUs / 60s |
| `api_account_credit.js` | API account credit balance | 10 VUs / 60s |
| `e2e_order_flow.js` | End-to-end: login → products → detail | 5 VUs / 60s |

## Usage

```bash
# Basic run (against localhost)
k6 run k6/admin_login.js

# Override base URL and credentials
BASE_URL=https://your-domain.com ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD=secret k6 run k6/admin_login.js

# API tests require a valid token
BASE_URL=https://your-domain.com API_TOKEN=your-token k6 run k6/api_products.js

# E2E test with client credentials
BASE_URL=https://your-domain.com CLIENT_EMAIL=user@example.com CLIENT_PASSWORD=secret k6 run k6/e2e_order_flow.js
```

## Thresholds (shared via `config.js`)

- `p(95) < 500ms`
- `p(99) < 1000ms`
- `error rate < 1%`
