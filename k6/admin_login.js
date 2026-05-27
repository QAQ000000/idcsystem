import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, defaultParams, thresholds } from './config.js';

export const options = { vus: 10, duration: '30s', thresholds };

export default function () {
    const res = http.post(
        `${BASE_URL}/admin/login`,
        JSON.stringify({ email: __ENV.ADMIN_EMAIL || 'admin@example.com', password: __ENV.ADMIN_PASSWORD || 'password' }),
        defaultParams,
    );
    check(res, { 'status is 200 or 302': (r) => r.status === 200 || r.status === 302 });
    sleep(1);
}
