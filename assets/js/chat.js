/* Tab "Asistente": agente con herramientas sobre el inventario del propio negocio. */
const Chat = (() => {
  // Identificador del hilo de conversación. Se genera al cargar la página: todas las
  // preguntas de esta sesión comparten contexto, y "Nueva conversación" lo renueva.
  let conversationId = newConversationId();

  function newConversationId() {
    if (crypto.randomUUID) return crypto.randomUUID();
    // Respaldo para navegadores sin randomUUID.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

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
      const res = await Api.post('chat.php', { question, conversation_id: conversationId });

      // El modelo responde en Markdown; antes se pintaba con esc() y se veía como
      // texto crudo (asteriscos sueltos y tablas convertidas en filas de barras).
      const cuerpo = Markdown.render(res.answer);

      // Si la respuesta ya trae su propia tabla, no se dibuja la de datos: serían dos
      // tablas de lo mismo, y hasta ahora mostraban cifras distintas porque la
      // herramienta devolvía menos columnas de las que el texto mencionaba.
      thinking.innerHTML = cuerpo + (Markdown.hasTable(res.answer) ? '' : rowsTable(res.rows));
    } catch (e) {
      thinking.innerHTML = '<span class="muted">No pude responder esa pregunta.</span>';
    }
  }

  /** Corta el hilo: las siguientes preguntas empiezan sin contexto previo. */
  function reset() {
    conversationId = newConversationId();
    document.getElementById('chat-log').innerHTML = '';
    bubble('assistant', '<span class="muted">Nueva conversación. Las preguntas anteriores ya no se toman en cuenta.</span>');
  }

  return { send, reset };
})();
