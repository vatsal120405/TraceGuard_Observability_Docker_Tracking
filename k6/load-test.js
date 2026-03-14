import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    stages: [
        { duration: '30s', target: 50 },  // Ramp-up to 50 users
        { duration: '1m', target: 50 },   // Stay at 50 users
        { duration: '30s', target: 100 }, // Spike to 100 users
        { duration: '1m', target: 100 },  // Stay at 100 users
        { duration: '30s', target: 0 },   // Ramp-down
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'], // http errors should be less than 1%
        http_req_duration: ['p(95)<500'], // 95% of requests should be below 500ms
    },
};

export default function () {
    // Normal traffic
    let res = http.get('http://localhost:8006/');
    check(res, { 'status was 200': (r) => r.status == 200 });
    sleep(1);

    // Occasional slow request
    if (__VU % 10 === 0) {
        http.get('http://localhost:8006/anomaly/delay?seconds=1');
    }

    // Occasional error
    if (__VU % 25 === 0) {
        http.get('http://localhost:8006/anomaly/error');
    }
}
