(() => {
  'use strict';
  if (!window.CORREO_CONFIG.connected) return;

  const elements = {
    lista: document.querySelector('#correoLista'),
    readPane: document.querySelector('#correoReadPane'),
    buscarInput: document.querySelector('#correoBuscarInput'),
    buscarBtn: document.querySelector('#correoBuscarBtn'),
    refrescarBtn: document.querySelector('#correoRefrescarBtn'),
    filtroBtns: document.querySelectorAll('[data-filtro]'),
    redactarBtn: document.querySelector('#correoRedactarBtn'),
    composeForm: document.querySelector('#correoComposeForm'),
    composeError: document.querySelector('#correoComposeError'),
    toast: document.querySelector('#correoToast'),
  };

  let filtroActual = 'no_leidos';
  let mensajeAbiertoId = null;

  const toast = (mensaje) => {
    elements.toast.textContent = mensaje;
    elements.toast.classList.add('visible');
    setTimeout(() => elements.toast.classList.remove('visible'), 3200);
  };

  const escaparHtml = (texto) => {
    const div = document.createElement('div');
    div.textContent = String(texto || '');
    return div.innerHTML;
  };

  async function llamar(op, params = {}) {
    const response = await fetch('admin/ajax/correo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: new URLSearchParams({ op, ...params }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.output || !payload.output.valid) {
      throw new Error((payload.output && payload.output.response) || 'No fue posible completar la operación.');
    }
    const resultado = payload.output.response;
    if (resultado && resultado.requiere_conexion) {
      throw new Error(resultado.mensaje || 'Debes conectar tu cuenta de Google.');
    }
    if (resultado && resultado.error) {
      throw new Error(resultado.error);
    }
    return resultado;
  }

  function formatearFecha(valor) {
    if (!valor) return '';
    const fecha = new Date(valor);
    if (Number.isNaN(fecha.getTime())) return valor;
    const hoy = new Date();
    const mismoDia = fecha.toDateString() === hoy.toDateString();
    return mismoDia
      ? fecha.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
      : fecha.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
  }

  function renderLista(correos) {
    elements.lista.innerHTML = '';
    if (!correos || correos.length === 0) {
      elements.lista.innerHTML = '<div class="correo-lista__vacio">No hay correos que mostrar.</div>';
      return;
    }
    correos.forEach((correo) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'correo-item'
        + (correo.no_leido ? ' is-unread' : '')
        + (String(correo.id) === mensajeAbiertoId ? ' is-active' : '');
      item.dataset.id = correo.id;
      item.innerHTML = `
        <div class="correo-item__top">
          <span class="correo-item__remitente">${correo.no_leido ? '<span class="correo-item__dot"></span>' : ''}${escaparHtml(correo.remitente || '(Desconocido)')}</span>
          <span class="correo-item__fecha">${escaparHtml(formatearFecha(correo.fecha))}</span>
        </div>
        <div class="correo-item__asunto">${escaparHtml(correo.asunto || '(Sin asunto)')}</div>
        <div class="correo-item__fragmento">${escaparHtml(correo.fragmento || '')}</div>
      `;
      item.addEventListener('click', () => abrirCorreo(String(correo.id)));
      elements.lista.appendChild(item);
    });
  }

  async function cargarLista() {
    elements.lista.innerHTML = '<div class="correo-lista__vacio">Cargando correos…</div>';
    try {
      const resultado = await llamar('listar', { filtro: filtroActual, limite: 20 });
      renderLista(resultado.correos || []);
    } catch (error) {
      elements.lista.innerHTML = `<div class="correo-lista__vacio">${escaparHtml(error.message)}</div>`;
    }
  }

  async function buscar() {
    const consulta = elements.buscarInput.value.trim();
    if (!consulta) {
      cargarLista();
      return;
    }
    elements.lista.innerHTML = '<div class="correo-lista__vacio">Buscando…</div>';
    try {
      const resultado = await llamar('buscar', { consulta, limite: 20 });
      renderLista(resultado.correos || []);
    } catch (error) {
      elements.lista.innerHTML = `<div class="correo-lista__vacio">${escaparHtml(error.message)}</div>`;
    }
  }

  async function abrirCorreo(id) {
    mensajeAbiertoId = id;
    document.querySelectorAll('.correo-item').forEach((el) => el.classList.toggle('is-active', el.dataset.id === id));

    elements.readPane.innerHTML = '<div class="correo-read-body is-loading">Cargando correo…</div>';
    try {
      const correo = await llamar('leer', { mensaje_id: id });
      renderLectura(correo);

      const itemEl = document.querySelector(`.correo-item[data-id="${CSS.escape(id)}"]`);
      if (itemEl && itemEl.classList.contains('is-unread')) {
        itemEl.classList.remove('is-unread');
        llamar('marcar_leido', { mensaje_id: id, leido: 1 }).catch(() => {});
      }
    } catch (error) {
      elements.readPane.innerHTML = `<div class="correo-read-pane__vacio"><i class="feather icon-alert-circle"></i><p>${escaparHtml(error.message)}</p></div>`;
    }
  }

  function renderLectura(correo) {
    elements.readPane.innerHTML = `
      <div class="correo-read-header">
        <div>
          <h5>${escaparHtml(correo.asunto || '(Sin asunto)')}</h5>
          <div class="correo-read-meta">De: ${escaparHtml(correo.remitente || '')}<br>Para: ${escaparHtml(correo.destinatario || '')}<br>${escaparHtml(correo.fecha || '')}</div>
        </div>
        <div class="correo-read-actions">
          <button type="button" class="btn btn-light btn-sm" id="correoMarcarNoLeidoBtn"><i class="feather icon-mail"></i> No leído</button>
        </div>
      </div>
      <div class="correo-read-body">${escaparHtml(correo.cuerpo || '(Sin contenido)')}</div>
      <div class="correo-reply">
        <textarea class="form-control" id="correoRespuestaTexto" placeholder="Escribe tu respuesta…"></textarea>
        <div class="correo-reply__actions">
          <button type="button" class="btn btn-primary btn-sm" id="correoResponderBtn"><i class="feather icon-corner-up-left"></i> Responder</button>
        </div>
      </div>
    `;

    document.querySelector('#correoMarcarNoLeidoBtn').addEventListener('click', async () => {
      try {
        await llamar('marcar_leido', { mensaje_id: correo.id, leido: 0 });
        const itemEl = document.querySelector(`.correo-item[data-id="${CSS.escape(String(correo.id))}"]`);
        if (itemEl) {
          itemEl.classList.add('is-unread');
        }
        toast('Marcado como no leído.');
      } catch (error) {
        toast(error.message);
      }
    });

    document.querySelector('#correoResponderBtn').addEventListener('click', async (event) => {
      const boton = event.currentTarget;
      const campoTexto = document.querySelector('#correoRespuestaTexto');
      const texto = campoTexto.value.trim();
      if (!texto) {
        return;
      }
      boton.disabled = true;
      try {
        await llamar('responder', { mensaje_id: correo.id, cuerpo: texto });
        toast('Respuesta enviada.');
        campoTexto.value = '';
      } catch (error) {
        toast(error.message);
      } finally {
        boton.disabled = false;
      }
    });
  }

  elements.filtroBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      elements.filtroBtns.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      filtroActual = btn.dataset.filtro;
      cargarLista();
    });
  });

  elements.buscarBtn.addEventListener('click', buscar);
  elements.buscarInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      buscar();
    }
  });
  elements.refrescarBtn.addEventListener('click', () => (elements.buscarInput.value ? buscar() : cargarLista()));

  elements.redactarBtn.addEventListener('click', () => {
    elements.composeForm.reset();
    elements.composeError.textContent = '';
    if (window.jQuery) {
      window.jQuery('#correoComposeModal').modal('show');
    }
  });

  elements.composeForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.composeError.textContent = '';
    const boton = elements.composeForm.querySelector('button[type="submit"]');
    boton.disabled = true;
    try {
      await llamar('enviar', {
        para: document.querySelector('#correoPara').value.trim(),
        asunto: document.querySelector('#correoAsunto').value.trim(),
        cuerpo: document.querySelector('#correoCuerpo').value.trim(),
      });
      if (window.jQuery) {
        window.jQuery('#correoComposeModal').modal('hide');
      }
      toast('Correo enviado.');
    } catch (error) {
      elements.composeError.textContent = error.message;
    } finally {
      boton.disabled = false;
    }
  });

  cargarLista();
})();
