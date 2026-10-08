import http from 'k6/http';
import { check } from 'k6';

function firstMatch(body, patterns) {
  for (const pattern of patterns) {
    const match = body.match(pattern);
    if (match && match[1]) {
      return match[1];
    }
  }

  return null;
}

function decodeHtmlEntities(value) {
  return value
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>');
}

function extractCsrf(body) {
  return firstMatch(body, [
    /name="csrf-token"\s+content="([^"]+)"/i,
    /data-csrf="([^"]+)"/i,
    /name="_token"\s+value="([^"]+)"/i,
  ]);
}

/**
 * Livewire v4 serves updates at /livewire-{hash}/update (from data-update-uri).
 * Prefer that over the legacy /livewire/update path.
 */
function extractUpdateUri(body, baseUrl) {
  const absolute = firstMatch(body, [/data-update-uri="([^"]+)"/i]);
  if (absolute) {
    return absolute;
  }

  const relative = firstMatch(body, [/data-update-uri='([^']+)'/i]);
  if (relative) {
    if (relative.startsWith('http://') || relative.startsWith('https://')) {
      return relative;
    }

    return `${baseUrl}${relative.startsWith('/') ? '' : '/'}${relative}`;
  }

  return `${baseUrl}/livewire/update`;
}

/**
 * Prefer the Filament login component snapshot (has data.email), not notifications.
 */
function extractLoginSnapshot(body) {
  const matches = body.matchAll(/wire:snapshot="([^"]+)"/gi);

  for (const match of matches) {
    const raw = match[1] || '';
    if (raw.includes('&quot;email&quot;') || raw.includes('"email"')) {
      return raw;
    }
  }

  return firstMatch(body, [
    /wire:snapshot="([^"]+)"/i,
    /wire:snapshot='([^']+)'/i,
  ]);
}

function xsrfTokenFromJar(jar, url) {
  try {
    const cookies = jar.cookiesForURL(url) || {};
    const raw = cookies['XSRF-TOKEN'];
    if (!raw) {
      return null;
    }

    const value = Array.isArray(raw) ? raw[0] : raw;

    return decodeURIComponent(value);
  } catch (error) {
    return null;
  }
}

/**
 * Attempt a Filament/Livewire login.
 * Filament markup changes across versions — treat failures as script issues first.
 */
export function loginFilament(baseUrl, loginPath, email, password) {
  const jar = http.cookieJar();
  const loginUrl = `${baseUrl}${loginPath}`;

  const page = http.get(loginUrl, {
    jar,
    headers: {
      Accept: 'text/html,application/xhtml+xml',
    },
  });
  check(page, {
    'login page loaded': (r) => r.status === 200,
  });

  const csrf = extractCsrf(page.body || '');
  const snapshotRaw = extractLoginSnapshot(page.body || '');
  const updateUri = extractUpdateUri(page.body || '', baseUrl);

  if (!csrf || !snapshotRaw) {
    return {
      ok: false,
      jar,
      reason: 'Could not parse CSRF/Livewire markers from /login. Filament markup may have changed.',
      status: page.status,
    };
  }

  let snapshot;
  try {
    snapshot = JSON.parse(decodeHtmlEntities(snapshotRaw));
  } catch (error) {
    return {
      ok: false,
      jar,
      reason: `Livewire snapshot JSON parse failed: ${error}`,
    };
  }

  const payload = {
    _token: csrf,
    components: [
      {
        snapshot: JSON.stringify(snapshot),
        updates: {
          'data.email': email,
          'data.password': password,
          'data.remember': false,
        },
        calls: [
          {
            path: '',
            method: 'authenticate',
            params: [],
          },
        ],
      },
    ],
  };

  const xsrf = xsrfTokenFromJar(jar, loginUrl);
  const headers = {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrf,
    'X-Livewire': 'true',
    Accept: 'application/json',
    Referer: loginUrl,
    Origin: baseUrl,
    'X-Requested-With': 'XMLHttpRequest',
  };

  if (xsrf) {
    headers['X-XSRF-TOKEN'] = xsrf;
  }

  const response = http.post(updateUri, JSON.stringify(payload), {
    jar,
    headers,
  });

  const ok = check(response, {
    'login livewire call succeeded': (r) => r.status === 200 || r.status === 302,
  });

  // Follow-up authenticated GET helps verify the session cookie stuck.
  const dashboard = http.get(`${baseUrl}/`, { jar, redirects: 0 });
  const authenticated = dashboard.status === 200 || dashboard.status === 302;

  return {
    ok: ok && authenticated && dashboard.status !== 401 && dashboard.status !== 419,
    jar,
    status: response.status,
    dashboardStatus: dashboard.status,
    updateUri,
    reason: ok ? null : `Livewire update returned HTTP ${response.status}`,
  };
}
