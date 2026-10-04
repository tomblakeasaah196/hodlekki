// /tests/special_events/load/quiz.k6.js
// PR5 smoke/load profile. Run with: k6 run -e BASE_URL=... -e EVENT=... quiz.k6.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
export const options = { vus: Number(__ENV.VUS || 250), duration: __ENV.DURATION || '20s', thresholds: { http_req_failed: ['rate<0.005'], http_req_duration: ['p(95)<800'] } };
const errors = new Rate('quiz_errors'); const answerTime = new Trend('answer_time');
const base = (__ENV.BASE_URL || '').replace(/\/$/, ''); const event = __ENV.EVENT || '';
export default function () {
  if (!base || !event) return;
  const r = http.post(`${base}/api/special_events_public_api.php`, JSON.stringify({action:'join_games',event}), {headers:{'Content-Type':'application/json','X-SE-Request':'1'}});
  check(r, {'join responds': x => x.status === 200}); errors.add(r.status !== 200); sleep(1 + Math.random());
  const started = Date.now(); const q=http.get(`${base}/live/${event}/public.json`); answerTime.add(Date.now()-started); errors.add(q.status >= 400);
}
