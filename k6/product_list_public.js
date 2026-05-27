import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, defaultParams, thresholds } from './config.js';

export const options = {
    stages: [{ duration: '10s', target: 20 }, { duration: '30s', target: 20 }, { duration: '10s', target: 0 }],
    thresholds,
};

export default function () {
    const res = http.get(`${BASE_URL}/products`, defaultParams);
    check(res, { 'status is 200': (r) => r.status === 200 });
    sleep(1);
}
