/**
 * Función para el botón "Restaurar original" del asiento predefinido.
 * Se llama desde el row actions del XMLView via type="js".
 */
function btnRestoreAsiento() {
    var form = document.getElementById('form-restore-asiento');
    if (!form) {
        console.error('AsientosPredefinidos: form-restore-asiento no encontrado');
        return;
    }

    var msg = form.getAttribute('data-confirm');
    if (!msg) msg = '¿Seguro? Las líneas, variables y ayuda volverán al estado original del plugin.';

    if (!confirm(msg)) return;

    // Mantener la pestaña activa tras el redirect
    var activeTab = document.querySelector('.nav-link.active');
    if (activeTab) {
        var tabName = (activeTab.getAttribute('href') || activeTab.getAttribute('data-bs-target') || '').replace('#', '');
        if (tabName) {
            var input = form.querySelector('input[name="activetab"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'activetab';
                form.appendChild(input);
            }
            input.value = tabName;
        }
    }

    form.submit();
}

/**
 * Interceptar el Guardar cuando el usuario desmarca el checkbox "Asiento personalizado".
 * Muestra un confirm advirtiendo que al reinstalar el plugin se perderán los cambios.
 * Si cancela, revierte el checkbox a marcado.
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('formEditAsientoPredefinido');
    if (!form) return;

    var chk = form.querySelector('input[name="personalizado"]');
    if (!chk) return;

    // Solo actuar si el checkbox estaba marcado al cargar la página
    var eraPersonalizado = chk.checked;

    chk.addEventListener('change', function () {
        if (!chk.checked && eraPersonalizado) {
            // El usuario está desmarcando — avisar
            var msg = '¿Seguro? Al reinstalar el plugin, las líneas, variables y ayuda de este asiento serán sobreescritas con los datos originales.';
            if (!confirm(msg)) {
                // Cancelado: revertir
                chk.checked = true;
            }
            // Si acepta: deja el check desmarcado y el usuario pulsa Guardar normalmente
        }
        if (chk.checked) {
            eraPersonalizado = true;
        }
    });
});
