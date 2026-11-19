import express from 'express';
import crypto from 'node:crypto';

const app = express();
const key = process.env.TG_API_KEY;
if (!key) throw new Error('Set TG_API_KEY in the server environment');

app.post('/webhooks/tg', express.raw({ type: 'application/json', limit: '256kb' }), (req, res) => {
  const supplied = (req.get('X-TGGateway-Signature') || '').replace(/^sha256=/, '');
  const expected = crypto.createHmac('sha256', key).update(req.body).digest();
  const actual = Buffer.from(supplied, 'hex');
  if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return res.sendStatus(401);
  let event;
  try { event = JSON.parse(req.body.toString('utf8')); } catch { return res.sendStatus(400); }
  if (event.event !== 'payment.succeeded' || !event.order_id) return res.sendStatus(400);
  // Persist the order ID under a UNIQUE constraint before fulfilling it. Return 2xx for duplicates.
  console.log('Verified payment event for order:', event.order_id);
  return res.sendStatus(200);
});
app.listen(Number(process.env.PORT || 3000), '0.0.0.0');
