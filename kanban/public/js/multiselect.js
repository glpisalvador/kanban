/**
 * Plugin Kanban - multiselect com pesquisa (perfis, grupos, usuários, validadores).
 * Funciona por delegação: vale também para fileiras adicionadas depois.
 */
(function () {
    'use strict';

    function opcoes(container) {
        return Array.prototype.slice.call(container.querySelectorAll('.kanban-ms-opcao'));
    }

    function kanbanUpdateMultiselectCount(container) {
        var marcados = opcoes(container).filter(function (o) { return o.querySelector('input').checked; });
        var texto = container.querySelector('.kanban-ms-texto');
        var contador = container.querySelector('.kanban-ms-contador');
        if (marcados.length === 0) {
            texto.textContent = container.getAttribute('data-placeholder') || 'Selecione...';
            texto.classList.add('kanban-ms-vazio');
        } else if (marcados.length <= 2) {
            texto.textContent = marcados.map(function (o) { return o.querySelector('span').textContent; }).join(', ');
            texto.classList.remove('kanban-ms-vazio');
        } else {
            texto.textContent = marcados.length + ' selecionados';
            texto.classList.remove('kanban-ms-vazio');
        }
        contador.textContent = marcados.length + ' de ' + opcoes(container).length + ' selecionado(s)';
        kanbanUpdateSelectAll(container);
    }

    function kanbanUpdateSelectAll(container) {
        var todos = container.querySelector('[data-kanban-ms-todos]');
        if (!todos) { return; }
        var visiveis = opcoes(container).filter(function (o) { return o.style.display !== 'none'; });
        var marcados = visiveis.filter(function (o) { return o.querySelector('input').checked; });
        todos.checked = visiveis.length > 0 && marcados.length === visiveis.length;
        todos.indeterminate = marcados.length > 0 && marcados.length < visiveis.length;
    }

    /** Selecionados primeiro; ordem alfabética dentro de cada grupo */
    function kanbanReorderMultiselectOptions(container) {
        var lista = container.querySelector('.kanban-ms-opcoes');
        opcoes(container).sort(function (a, b) {
            var ca = a.querySelector('input').checked, cb = b.querySelector('input').checked;
            if (ca !== cb) { return ca ? -1 : 1; }
            return a.getAttribute('data-label').localeCompare(b.getAttribute('data-label'), 'pt-BR');
        }).forEach(function (o) { lista.appendChild(o); });
    }

    function kanbanFilterMultiselect(container, termo) {
        termo = (termo || '').toLowerCase().trim();
        opcoes(container).forEach(function (o) {
            o.style.display = !termo || o.getAttribute('data-label').indexOf(termo) !== -1 ? 'flex' : 'none';
        });
        kanbanUpdateSelectAll(container);
    }

    function kanbanToggleMultiselect(container, abrir) {
        var dropdown = container.querySelector('.kanban-ms-dropdown');
        var deveAbrir = abrir === undefined ? dropdown.hidden : abrir;
        document.querySelectorAll('[data-kanban-ms] .kanban-ms-dropdown').forEach(function (d) {
            if (d !== dropdown) { d.hidden = true; }
        });
        dropdown.hidden = !deveAbrir;
        container.classList.toggle('kanban-ms-aberto', deveAbrir);
        if (deveAbrir) {
            var busca = container.querySelector('.kanban-ms-busca');
            if (busca) { busca.focus(); }
        }
    }

    function kanbanToggleAllMultiselect(container, marcar) {
        opcoes(container).forEach(function (o) {
            if (o.style.display === 'none') { return; }
            o.querySelector('input').checked = marcar;
            o.classList.toggle('selected', marcar);
        });
        kanbanReorderMultiselectOptions(container);
        kanbanUpdateMultiselectCount(container);
    }

    function kanbanHandleMultiselectChange(container, opcao) {
        var marcado = opcao.querySelector('input').checked;
        opcao.classList.toggle('selected', marcado);
        var busca = container.querySelector('.kanban-ms-busca');
        if (busca && busca.value !== '') {
            busca.value = '';
            kanbanFilterMultiselect(container, '');
            busca.focus();
        }
        kanbanReorderMultiselectOptions(container);
        kanbanUpdateMultiselectCount(container);
    }

    function kanbanGetMultiselectValues(container) {
        return opcoes(container).filter(function (o) { return o.querySelector('input').checked; })
            .map(function (o) { return o.querySelector('input').value; });
    }

    function iniciar(raiz) {
        (raiz || document).querySelectorAll('[data-kanban-ms]').forEach(function (c) {
            if (c.closest('template')) { return; }
            kanbanUpdateMultiselectCount(c);
        });
    }

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-kanban-ms-abrir]');
        if (abrir) {
            kanbanToggleMultiselect(abrir.closest('[data-kanban-ms]'));
            return;
        }
        // Fecha ao clicar fora
        document.querySelectorAll('[data-kanban-ms]').forEach(function (c) {
            if (!c.contains(e.target)) {
                c.querySelector('.kanban-ms-dropdown').hidden = true;
                c.classList.remove('kanban-ms-aberto');
            }
        });
    });

    document.addEventListener('change', function (e) {
        var container = e.target.closest('[data-kanban-ms]');
        if (!container) { return; }
        if (e.target.matches('[data-kanban-ms-todos]')) {
            kanbanToggleAllMultiselect(container, e.target.checked);
            return;
        }
        var opcao = e.target.closest('.kanban-ms-opcao');
        if (opcao) {
            kanbanHandleMultiselectChange(container, opcao);
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.kanban-ms-busca')) {
            kanbanFilterMultiselect(e.target.closest('[data-kanban-ms]'), e.target.value);
        }
    });

    window.kanbanMultiselect = {
        iniciar: iniciar,
        valores: kanbanGetMultiselectValues,
        atualizar: kanbanUpdateMultiselectCount
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { iniciar(); });
    } else {
        iniciar();
    }
})();
