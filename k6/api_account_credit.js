import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, API_TOKEN, thresholds } from './config.js';

export const options = { vus: 10, duration: '60s', thresholds };

export default function () {
    const res = http.get(`${BASE_URL}/api/account/credit`, {
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${API_TOKEN}` },
    });
    check(res, {
        'status is 200': (r) => r.status === 200,
        'has balance field': (r) => { try { return 'balance' in JSON.parse(r.body); } catch { return false; } },
    });
    sleep(1);
}
