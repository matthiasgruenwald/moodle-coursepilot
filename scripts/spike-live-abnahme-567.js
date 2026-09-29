#!/usr/bin/env node
// Live-Abnahme des HTTP-Wegs gegen die Spike-Instanz (#567, Luecke aus #578):
// Discovery, DCR, Moodle-Login, Zustimmungsdialog, Token-Endpunkt
// (Einmaligkeit, gleichzeitige Refresh-Rotation), MCP ueber HTTPS und
// Widerruf. Schreibt keine Kursdaten; die angelegte Verbindung wird am Ende
// widerrufen, der registrierte OAuth-Client bleibt wie bei jedem Client stehen.
//
//   export TEACHER_USERNAME=... TEACHER_PASSWORD=...   (nie ausgeben)
//   node scripts/spike-live-abnahme-567.js
'use strict';
const crypto = require('node:crypto');

const BASE = 'https://spike.gruenwald.fun';
const USER = process.env.TEACHER_USERNAME;
const PASS = process.env.TEACHER_PASSWORD;
const REDIRECT = 'http://127.0.0.1:53682/callback';
const CLIENTNAME = 'Coursepilot Live-Abnahme 567';
const jar = new Map();
const results = [];

function check(name, ok, detail = '') {
  results.push({ name, ok });
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`);
}
function storeCookies(res) {
  for (const c of res.headers.getSetCookie?.() || []) {
    const [pair] = c.split(';');
    const i = pair.indexOf('=');
    jar.set(pair.slice(0, i), pair.slice(i + 1));
  }
}
function cookieHeader() {
  return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
}
async function web(url, opts = {}) {
  const res = await fetch(url, { redirect: 'manual', ...opts, headers: { Cookie: cookieHeader(), ...(opts.headers || {}) } });
  storeCookies(res);
  return res;
}
async function mcp(token, method, params = {}, id = 1) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(`${BASE}/local/coursepilot/mcp.php`, {
    method: 'POST', headers, body: JSON.stringify({ jsonrpc: '2.0', id, method, params }),
  });
  const text = await res.text();
  let body = null;
  try { body = JSON.parse(text); } catch { /* leer */ }
  return { status: res.status, headers: res.headers, body };
}
async function tokenRequest(form) {
  const res = await fetch(`${BASE}/local/coursepilot/oauth/token.php`, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(form),
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

(async () => {
  // 1. Discovery-Kette wie ein Client sie laeuft.
  const unauth = await mcp(null, 'initialize', { protocolVersion: '2025-06-18' });
  const wwwauth = unauth.headers.get('www-authenticate') || '';
  check('mcp.php ohne Token -> 401 mit resource_metadata', unauth.status === 401 && /resource_metadata="/.test(wwwauth), `HTTP ${unauth.status}`);
  const prurl = (wwwauth.match(/resource_metadata="([^"]+)"/) || [])[1];
  const pr = await (await fetch(prurl)).json();
  check('Protected-Resource-Metadaten (RFC 9728, verlinkt)', pr.resource === `${BASE}/local/coursepilot/mcp.php`);
  const pr2 = await fetch(`${BASE}/local/coursepilot/mcp.php/.well-known/oauth-protected-resource`);
  check('Protected-Resource-Metadaten am Ressourcenpfad', pr2.status === 200 && (await pr2.json()).resource === pr.resource);
  const asurl = `${pr.authorization_servers[0]}/.well-known/oauth-authorization-server`;
  const asres = await fetch(asurl);
  const as = await asres.json();
  check('Authorization-Server-Metadaten (RFC 8414)', asres.status === 200 && as.code_challenge_methods_supported?.includes('S256'));
  const oidc = await fetch(`${pr.authorization_servers[0]}/.well-known/openid-configuration`);
  check('OIDC-Discovery-Adresse', oidc.status === 200);

  // 2. Dynamische Client-Registrierung.
  const reg = await fetch(as.registration_endpoint, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_name: CLIENTNAME, redirect_uris: [REDIRECT], token_endpoint_auth_method: 'none' }),
  });
  const client = await reg.json();
  check('DCR', reg.status === 201 || (reg.status === 200 && !!client.client_id), `HTTP ${reg.status}`);

  // 3. Moodle-Login.
  const loginpage = await (await web(`${BASE}/login/index.php`)).text();
  const logintoken = (loginpage.match(/name="logintoken" value="([^"]+)"/) || [])[1] || '';
  const login = await web(`${BASE}/login/index.php`, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ username: USER, password: PASS, logintoken, anchor: '' }),
  });
  const next = login.headers.get('location') || '';
  if (/testsession=/.test(next)) await web(next);
  const loggedin = login.status === 303 && (/testsession=/.test(next) || !/login\/index\.php/.test(next));
  check('Moodle-Login teacher_edit', loggedin, `HTTP ${login.status}`);
  if (!loggedin) return finish();

  // 4. Zustimmungsdialog.
  const verifier = crypto.randomBytes(32).toString('base64url');
  const challenge = crypto.createHash('sha256').update(verifier).digest('base64url');
  const state = crypto.randomBytes(8).toString('hex');
  const authparams = {
    response_type: 'code', client_id: client.client_id, redirect_uri: REDIRECT,
    code_challenge: challenge, code_challenge_method: 'S256', state,
  };
  const authpage = await web(`${as.authorization_endpoint}?${new URLSearchParams(authparams)}`);
  const html = await authpage.text();
  check('Zustimmungsdialog nennt den Client', authpage.status === 200 && html.includes(CLIENTNAME), `HTTP ${authpage.status}`);
  const sesskey = (html.match(/name="sesskey" value="([^"]+)"/) || [])[1];
  const allow = await web(as.authorization_endpoint, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ ...authparams, sesskey, action: 'allow' }),
  });
  const loc = new URL(allow.headers.get('location') || 'http://x/');
  const code = loc.searchParams.get('code');
  check('Zustimmung -> Redirect mit Code und state', !!code && loc.searchParams.get('state') === state, `HTTP ${allow.status}`);

  // 5. Token-Endpunkt: Einloesung, zweite Einloesung, Refresh-Rotation.
  const ex = await tokenRequest({ grant_type: 'authorization_code', code, client_id: client.client_id, redirect_uri: REDIRECT, code_verifier: verifier });
  check('Code-Einloesung -> Tokenpaar', ex.status === 200 && !!ex.body?.access_token && !!ex.body?.refresh_token, `HTTP ${ex.status}`);
  const ex2 = await tokenRequest({ grant_type: 'authorization_code', code, client_id: client.client_id, redirect_uri: REDIRECT, code_verifier: verifier });
  check('Zweite Einloesung desselben Codes abgelehnt', ex2.status === 400 && ex2.body?.error === 'invalid_grant', `HTTP ${ex2.status}`);
  const [r1, r2] = await Promise.all([1, 2].map(() => tokenRequest({ grant_type: 'refresh_token', refresh_token: ex.body.refresh_token, client_id: client.client_id })));
  const winners = [r1, r2].filter(r => r.status === 200 && r.body?.access_token);
  check('Gleichzeitige Refresh-Rotation -> genau ein neues Paar', winners.length === 1, `HTTP ${r1.status}/${r2.status}`);
  const token = winners[0]?.body.access_token;

  // 6. MCP ueber HTTPS.
  const init = await mcp(token, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'live-check', version: '1' } });
  check('initialize', init.status === 200 && init.body?.result?.serverInfo?.name === 'local_coursepilot', `Release ${init.body?.result?.serverInfo?.version}`);
  const list = await mcp(token, 'tools/list', {}, 2);
  const tools = list.body?.result?.tools || [];
  const schemaok = tools.every(t => (t.inputSchema.required || []).every(r => Object.prototype.hasOwnProperty.call(t.inputSchema.properties, r)));
  check('tools/list', list.status === 200 && tools.length > 0 && schemaok, `${tools.length} Werkzeuge, Pflichtfelder im Schema`);
  const vi = await mcp(token, 'tools/call', { name: 'coursepilot_get_version_info', arguments: {} }, 3);
  const info = vi.body?.result?.structuredContent;
  check('tools/call coursepilot_get_version_info', vi.status === 200 && !vi.body?.result?.isError && !!info && String(info.plugin_version) === String(info.plugin_version_db), info?.message);
  const lc = await mcp(token, 'tools/call', { name: 'coursepilot_list_courses', arguments: {} }, 4);
  check('tools/call coursepilot_list_courses (lesend)', lc.status === 200 && !lc.body?.result?.isError);
  const bad = await mcp('ungueltig', 'tools/list', {}, 5);
  check('Ungueltiges Token -> 401', bad.status === 401);
  const old = await mcp(ex.body.access_token, 'tools/list', {}, 6);
  console.log(`info alter Access-Token nach Rotation: HTTP ${old.status}`);

  // 7. Aufraeumen: eigene Verbindungen dieses Clients widerrufen.
  const conn = await (await web(`${BASE}/local/coursepilot/connections.php`)).text();
  const ids = [...conn.matchAll(/revoke=(\d+)&(?:amp;)?sesskey=/g)].map(m => m[1]);
  let revoked = 0;
  for (const id of new Set(ids)) {
    const idx = conn.indexOf(`revoke=${id}`);
    const before = conn.slice(Math.max(0, idx - 3000), idx);
    const lastrow = before.lastIndexOf(CLIENTNAME) > before.lastIndexOf('revoke=');
    if (!lastrow) continue;
    await web(`${BASE}/local/coursepilot/connections.php?revoke=${id}&sesskey=${sesskey}`);
    revoked++;
  }
  const after = await mcp(token, 'tools/list', {}, 7);
  check('Widerruf wirkt sofort', revoked > 0 && after.status === 401, `${revoked} Verbindung(en) widerrufen, danach HTTP ${after.status}`);
  finish();
})().catch(e => { console.error('ABBRUCH', e.message); process.exit(2); });

function finish() {
  const failed = results.filter(r => !r.ok).length;
  console.log(`\n${results.length - failed}/${results.length} bestanden`);
  process.exit(failed ? 1 : 0);
}
