import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, thresholds } from './config.js';

export const options = { vus: 5, duration: '60s', thresholds };

const JSON_HEADERS = { 'Content-Type': 'application/json', 'Accept': 'application/json' };

export default function () {
    const loginRes = http.post(
        `${BASE_URL}/api/auth/login`,
        JSON.stringify({ email: __ENV.CLIENT_EMAIL || 'user@example.com', password: __ENV.CLIENT_PASSWORD || 'password' }),
        { headers: JSON_HEADERS },
    );
    const loginOk = check(loginRes, { 'login 200': (r) => r.status === 200 });
    if (!loginOk) { sleep(2); return; }

    let token;
    try { token = JSON.parse(loginRes.body).token; } catch { sleep(2); return; }

    const authHeaders = { ...JSON_HEADERS, 'Authorization': `Bearer ${token}` };

    const productsRes = http.get(`${BASE_URL}/api/products`, { headers: authHeaders });
    check(productsRes, { 'products 200': (r) => r.status === 200 });

    let productId;
    try {
        const products = JSON.parse(productsRes.body).data;
        if (!products || products.length === 0) { sleep(2); return; }
        productId = products[0].id;
    } catch { sleep(2); return; }

    const detailRes = http.get(`${BASE_URL}/api/products/${productId}`, { headers: authHeaders });
    check(detailRes, { 'product detail 200': (r) => r.status === 200 });

    sleep(2);
}
