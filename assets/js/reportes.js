/* Tab "Reportes": KPIs, gráficas (Chart.js) y proyección de ventas. */
const Reportes = (() => {
  const charts = {};
  const COLORS = ['#3049f0', '#12875a', '#8a5807', '#5f6b7a', '#7c8cf5', '#d1332f', '#0b0f1a', '#5b6472'];

  function drawChart(canvasId, config) {
    if (charts[canvasId]) charts[canvasId].destroy();
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    charts[canvasId] = new Chart(ctx, config);
  }

  async function render() {
    await Promise.all([
      renderKpis(), renderStockKpis(), renderTopProducts(),
      renderSalesTrend(), populateGroupByOptions(), renderSlowMovers(),
    ]);
    loadProjection();
  }

  async function renderKpis() {
    const k = await Api.get('dashboard.php?action=kpis');
    document.getElementById('kpis-rep').innerHTML = `
      <div class="kpi g"><div class="lbl">${icon('dollar', 14)} Ganancia histórica</div><div class="val pos" data-raw="${k.profit}" data-format="money">$0</div></div>
      <div class="kpi b"><div class="lbl">${icon('outbox', 14)} Ventas históricas</div><div class="val" data-raw="${k.revenue}" data-format="money">$0</div><div class="sub">${k.units_sold} piezas</div></div>
      <div class="kpi"><div class="lbl">${icon('trendUp', 14)} Ticket promedio</div><div class="val" data-raw="${k.avg_ticket}" data-format="money">$0</div></div>
      <div class="kpi r"><div class="lbl">${icon('chart', 14)} Margen</div><div class="val" data-raw="${k.margin}" data-format="pct">0%</div></div>`;
    animateKpis('kpis-rep');
  }

  async function renderStockKpis() {
    const k = await Api.get('dashboard.php?action=stock-kpis');
    document.getElementById('kpis-stock').innerHTML = `
      <div class="kpi in"><div class="lbl">${icon('package', 14)} Piezas en stock</div><div class="val" data-raw="${k.count}" data-format="int">0</div></div>
      <div class="kpi r"><div class="lbl">${icon('dollar', 14)} Dinero invertido</div><div class="val" data-raw="${k.invested}" data-format="money">$0</div></div>
      <div class="kpi g"><div class="lbl">${icon('trendUp', 14)} Venta potencial estimada</div><div class="val">${k.potential_revenue != null ? mx(k.potential_revenue) : 'Sin historial'}</div><div class="sub">${k.avg_historical_sale != null ? `${k.count} piezas × ${mx(k.avg_historical_sale)} promedio de venta` : 'aún no hay ventas para estimar'}</div></div>
      <div class="kpi"><div class="lbl">${icon('sparkle', 14)} Ganancia potencial</div><div class="val pos">${k.potential_profit != null ? mx(k.potential_profit) : '—'}</div></div>`;
    animateKpis('kpis-stock');
  }

  async function renderTopProducts() {
    const { rows } = await Api.get('dashboard.php?action=top-products');
    drawChart('chart-top-productos', {
      type: 'bar',
      data: {
        labels: rows.map(r => r.name),
        datasets: [{ label: 'Ganancia (MXN)', data: rows.map(r => Number(r.profit)), backgroundColor: COLORS[0] }],
      },
      options: { indexAxis: 'y', plugins: { legend: { display: false } } },
    });
  }

  async function renderSalesTrend() {
    const { rows } = await Api.get('dashboard.php?action=sales-trend');
    drawChart('chart-ventas-mes', {
      type: 'line',
      data: {
        labels: rows.map(r => r.label),
        datasets: [{ label: 'Piezas vendidas', data: rows.map(r => r.units), borderColor: COLORS[2], backgroundColor: COLORS[2], tension: 0.25 }],
      },
      options: { plugins: { legend: { display: false } } },
    });
    drawChart('chart-ganancia-mes', {
      type: 'bar',
      data: {
        labels: rows.map(r => r.label),
        datasets: [{ label: 'Ganancia (MXN)', data: rows.map(r => Number(r.profit)), backgroundColor: COLORS[1] }],
      },
      options: { plugins: { legend: { display: false } } },
    });
  }

  async function populateGroupByOptions() {
    const { fields } = await Api.get('dashboard.php?action=groupable-fields');
    const sel = document.getElementById('groupby-field');
    sel.innerHTML = fields.map(f => `<option value="${esc(f.field_key)}">${esc(f.label)}</option>`).join('');
    // Prioriza el primer atributo personalizado (más útil que "Categoría" cuando esta viene vacía).
    if (fields.length > 2) sel.value = fields[2].field_key;
    await loadGroupBy();
  }

  async function loadGroupBy() {
    const sel = document.getElementById('groupby-field');
    const field = sel.value;
    if (!field) return;
    const label = sel.options[sel.selectedIndex]?.textContent || 'criterio';
    document.getElementById('groupby-label').textContent = label.toLowerCase();
    const { rows } = await Api.get('dashboard.php?action=by-attribute&field=' + encodeURIComponent(field));
    drawChart('chart-groupby-units', {
      type: 'bar',
      data: {
        labels: rows.map(r => r.value),
        datasets: [{ label: 'Piezas vendidas', data: rows.map(r => Number(r.units)), backgroundColor: COLORS[3] }],
      },
      options: { indexAxis: 'y', plugins: { legend: { display: false } } },
    });
    drawChart('chart-groupby-profit', {
      type: 'bar',
      data: {
        labels: rows.map(r => r.value),
        datasets: [{ label: 'Ganancia (MXN)', data: rows.map(r => Number(r.profit)), backgroundColor: COLORS[0] }],
      },
      options: { indexAxis: 'y', plugins: { legend: { display: false } } },
    });
  }

  async function renderSlowMovers() {
    const { rows } = await Api.get('dashboard.php?action=slow-movers');
    const tb = document.getElementById('stock-antiguo');
    if (!rows.length) {
      tb.innerHTML = `<tr><td colspan="4"><div class="empty">${icon('package', 30)}No hay piezas en stock con fecha registrada.</div></td></tr>`;
      return;
    }
    tb.innerHTML = rows.map(r => `
      <tr>
        <td>${r.order_number ? '#' + r.order_number : '—'}</td>
        <td><b>${esc(r.name)}</b></td>
        <td class="money">${mx(r.total_cost)}</td>
        <td><b style="color:${r.days > 90 ? 'var(--red)' : (r.days > 45 ? 'var(--amber)' : 'var(--ink)')}">${r.days} días</b></td>
      </tr>`).join('');
  }

  async function loadProjection() {
    const months = document.getElementById('proj-months').value || 1;
    const res = await Api.get('dashboard.php?action=projection&months=' + months);
    const box = document.getElementById('proj-result');
    if (res.method === 'insufficient_data') {
      box.innerHTML = `<p class="muted">Aún no tienes suficientes ventas registradas para proyectar. Registra algunas ventas y vuelve a intentarlo.</p>`;
      return;
    }
    const note = res.method === 'moving_average'
      ? '<p class="muted">Con pocos meses de historial, esto es un promedio simple — mejora conforme registres más ventas.</p>'
      : '<p class="muted">Estimado con tendencia lineal sobre tu historial de ventas mensual.</p>';
    box.innerHTML = `
      <div class="kpis">
        ${res.projections.map(p => `
          <div class="kpi b">
            <div class="lbl">${icon('sparkle', 14)} ${esc(p.label)}</div>
            <div class="val">${p.units} piezas</div>
            <div class="sub">${mx(p.revenue)} venta · ${mx(p.profit)} ganancia est.</div>
          </div>`).join('')}
      </div>
      ${note}`;
  }

  return { render, loadProjection, loadGroupBy };
})();
