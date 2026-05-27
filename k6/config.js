export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
export const API_TOKEN = __ENV.API_TOKEN || '';

export const defaultParams = {
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
};

export const thresholds = {
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    http_req_failed: ['rate<0.01'],
};
