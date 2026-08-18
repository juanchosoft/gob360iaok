(() => {
  'use strict';

  const cfg = window.CONTACTOS_CONFIG || { verTodos: false, puedeAsignar: false };
  const $tabla = $('#contTabla');
  const $modal = $('#contModal');
  const $form = $('#contForm');
  const $formError = $('#contFormError');
  const $importModal = $('#contImportModal');
  const $importForm = $('#contImportForm');
  const $importError = $('#contImportError');
  const $importResultado = $('#contImportResultado');

  const idiomaEs = {
    processing: 'Procesando…',
    search: 'Buscar:',
    lengthMenu: 'Mostrar _MENU_ registros',
    info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
    infoEmpty: 'Sin registros',
    infoFiltered: '(filtrado de _MAX_ registros totales)',
    zeroRecords: 'No se encontraron contactos',
    emptyTable: 'Aún no tienes contactos registrados',
    paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
  };

  const escaparHtml = (texto) => {
    const div = document.createElement('div');
    div.textContent = String(texto || '');
    return div.innerHTML;
  };

  const toast = (mensaje) => {
    const node = document.querySelector('#contToast');
    node.textContent = mensaje;
    node.classList.add('visible');
    setTimeout(() => node.classList.remove('visible'), 3200);
  };

  const columnas = [
    { data: 'nombre' },
    { data: 'correo' },
    { data: 'cargo', defaultContent: '' },
    { data: 'telefono', defaultContent: '' },
  ];
  if (cfg.verTodos) {
    columnas.push({ data: 'propietario', defaultContent: '' });
  }
  columnas.push({
    data: null,
    orderable: false,
    className: 'cont-td-acciones',
    render: (data, type, row) => `
      <button type="button" class="btn btn-light btn-sm cont-edit-btn" data-id="${row.id}" title="Editar"><i class="feather icon-edit-2"></i></button>
    `,
  });

  const table = $tabla.DataTable({
    processing: true,
    serverSide: true,
    language: idiomaEs,
    ajax: {
      url: 'admin/ajax/rqst.php',
      type: 'POST',
      dataType: 'json',
      data: (d) => {
        d.op = 'contactos_listar';
        if (cfg.verTodos) {
          d.tbl_usuario_id = $('#contFiltroUsuario').val() || '';
        }
      },
    },
    columns: columnas,
  });

  $tabla.on('click', '.cont-edit-btn', function editarContacto() {
    const fila = table.row($(this).closest('tr')).data();
    if (!fila) {
      return;
    }
    abrirModal(fila);
  });

  if (cfg.verTodos) {
    $('#contFiltroUsuario').select2({ width: '260px' });
    llamarAjax('contactos_usuarios_filtro', {}).then((usuarios) => {
      const $select = $('#contFiltroUsuario');
      (usuarios || []).forEach((u) => {
        $select.append(new Option(u.nombre, u.id, false, false));
      });
    });
    $('#contFiltroUsuario').on('change', () => table.ajax.reload());
  }

  if (cfg.puedeAsignar) {
    $('#contPropietario').select2({
      width: '100%',
      placeholder: 'Yo (predeterminado)',
      allowClear: true,
      // Sin esto, Select2 cuelga el dropdown de <body> y queda detrás del modal de Bootstrap
      // (su backdrop/contenido tiene mayor z-index) -- al anclarlo al propio modal, el
      // dropdown queda dentro de su misma pila de apilamiento y se ve por encima.
      dropdownParent: $('#contModal'),
    });
    llamarAjax('contactos_usuarios_asignar', {}).then((usuarios) => {
      const $select = $('#contPropietario');
      (usuarios || []).forEach((u) => {
        $select.append(new Option(u.nombre, u.id, false, false));
      });
    });
  }

  function abrirModal(data) {
    $form[0].reset();
    $formError.text('');
    $('#contId').val(data.id || '');
    $('#contNombre').val(data.nombre || '');
    $('#contCorreo').val(data.correo || '');
    $('#contCargo').val(data.cargo || '');
    $('#contTelefono').val(data.telefono || '');
    if (cfg.puedeAsignar) {
      const $prop = $('#contPropietario');
      if (data.tbl_usuario_id && !$prop.find(`option[value="${data.tbl_usuario_id}"]`).length) {
        $prop.append(new Option(data.propietario || `Usuario #${data.tbl_usuario_id}`, data.tbl_usuario_id, false, false));
      }
      $prop.val(data.id ? (data.tbl_usuario_id || '') : '').trigger('change');
    }
    $('#contModalTitle').text(data.id ? 'Editar contacto' : 'Nuevo contacto');
    $('#contEliminarBtn').prop('hidden', !data.id);
    mostrarModal($modal);
  }

  $('#contNuevoBtn').on('click', () => abrirModal({}));

  $form.on('submit', async (event) => {
    event.preventDefault();
    $formError.text('');
    const $boton = $form.find('button[type="submit"]');
    $boton.prop('disabled', true);
    try {
      const payload = {
        id: $('#contId').val(),
        nombre: $('#contNombre').val().trim(),
        correo: $('#contCorreo').val().trim(),
        cargo: $('#contCargo').val().trim(),
        telefono: $('#contTelefono').val().trim(),
      };
      if (cfg.puedeAsignar) {
        payload.tbl_usuario_id = $('#contPropietario').val() || '';
      }
      await llamarAjax('contactos_guardar', payload);
      ocultarModal($modal);
      table.ajax.reload(null, false);
      toast('Contacto guardado correctamente.');
    } catch (error) {
      $formError.text(error.message);
    } finally {
      $boton.prop('disabled', false);
    }
  });

  $('#contEliminarBtn').on('click', () => {
    const id = $('#contId').val();
    if (!id) {
      return;
    }
    const confirmar = window.Swal
      ? Swal.fire({
          title: '¿Eliminar este contacto?',
          text: 'Esta acción no se puede deshacer.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Eliminar',
          cancelButtonText: 'Cancelar',
          confirmButtonColor: '#c93434',
        }).then((r) => r.isConfirmed)
      : Promise.resolve(window.confirm('¿Eliminar este contacto?'));

    confirmar.then(async (ok) => {
      if (!ok) {
        return;
      }
      try {
        await llamarAjax('contactos_eliminar', { id });
        ocultarModal($modal);
        table.ajax.reload(null, false);
        toast('Contacto eliminado.');
      } catch (error) {
        $formError.text(error.message);
      }
    });
  });

  // ── Importar Excel ────────────────────────────────────────────────────────
  $('#contImportarBtn').on('click', () => {
    $importForm[0].reset();
    $importError.text('');
    $importResultado.empty();
    mostrarModal($importModal);
  });

  $importForm.on('submit', async (event) => {
    event.preventDefault();
    $importError.text('');
    $importResultado.empty();

    const archivo = document.querySelector('#contImportFile').files[0];
    if (!archivo) {
      $importError.text('Selecciona un archivo .xlsx.');
      return;
    }

    const $boton = $importForm.find('button[type="submit"]');
    $boton.prop('disabled', true);
    try {
      const formData = new FormData();
      formData.append('excelFile', archivo);

      const response = await fetch('admin/ajax/contactos_import.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      });
      const payload = await response.json().catch(() => ({}));

      if (!payload.valid) {
        $importError.text(payload.message || 'No fue posible importar el archivo.');
        return;
      }

      let html = `<strong>${escaparHtml(payload.message || '')}</strong>`;
      if (payload.errors && payload.errors.length) {
        html += '<ul>' + payload.errors.map((e) => `<li>Fila ${escaparHtml(e.fila)}: ${escaparHtml(e.mensaje)}</li>`).join('') + '</ul>';
      }
      $importResultado.html(html);

      if (payload.inserted > 0) {
        table.ajax.reload(null, false);
        toast(`Se importaron ${payload.inserted} contacto(s).`);
      }
    } catch (error) {
      $importError.text('Error al importar: ' + error.message);
    } finally {
      $boton.prop('disabled', false);
    }
  });

  // ── Helpers ──────────────────────────────────────────────────────────────

  function mostrarModal($el) {
    if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
      window.jQuery($el).modal('show');
    } else {
      $el.get(0).style.display = 'block';
      $el.addClass('show');
      $el.removeAttr('aria-hidden');
    }
  }

  function ocultarModal($el) {
    if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
      window.jQuery($el).modal('hide');
    } else {
      $el.get(0).style.display = 'none';
      $el.removeClass('show');
      $el.attr('aria-hidden', 'true');
    }
  }

  async function llamarAjax(op, data) {
    const response = await fetch('admin/ajax/rqst.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: new URLSearchParams({ op, ...data }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.output || !payload.output.valid) {
      throw new Error((payload.output && payload.output.response) || 'No fue posible completar la operación.');
    }
    return payload.output.response;
  }
})();
