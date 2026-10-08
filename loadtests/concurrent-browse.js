import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { loginFilament } from './lib/filament-login.js';

const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const LOGIN_PATH = __ENV.LOGIN_PATH || '/login';

const users = new SharedArray('loadtest-users', () => {
  try {
    const raw = open('./.credentials.json');
    const parsed = JSON.parse(raw);

    return parsed.users || [];
  } catch (error) {
    return [];
  }
});

export const options = {
  vus: Number(__ENV.VUS || 20),
  duration: __ENV.DURATION || '3m',
  thresholds: {
    http_req_failed: ['rate<0.05'],
    // Local/dev hosts are often slower than 2s under Filament login load; use 20s here.
    // For stronger UAT/prod boxes, tighten with: -e DURATION_P95_MS=2000 (see README).
    http_req_duration: [`p(95)<${Number(__ENV.DURATION_P95_MS || 20000)}`],
  },
};

export function setup() {
  if (users.length === 0) {
    throw new Error('No users in loadtests/.credentials.json. Run: php artisan loadtest:prepare');
  }
}

export default function () {
  // Use SharedArray directly — do not pass it through setup() (JSON serialization breaks it).
  const user = users[(__VU - 1) % users.length];

  const login = loginFilament(BASE_URL, LOGIN_PATH, user.email, user.password);

  check(login, {
    'filament login established session': (result) => result.ok === true,
  });

  if (!login.ok) {
    sleep(2);

    return;
  }

  const paths = ['/', '/requisitions'];

  for (const path of paths) {
    const res = http.get(`${BASE_URL}${path}`, {
      jar: login.jar,
      redirects: 5,
    });

    check(res, {
      [`GET ${path} is not 5xx`]: (r) => r.status < 500,
      [`GET ${path} is authenticated-ish`]: (r) => r.status === 200 || r.status === 302 || r.status === 403,
    });

    sleep(1);
  }

  sleep(2);
}
