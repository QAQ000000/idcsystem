import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, API_TOKEN, thresholds } from './config.js';

export const options = { vus: 20, duration: '60s', thresholds };

export default function () {
    const res = http.get(`${BASE_URL}/api/products`, {
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${API_TOKEN}` },
    });
    check(res, {
        'status is 200': (r) => r.status === 200,
        'response has data': (r) => { try { return Array.isArray(JSON.parse(r.body).data); } catch { return false; } },
    });
    sleep(1);
}
