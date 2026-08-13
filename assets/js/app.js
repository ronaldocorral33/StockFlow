/* Núcleo de la app: cambio de tabs, indicador de sidebar, KPIs animados, toast. */
function switchTab(tab) {
  const btn = document.querySelector(`.sb-item[data-tab="${tab}"]`);
  document.querySelectorAll('.sb-item[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.view').forEach(v => v.classList.toggle('active', v.id === tab));
  const title = document.getElementById('page-title');
  if (title && btn) title.textContent = btn.dataset.label;
  moveSidebarIndicator(btn);
  if (tab === 'inventario') Inventario.render();
  if (tab === 'salidas') Salidas.render();
  if (tab === 'reportes') Reportes.render();
}

function moveSidebarIndicator(activeBtn) {
  const ind = document.getElementById('sb-indicator');
  if (!ind || !activeBtn) return;
  ind.style.opacity = '1';
  ind.style.transform = `translateY(${activeBtn.offsetTop}px)`;
}

function toast(msg, kind = 'ok') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.innerHTML = icon(kind === 'error' ? 'alertCircle' : 'check', 15) + `<span>${esc(msg)}</span>`;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t = setTimeout(() => t.classList.remove('show'), 2600);
}

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function fmt(n, dec = 0) {
  return (n == null || isNaN(n)) ? '—' : Number(n).toLocaleString('es-MX', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

function mx(n) {
  return (n == null || isNaN(n)) ? '—' : '$' + fmt(n, 0);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

/* Anima los valores .val[data-raw] dentro de un contenedor de KPIs (cuenta ascendente, ease-out). */
function animateKpis(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const easeOut = t => 1 - Math.pow(1 - t, 3);
  container.querySelectorAll('.val[data-raw]').forEach(el => {
    const target = Number(el.dataset.raw);
    const format = el.dataset.format || 'int';
    if (isNaN(target)) return;
    const duration = 650;
    const start = performance.now();
    function frame(now) {
      const p = Math.min(1, (now - start) / duration);
      const v = target * easeOut(p);
      el.textContent = format === 'money' ? mx(v) : (format === 'pct' ? v.toFixed(1) + '%' : Math.round(v).toLocaleString('es-MX'));
      if (p < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  });
}

document.addEventListener('DOMContentLoaded', async () => {
  await Attributes.load();
  Entradas.init();
  await Inventario.render();
  moveSidebarIndicator(document.querySelector('.sb-item.active'));
  toast('Listo');
});
