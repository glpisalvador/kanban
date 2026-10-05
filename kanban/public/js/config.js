/**
 * Plugin Kanban - tela de configuração (editor de fileiras e confirmações)
 */
(function () {
    'use strict';

    var modalEl = document.getElementById('kanban-cfg-confirmar');

    function confirmar(texto, rotulo) {
        return new Promise(function (resolve) {
            if (!modalEl || !window.bootstrap) { resolve(true); return; }
            var bs = bootstrap.Modal.getOrCreateInstance(modalEl);
            modalEl.querySelector('p').textContent = texto;
            var botao = modalEl.querySelector('[data-kanban-confirmar-sim]');
            botao.textContent = rotulo || 'Confirmar';
            var decidido = false;
            function sim() { decidido = true; bs.hide(); resolve(true); }
            function fechou() {
                botao.removeEventListener('click', sim);
                modalEl.removeEventListener('hidden.bs.modal', fechou);
                if (!decidido) { resolve(false); }
            }
            botao.addEventListener('click', sim);
            modalEl.addEventListener('hidden.bs.modal', fechou);
            bs.show();
        });
    }

    // Formulários que pedem confirmação antes de enviar
    document.querySelectorAll('form[data-kanban-confirmar]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmado === '1') { return; }
            e.preventDefault();
            var tmp = document.createElement('div');
            tmp.innerHTML = form.getAttribute('data-kanban-confirmar');
            confirmar(tmp.textContent, 'Confirmar').then(function (ok) {
                if (ok) {
                    form.dataset.confirmado = '1';
                    form.submit();
                }
            });
        });
    });

    // =====================================================================
    // Editor de fileiras
    // =====================================================================
    var area = document.querySelector('[data-kanban-fileiras]');
    if (!area) { return; }
    var modelo = document.getElementById('kanban-modelo-fileira');
    var contador = 0;

    function linhas() {
        return Array.prototype.slice.call(area.querySelectorAll('.kanban-fileira'));
    }

    /** Listas "mover para" com as fileiras atuais (inclusive as novas, ainda sem id) */
    function atualizarDestinos() {
        var itens = linhas().map(function (l) {
            var nome = l.querySelector('.kanban-fileira-nome').value.trim() || '(sem nome)';
            return { chave: l.getAttribute('data-chave'), nome: nome };
        });
        area.querySelectorAll('select[data-kanban-destino]').forEach(function (sel) {
            var atual = sel.value || sel.getAttribute('data-valor') || '';
            var propria = sel.closest('.kanban-fileira').getAttribute('data-chave');
            sel.innerHTML = '<option value="">— permanece —</option>';
            itens.forEach(function (i) {
                if (i.chave === propria) { return; }
                var o = document.createElement('option');
                o.value = i.chave;
                o.textContent = i.nome;
                sel.appendChild(o);
            });
            sel.value = itens.some(function (i) { return i.chave === atual && i.chave !== propria; }) ? atual : '';
            sel.setAttribute('data-valor', sel.value);
        });
    }

    function adicionar() {
        contador++;
        var chave = 'n' + contador;
        var html = modelo.innerHTML.split('__CHAVE__').join(chave);
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var linha = tmp.firstElementChild;
        area.appendChild(linha);
        if (window.kanbanMultiselect) { window.kanbanMultiselect.iniciar(linha); }
        atualizarDestinos();
        linha.querySelector('.kanban-fileira-nome').focus();
        linha.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    document.querySelectorAll('[data-kanban-adicionar-fileira]').forEach(function (b) {
        b.addEventListener('click', adicionar);
    });

    area.addEventListener('click', function (e) {
        var linha = e.target.closest('.kanban-fileira');
        if (!linha) { return; }
        if (e.target.closest('[data-kanban-subir]') && linha.previousElementSibling) {
            area.insertBefore(linha, linha.previousElementSibling);
        } else if (e.target.closest('[data-kanban-descer]') && linha.nextElementSibling) {
            area.insertBefore(linha.nextElementSibling, linha);
        } else if (e.target.closest('[data-kanban-remover-fileira]')) {
            if (linhas().length <= 1) {
                if (typeof window.glpi_toast_warning === 'function') {
                    window.glpi_toast_warning('O quadro precisa de pelo menos uma fileira.');
                }
                return;
            }
            var nome = linha.querySelector('.kanban-fileira-nome').value.trim() || 'sem nome';
            var aviso = /^\d+$/.test(linha.getAttribute('data-chave'))
                ? 'Remover a fileira "' + nome + '"? Ao salvar, os cards dela vão para a primeira fileira.'
                : 'Remover a fileira "' + nome + '"?';
            confirmar(aviso, 'Remover').then(function (ok) {
                if (ok) {
                    linha.remove();
                    atualizarDestinos();
                }
            });
        }
    });

    area.addEventListener('input', function (e) {
        if (e.target.matches('.kanban-fileira-nome')) {
            atualizarDestinos();
        }
    });

    area.addEventListener('change', function (e) {
        var linha = e.target.closest('.kanban-fileira');
        if (!linha) { return; }
        if (e.target.matches('.kanban-fileira-cor')) {
            linha.style.setProperty('--kanban-cor', e.target.value);
        }
        if (e.target.matches('input[name$="[validacao_ativa]"]')) {
            linha.querySelector('.kanban-fileira-validacao').hidden = !e.target.checked;
        }
        if (e.target.matches('select[data-kanban-destino]')) {
            e.target.setAttribute('data-valor', e.target.value);
        }
    });

    // Reordenar arrastando pela alça
    var arrastando = null;
    area.addEventListener('dragstart', function (e) {
        var alca = e.target.closest('.kanban-fileira-alca');
        if (!alca) { return; }
        arrastando = alca.closest('.kanban-fileira');
        arrastando.classList.add('kanban-fileira-arrastando');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', arrastando.getAttribute('data-chave'));
        e.dataTransfer.setDragImage(arrastando, 20, 20);
    });
    area.addEventListener('dragover', function (e) {
        if (!arrastando) { return; }
        e.preventDefault();
        var alvo = e.target.closest('.kanban-fileira');
        if (!alvo || alvo === arrastando) { return; }
        var r = alvo.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) {
            area.insertBefore(arrastando, alvo);
        } else {
            area.insertBefore(arrastando, alvo.nextElementSibling);
        }
    });
    area.addEventListener('dragend', function () {
        if (arrastando) { arrastando.classList.remove('kanban-fileira-arrastando'); }
        arrastando = null;
    });

    atualizarDestinos();
})();
