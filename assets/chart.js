/* assets/chart.js — Smokeping 风格交互式延迟图（无第三方依赖）
 * 功能：烟雾带(p0~p100 / p25~p75)、按丢包着色的中位线、丢包背景、悬停提示、拖选缩放、HiDPI、暗色模式
 */
(function (global) {
  'use strict';

  // ---------------- 时区工具（始终按站点时区显示，不受浏览器时区影响） ----------------
  const fmtCache = {};
  function partsFmt(tz) {
    return fmtCache[tz] || (fmtCache[tz] = new Intl.DateTimeFormat('en-US', {
      timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    }));
  }
  function tzParts(ts, tz) {
    const o = {};
    for (const p of partsFmt(tz).formatToParts(new Date(ts * 1000))) o[p.type] = p.value;
    return o; // {year,month,day,hour,minute,second}
  }
  function tzOffset(ts, tz) {
    const p = tzParts(ts, tz);
    return (Date.UTC(+p.year, p.month - 1, +p.day, +p.hour, +p.minute, +p.second) / 1000) - Math.floor(ts);
  }
  function fmtTs(ts, tz, mode) {
    const p = tzParts(ts, tz);
    if (mode === 'input') return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
    if (mode === 'full') return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}`;
    if (mode === 'date') return `${p.month}-${p.day}`;
    if (mode === 'dt') return `${p.month}-${p.day} ${p.hour}:${p.minute}`;
    return `${p.hour}:${p.minute}`;
  }

  // ---------------- 颜色 ----------------
  function lossColor(l) {
    if (l == null) return '#9ca3af';
    if (l <= 0) return '#16a34a';
    if (l <= 0.05) return '#0ea5e9';
    if (l <= 0.2) return '#2563eb';
    if (l <= 0.5) return '#7c3aed';
    return '#dc2626';
  }
  function cssVar(name, fb) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fb;
  }

  function niceStep(raw) {
    const p = Math.pow(10, Math.floor(Math.log10(raw)));
    const n = raw / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
  }
  const X_STEPS = [60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800, 1209600, 2592000];

  function fmtMs(v) { return v == null ? '—' : (v >= 100 ? v.toFixed(0) : v.toFixed(1)) + ' ms'; }
  function fmtPct(v) { return v == null ? '—' : (v * 100).toFixed(v > 0 && v < 0.01 ? 2 : 1) + '%'; }

  // ---------------- 图表 ----------------
  class SmokeChart {
    constructor(el, opts) {
      this.el = el;
      this.tz = opts.tz || 'Asia/Shanghai';
      this.onZoom = opts.onZoom || null;
      this.data = null;
      this.hoverIdx = -1;
      el.classList.add('chart');
      el.innerHTML = '<canvas></canvas><div class="sel"></div><div class="tip"></div><div class="msg">加载中…</div>';
      this.cv = el.querySelector('canvas');
      this.selEl = el.querySelector('.sel');
      this.tipEl = el.querySelector('.tip');
      this.msgEl = el.querySelector('.msg');
      this.pad = { l: 54, r: 14, t: 14, b: 26 };
      this._bind();
      if (global.ResizeObserver) new ResizeObserver(() => this.draw()).observe(el);
      else global.addEventListener('resize', () => this.draw());
      if (global.matchMedia) {
        const mq = global.matchMedia('(prefers-color-scheme: dark)');
        mq.addEventListener && mq.addEventListener('change', () => this.draw());
      }
    }

    message(text) { this.msgEl.textContent = text || ''; this.msgEl.style.display = text ? 'flex' : 'none'; }

    setData(d) {
      this.data = d;
      this.hoverIdx = -1;
      const any = d.p50.some(v => v != null) || d.loss.some(v => v != null);
      this.message(any ? '' : '该时间段内没有数据');
      this.draw();
    }

    // 统计（等价于旧版 PNG 底部 GPRINT）
    stats() {
      const d = this.data; if (!d) return null;
      const med = d.p50.filter(v => v != null);
      const loss = d.loss.filter(v => v != null);
      let last = null;
      for (let i = d.p50.length - 1; i >= 0; i--) if (d.p50[i] != null) { last = d.p50[i]; break; }
      const avg = a => a.length ? a.reduce((s, v) => s + v, 0) / a.length : null;
      const m = avg(med);
      const sd = med.length ? Math.sqrt(avg(med.map(v => (v - m) * (v - m)))) : null;
      const up = loss.length ? loss.filter(v => v < 1).length / loss.length : null;
      return {
        avg: m, min: med.length ? Math.min(...med) : null, max: med.length ? Math.max(...med) : null,
        last, sd, lossAvg: avg(loss), lossMax: loss.length ? Math.max(...loss) : null, uptime: up,
      };
    }

    _geom() {
      const r = this.el.getBoundingClientRect();
      const p = this.pad;
      return { W: r.width, H: r.height, x0: p.l, y0: p.t, pw: Math.max(10, r.width - p.l - p.r), ph: Math.max(10, r.height - p.t - p.b) };
    }

    draw() {
      const d = this.data;
      const g = this._geom();
      const dpr = global.devicePixelRatio || 1;
      const cv = this.cv;
      if (cv.width !== Math.round(g.W * dpr) || cv.height !== Math.round(g.H * dpr)) {
        cv.width = Math.round(g.W * dpr); cv.height = Math.round(g.H * dpr);
      }
      const c = cv.getContext('2d');
      c.setTransform(dpr, 0, 0, dpr, 0, 0);
      c.clearRect(0, 0, g.W, g.H);
      if (!d) return;

      const S = d.req_start, E = d.req_end, step = d.step || 60;
      const X = t => g.x0 + (t - S) / (E - S) * g.pw;
      this._X = X; this._g = g;

      // ---- Y 轴范围：避免偶发尖峰把图压扁（尖峰会被截顶）
      let m50 = 0, m75 = 0, m100 = 0;
      for (let i = 0; i < d.ts.length; i++) {
        if (d.p50[i] != null) m50 = Math.max(m50, d.p50[i]);
        if (d.p75[i] != null) m75 = Math.max(m75, d.p75[i]);
        if (d.p100[i] != null) m100 = Math.max(m100, d.p100[i]);
      }
      let top = Math.min(m100, Math.max(m75 * 1.4, m50 * 2));
      top = Math.max(top, m50 * 1.15, 5);
      const ys = niceStep(top / 5);
      const yMax = Math.ceil(top / ys) * ys;
      const Y = v => g.y0 + g.ph - Math.min(v, yMax) / yMax * g.ph;
      this._Y = Y;

      const axis = cssVar('--axis', '#6b7280'), grid = cssVar('--grid', 'rgba(0,0,0,.08)');
      c.font = '11px system-ui,-apple-system,Segoe UI,sans-serif';
      c.lineWidth = 1;

      // ---- 网格 + Y 标签
      c.fillStyle = axis; c.strokeStyle = grid; c.textAlign = 'right'; c.textBaseline = 'middle';
      for (let v = 0; v <= yMax + 1e-9; v += ys) {
        const y = Math.round(Y(v)) + .5;
        c.beginPath(); c.moveTo(g.x0, y); c.lineTo(g.x0 + g.pw, y); c.stroke();
        c.fillText(v >= 1000 ? (v / 1000) + 's' : String(+v.toFixed(1)), g.x0 - 6, y);
      }
      c.save(); c.translate(12, g.y0 + g.ph / 2); c.rotate(-Math.PI / 2); c.textAlign = 'center'; c.fillText('ms', 0, 0); c.restore();

      // ---- X 轴刻度（按站点时区对齐到整点/整天）
      const span = E - S;
      const xs = X_STEPS.find(s => span / s <= Math.max(3, g.pw / 90)) || X_STEPS[X_STEPS.length - 1];
      const off = tzOffset(S, this.tz);
      const mode = xs >= 86400 ? 'date' : span > 86400 ? 'dt' : 'time';
      c.textAlign = 'center'; c.textBaseline = 'top';
      for (let t = Math.ceil((S + off) / xs) * xs - off; t <= E; t += xs) {
        const x = Math.round(X(t)) + .5;
        c.strokeStyle = grid; c.beginPath(); c.moveTo(x, g.y0); c.lineTo(x, g.y0 + g.ph); c.stroke();
        c.fillStyle = axis; c.fillText(fmtTs(t, this.tz, mode), x, g.y0 + g.ph + 6);
      }

      c.save();
      c.beginPath(); c.rect(g.x0, g.y0, g.pw, g.ph); c.clip();

      const colW = Math.max(1, X(S + step) - X(S));
      // ---- 丢包背景
      for (let i = 0; i < d.ts.length; i++) {
        const l = d.loss[i];
        if (l == null || l <= 0) continue;
        c.fillStyle = `rgba(220,38,38,${(0.08 + 0.32 * l).toFixed(3)})`;
        c.fillRect(X(d.ts[i] - step), g.y0, colW + .5, g.ph);
      }
      // ---- 烟雾
      const band = (lo, hi, color) => {
        c.fillStyle = color;
        for (let i = 0; i < d.ts.length; i++) {
          const a = d[lo][i], b = d[hi][i];
          if (a == null || b == null) continue;
          const y1 = Y(b), y2 = Y(a);
          c.fillRect(X(d.ts[i] - step), y1, colW + .5, Math.max(1, y2 - y1));
        }
      };
      band('p0', 'p100', cssVar('--smoke1', 'rgba(0,0,0,.1)'));
      band('p25', 'p75', cssVar('--smoke2', 'rgba(0,0,0,.22)'));

      // ---- 中位线（按丢包着色）
      c.lineWidth = 2; c.lineJoin = 'round';
      let prev = null;
      for (let i = 0; i < d.ts.length; i++) {
        const v = d.p50[i];
        if (v == null) { prev = null; continue; }
        const x = X(d.ts[i] - step / 2), y = Y(v);
        c.strokeStyle = lossColor(d.loss[i]);
        c.beginPath();
        if (prev) { c.moveTo(prev[0], prev[1]); c.lineTo(x, y); }
        else { c.moveTo(x - colW / 2, y); c.lineTo(x + colW / 2, y); }
        c.stroke();
        prev = [x, y];
      }

      // ---- 悬停十字线
      if (this.hoverIdx >= 0 && this.hoverIdx < d.ts.length) {
        const x = Math.round(X(d.ts[this.hoverIdx] - step / 2)) + .5;
        c.strokeStyle = axis; c.lineWidth = 1; c.setLineDash([3, 3]);
        c.beginPath(); c.moveTo(x, g.y0); c.lineTo(x, g.y0 + g.ph); c.stroke();
        c.setLineDash([]);
        const v = d.p50[this.hoverIdx];
        if (v != null) { c.fillStyle = lossColor(d.loss[this.hoverIdx]); c.beginPath(); c.arc(x, Y(v), 3.5, 0, 7); c.fill(); }
      }
      c.restore();

      c.strokeStyle = cssVar('--border2', '#ccc'); c.lineWidth = 1;
      c.strokeRect(g.x0 + .5, g.y0 + .5, g.pw - 1, g.ph - 1);
    }

    // ---------------- 交互 ----------------
    _idxAt(clientX) {
      const d = this.data; if (!d || !d.ts.length || !this._g) return -1;
      const r = this.el.getBoundingClientRect();
      const x = clientX - r.left;
      if (x < this._g.x0 || x > this._g.x0 + this._g.pw) return -1;
      const t = d.req_start + (x - this._g.x0) / this._g.pw * (d.req_end - d.req_start) + d.step / 2;
      let lo = 0, hi = d.ts.length - 1;
      while (lo < hi) { const m = (lo + hi) >> 1; if (d.ts[m] < t) lo = m + 1; else hi = m; }
      if (lo > 0 && Math.abs(d.ts[lo - 1] - t) < Math.abs(d.ts[lo] - t)) lo--;
      return lo;
    }

    _tip(clientX, clientY) {
      const i = this._idxAt(clientX);
      if (i !== this.hoverIdx) { this.hoverIdx = i; this.draw(); }
      const d = this.data;
      if (i < 0) { this.tipEl.style.display = 'none'; return; }
      const t = d.ts[i];
      const rows = [
        `<b>${fmtTs(t - d.step, this.tz, 'full')}${d.step > 60 ? ' ~ ' + fmtTs(t, this.tz, 'time') : ''}</b>`,
        `中位数 <b>${fmtMs(d.p50[i])}</b>`,
        `最小 / 最大 ${fmtMs(d.p0[i])} / ${fmtMs(d.p100[i])}`,
        `P25 / P75 ${fmtMs(d.p25[i])} / ${fmtMs(d.p75[i])}`,
        `丢包 <b style="color:${lossColor(d.loss[i])}">${fmtPct(d.loss[i])}</b>`,
      ];
      this.tipEl.innerHTML = rows.join('<br>');
      this.tipEl.style.display = 'block';
      const r = this.el.getBoundingClientRect();
      const tw = this.tipEl.offsetWidth, th = this.tipEl.offsetHeight;
      let x = clientX - r.left + 14, y = clientY - r.top + 14;
      if (x + tw > r.width) x = clientX - r.left - tw - 14;
      if (y + th > r.height) y = Math.max(0, r.height - th);
      this.tipEl.style.left = Math.max(0, x) + 'px';
      this.tipEl.style.top = y + 'px';
    }

    _bind() {
      let drag = null;
      const clampX = cx => {
        const r = this.el.getBoundingClientRect(), g = this._g;
        return Math.max(g.x0, Math.min(g.x0 + g.pw, cx - r.left));
      };
      this.cv.addEventListener('mousedown', e => {
        if (!this.data || e.button !== 0 || !this._g) return;
        drag = { x0: clampX(e.clientX) };
        e.preventDefault();
      });
      global.addEventListener('mousemove', e => {
        if (drag) {
          const x = clampX(e.clientX);
          this.selEl.style.display = 'block';
          this.selEl.style.left = Math.min(drag.x0, x) + 'px';
          this.selEl.style.width = Math.abs(x - drag.x0) + 'px';
          this.selEl.style.top = this._g.y0 + 'px';
          this.selEl.style.bottom = this._g.y0 + 'px';
          this.selEl.style.height = this._g.ph + 'px';
        }
      });
      this.cv.addEventListener('mousemove', e => this._tip(e.clientX, e.clientY));
      this.cv.addEventListener('mouseleave', () => {
        this.tipEl.style.display = 'none';
        if (this.hoverIdx !== -1) { this.hoverIdx = -1; this.draw(); }
      });
      global.addEventListener('mouseup', e => {
        if (!drag) return;
        const x1 = clampX(e.clientX), x0 = drag.x0;
        drag = null;
        this.selEl.style.display = 'none';
        if (Math.abs(x1 - x0) < 8 || !this.onZoom) return;
        // 像素 → 时间：基于实际绘图区（旧版用整张 PNG 宽度换算，含坐标轴边距，选区会偏）
        const d = this.data, g = this._g;
        const toT = x => Math.round(d.req_start + (x - g.x0) / g.pw * (d.req_end - d.req_start));
        const a = toT(Math.min(x0, x1)), b = toT(Math.max(x0, x1));
        if (b - a >= 120) this.onZoom(a, b);
      });
      // 触屏：点按/滑动查看数值
      this.cv.addEventListener('touchstart', e => { const t = e.touches[0]; this._tip(t.clientX, t.clientY); }, { passive: true });
      this.cv.addEventListener('touchmove', e => { const t = e.touches[0]; this._tip(t.clientX, t.clientY); }, { passive: true });
    }
  }

  global.SmokeChart = SmokeChart;
  global.SmokeUtil = { fmtTs, fmtMs, fmtPct, lossColor, tzOffset };
})(window);
