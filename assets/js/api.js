/* Wrapper de fetch() contra los endpoints PHP en /api. Incluye token CSRF y maneja errores con un toast. */
const Api = (() => {
  async function request(method, path, body) {
    const base = document.body.dataset.baseUrl || '';
    const opts = {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
    };
    if (window.__csrf) opts.headers['X-CSRF-Token'] = window.__csrf;
    if (body !== undefined) opts.body = JSON.stringify(body);

    let res;
    try {
      res = await fetch(base + 'api/' + path, opts);
    } catch (e) {
      toast('Error de conexión con el servidor');
      throw e;
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* respuesta vacía */ }
    if (!res.ok) {
      toast((data && data.error) ? data.error : `Error (${res.status})`);
      throw new Error((data && data.error) || `HTTP ${res.status}`);
    }
    return data;
  }

  return {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    put: (path, body) => request('PUT', path, body),
    del: (path) => request('DELETE', path),
  };
})();
