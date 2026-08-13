/* Tab "Asistente": chatbot texto-a-SQL (RAG) contra el propio inventario del usuario. */
const Chat = (() => {
  function bubble(role, html) {
    const log = document.getElementById('chat-log');
    const div = document.createElement('div');
    div.className = 'chat-bubble ' + (role === 'user' ? 'user' : 'assistant');
    div.innerHTML = html;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
    return div;
  }

  function rowsTable(rows) {
    if (!rows || !rows.length) return '';
    const cols = Object.keys(rows[0]);
    return `<div class="tscroll" style="margin-top:8px"><table style="font-size:.78rem;min-width:0">
      <thead><tr>${cols.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead>
      <tbody>${rows.slice(0, 20).map(r => `<tr>${cols.map(c => `<td>${esc(r[c])}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>`;
  }

  async function send() {
    const input = document.getElementById('chat-input');
    const question = input.value.trim();
    if (!question) return;
    input.value = '';
    bubble('user', esc(question));
    const thinking = bubble('assistant', '<span class="muted">Pensando…</span>');

    try {
      const res = await Api.post('chat.php', { question });
      thinking.innerHTML = esc(res.answer) + rowsTable(res.rows);
    } catch (e) {
      thinking.innerHTML = '<span class="muted">No pude responder esa pregunta.</span>';
    }
  }

  return { send };
})();
