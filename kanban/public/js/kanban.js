/**
 * Plugin Kanban - quadro (fileiras, cards, arrastar e soltar, modal do card e atualização automática)
 */
(function () {
    'use strict';

    var app = document.getElementById('kanban-app');
    if (!app) {
        return;
    }

    var CFG = JSON.parse(app.getAttribute('data-config') || '{}');
    var areaQuadro = document.getElementById('kanban-quadro');

    var estado = {
        colunas: [],
        cards: [],
        versao: '',
        hoje: '',
        carregando: false,
        arrastando: 0,
        cardAberto: 0,
        pendentes: 0,
        primeira: true,
        filtros: { texto: '', prioridade: '', vinculo: '', meus: false }
    };

    var CORES_PRIORIDADE = { 1: '#929dab', 2: '#667382', 3: '#206bc4', 4: '#f59f00', 5: '#f76707', 6: '#d63939' };

    // =====================================================================
    // Utilitários
    // =====================================================================
    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function escRegex(t) {
        return String(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function lerJson(texto) {
        try { return JSON.parse(texto); } catch (e) { }
        var m = String(texto).match(/\{[\s\S]*\}\s*$/);
        if (m) {
            try { return JSON.parse(m[0]); } catch (e2) { }
        }
        return null;
    }

    function avisar(texto, erro) {
        if (!texto) { return; }
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(texto);
            return;
        }
        var caixa = document.getElementById('kanban-avisos');
        if (!caixa) {
            caixa = document.createElement('div');
            caixa.id = 'kanban-avisos';
            document.body.appendChild(caixa);
        }
        var el = document.createElement('div');
        el.className = 'kanban-aviso' + (erro ? ' kanban-aviso-erro' : '');
        el.textContent = texto;
        caixa.appendChild(el);
        setTimeout(function () { el.remove(); }, 6000);
    }

    function debounce(fn, ms) {
        var t = null;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    function formatarData(iso) {
        if (!iso) { return ''; }
        var p = iso.split('-');
        return p.length === 3 ? p[2] + '/' + p[1] : iso;
    }

    // =====================================================================
    // Requisições: leituras por GET; alterações por POST em fila (token renovado a cada resposta)
    // =====================================================================
    function get(acao, params) {
        var qs = new URLSearchParams(params || {});
        qs.set('action', acao);
        return fetch(CFG.ajax + '?' + qs.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (t) {
            var d = lerJson(t);
            if (!d) { throw new Error('Resposta inválida do servidor.'); }
            return d;
        });
    }

    var fila = Promise.resolve();
    function post(acao, dados) {
        var p = fila.then(function () {
            var fd = dados instanceof FormData ? dados : new FormData();
            if (!(dados instanceof FormData)) {
                Object.keys(dados || {}).forEach(function (k) { fd.append(k, dados[k]); });
            }
            fd.set('action', acao);
            fd.set('_glpi_csrf_token', CFG.token || '');
            estado.pendentes++;
            return fetch(CFG.ajax, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': CFG.token || '' }
            }).then(function (r) { return r.text(); }).then(function (t) {
                var d = lerJson(t);
                if (!d) { throw new Error('Resposta inválida do servidor.'); }
                if (d.new_token) { CFG.token = d.new_token; }
                return d;
            }).finally(function () { estado.pendentes--; });
        });
        fila = p.catch(function () { });
        return p;
    }

    // =====================================================================
    // Editor rico: copia a configuração nativa do GLPI (editor-base escondido),
    // com imagens coladas guardadas em base64 dentro do HTML
    // =====================================================================
    function configEditor(id, altura) {
        var base = (window.tinymce_editor_configs || {})['kanban-editor-base'];
        var cfg = base ? Object.assign({}, base) : {
            license_key: 'gpl', branding: false, menubar: false, statusbar: false,
            plugins: ['autolink', 'autoresize', 'lists', 'link', 'table', 'code', 'image'],
            toolbar: 'bold italic | bullist numlist | link image table | code'
        };
        cfg.selector = '#' + (window.CSS && CSS.escape ? CSS.escape(id) : id);
        cfg.plugins = (Array.isArray(cfg.plugins) ? cfg.plugins : String(cfg.plugins || '').split(/[\s,]+/))
            .filter(function (p) { return p && p !== 'glpi_upload_doc'; });
        if (cfg.plugins.indexOf('image') === -1) { cfg.plugins.push('image'); }
        cfg.paste_data_images = true;
        cfg.automatic_uploads = false;
        delete cfg.images_upload_handler;
        delete cfg.images_upload_url;
        cfg.min_height = altura;
        cfg.height = altura;
        cfg.setup = function (editor) {
            editor.on('Change', function () { editor.save(); });
        };
        return cfg;
    }

    function criarEditores(raiz) {
        if (!window.tinymce) { return; }
        raiz.querySelectorAll('textarea.kanban-editor').forEach(function (t) {
            var atual = tinymce.get(t.id);
            if (atual) { atual.remove(); }
            var linhas = parseInt(t.getAttribute('rows'), 10) || 4;
            tinymce.init(configEditor(t.id, Math.max(120, linhas * 34)));
        });
    }

    function destruirEditores(raiz) {
        if (!window.tinymce) { return; }
        raiz.querySelectorAll('textarea.kanban-editor').forEach(function (t) {
            var ed = tinymce.get(t.id);
            if (ed) { ed.save(); ed.remove(); }
        });
    }

    function conteudo(id) {
        var ed = window.tinymce && tinymce.get(id);
        if (ed) { return ed.getContent(); }
        var t = document.getElementById(id);
        return t ? t.value : '';
    }

    function limparEditor(id) {
        var ed = window.tinymce && tinymce.get(id);
        if (ed) { ed.setContent(''); }
        var t = document.getElementById(id);
        if (t) { t.value = ''; }
    }

    function vazio(html) {
        var d = document.createElement('div');
        d.innerHTML = html || '';
        return d.textContent.trim() === '' && !d.querySelector('img');
    }

    // =====================================================================
    // Modais Bootstrap
    // =====================================================================
    function modal(id) {
        var el = document.getElementById(id);
        return { el: el, bs: bootstrap.Modal.getOrCreateInstance(el) };
    }

    function confirmar(texto, rotulo) {
        return new Promise(function (resolve) {
            var m = modal('kanban-modal-confirmar');
            document.getElementById('kanban-modal-confirmar-texto').textContent = texto;
            var botao = m.el.querySelector('[data-kanban-confirmar-sim]');
            botao.textContent = rotulo || 'Confirmar';
            var decidido = false;
            function sim() { decidido = true; m.bs.hide(); resolve(true); }
            function fechou() {
                botao.removeEventListener('click', sim);
                m.el.removeEventListener('hidden.bs.modal', fechou);
                if (!decidido) { resolve(false); }
            }
            botao.addEventListener('click', sim);
            m.el.addEventListener('hidden.bs.modal', fechou);
            m.bs.show();
        });
    }

    /** Comentário exigido pela fileira de destino */
    function pedirComentario(nomeColuna) {
        return new Promise(function (resolve) {
            var m = modal('kanban-modal-mover');
            document.getElementById('kanban-modal-mover-texto').textContent =
                'A fileira "' + nomeColuna + '" pede um comentário para receber o card.';
            var botao = m.el.querySelector('[data-kanban-confirmar-mover]');
            var decidido = false;
            function ok() {
                var html = conteudo('kanban-comentario-mover');
                if (vazio(html)) {
                    avisar('Escreva o comentário da movimentação.', true);
                    return;
                }
                decidido = true;
                m.bs.hide();
                resolve(html);
            }
            function aberto() {
                criarEditores(m.el);
            }
            function fechou() {
                botao.removeEventListener('click', ok);
                m.el.removeEventListener('shown.bs.modal', aberto);
                m.el.removeEventListener('hidden.bs.modal', fechou);
                destruirEditores(m.el);
                document.getElementById('kanban-comentario-mover').value = '';
                if (!decidido) { resolve(null); }
            }
            botao.addEventListener('click', ok);
            m.el.addEventListener('shown.bs.modal', aberto);
            m.el.addEventListener('hidden.bs.modal', fechou);
            m.bs.show();
        });
    }

    // =====================================================================
    // Carregar e desenhar o quadro
    // =====================================================================
    var indicador = document.getElementById('kanban-sinc');

    function marcarSinc(carregando) {
        if (!indicador) { return; }
        indicador.classList.toggle('kanban-sinc-ativo', !!carregando);
        if (!carregando) {
            var d = new Date();
            indicador.querySelector('span').textContent = 'Atualizado ' +
                String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' + String(d.getSeconds()).padStart(2, '0');
        }
    }

    function carregar() {
        if (estado.carregando) { return Promise.resolve(); }
        estado.carregando = true;
        marcarSinc(true);
        return get('quadro', { id: CFG.quadro }).then(function (d) {
            if (!d.success) {
                areaQuadro.innerHTML = '<div class="kanban-carregando">' + esc(d.mensagem || 'Não foi possível carregar o quadro.') + '</div>';
                return;
            }
            estado.colunas = d.colunas || [];
            estado.cards = d.cards || [];
            estado.versao = d.versao;
            estado.hoje = d.hoje;
            desenhar();
            if (estado.primeira) {
                estado.primeira = false;
                if (CFG.card > 0) { abrirCard(CFG.card); }
            }
        }).catch(function () {
            avisar('Não foi possível atualizar o quadro.', true);
        }).finally(function () {
            estado.carregando = false;
            marcarSinc(false);
        });
    }

    function cardsDaColuna(colunaId) {
        return estado.cards.filter(function (c) { return c.coluna === colunaId; })
            .sort(function (a, b) { return a.ordem - b.ordem || b.id - a.id; });
    }

    function desenhar() {
        var rolagem = areaQuadro.scrollLeft;
        var listas = {};
        areaQuadro.querySelectorAll('.kanban-lista').forEach(function (l) { listas[l.getAttribute('data-coluna')] = l.scrollTop; });

        if (!estado.colunas.length) {
            areaQuadro.innerHTML = '<div class="kanban-carregando">Este quadro ainda não tem fileiras.' +
                (CFG.config ? ' <a href="' + esc(CFG.config) + '">Configurar fileiras</a>' : '') + '</div>';
            return;
        }

        var html = '';
        estado.colunas.forEach(function (col) {
            var cards = cardsDaColuna(col.id);
            var limite = col.limite > 0 ? '<span class="kanban-coluna-limite' + (cards.length >= col.limite ? ' kanban-coluna-cheia' : '') + '" title="Limite de cards na fileira">'
                + cards.length + '/' + col.limite + '</span>' : '';
            var marcas = (col.final ? '<i class="ti ti-circle-check" title="Fileira de conclusão"></i>' : '')
                + (col.validacao ? '<i class="ti ti-shield-check" title="Pede validação ao receber o card"></i>' : '')
                + (col.pedir_comentario ? '<i class="ti ti-message" title="Pede comentário ao receber o card"></i>' : '');
            html += '<section class="kanban-coluna" data-coluna="' + col.id + '" style="--kanban-cor:' + esc(col.cor) + '">';
            html += '<header class="kanban-coluna-topo"><span class="kanban-coluna-nome">' + esc(col.nome) + '</span>'
                + '<span class="kanban-coluna-marcas">' + marcas + '</span>'
                + '<span class="kanban-coluna-total" data-total' + (col.limite > 0 ? ' hidden' : '') + '>' + cards.length + '</span>' + limite + '</header>';
            html += '<div class="kanban-lista" data-coluna="' + col.id + '">';
            cards.forEach(function (c) { html += cardHtml(c); });
            html += '<div class="kanban-lista-vazia">Arraste cards para cá</div>';
            html += '</div>';
            html += '<button type="button" class="kanban-coluna-novo" data-kanban-novo-coluna="' + col.id + '"><i class="ti ti-plus"></i> Adicionar card</button>';
            html += '</section>';
        });
        areaQuadro.innerHTML = html;
        areaQuadro.scrollLeft = rolagem;
        areaQuadro.querySelectorAll('.kanban-lista').forEach(function (l) {
            var s = listas[l.getAttribute('data-coluna')];
            if (s) { l.scrollTop = s; }
        });
        aplicarFiltros();
    }

    function cardHtml(c) {
        var classes = ['kanban-card'];
        if (c.fechado) { classes.push('kanban-card-fechado'); }
        if (c.nao_lido) { classes.push('kanban-card-novidade'); }
        var atrasado = c.prazo && !c.fechado && c.prazo < estado.hoje;
        var cor = CORES_PRIORIDADE[c.prioridade] || '#667382';

        var h = '<article class="' + classes.join(' ') + '" draggable="true" data-card="' + c.id + '" style="--kanban-prioridade:' + cor + '">';
        h += '<div class="kanban-card-topo">';
        h += '<span class="kanban-card-id">#' + c.id + '</span>';
        if (c.prioridade !== 3) {
            h += '<span class="kanban-card-prioridade">' + esc(CFG.prioridades[c.prioridade] || '') + '</span>';
        }
        if (c.item) {
            h += '<a class="kanban-card-item' + (c.item.lixeira ? ' kanban-card-item-lixeira' : '') + '" href="' + esc(c.item.url) + '" target="_blank" title="'
                + esc(c.item.tipo_nome + ' #' + c.item.id + ' - ' + c.item.status_nome) + '" data-kanban-link>'
                + '<i class="' + esc(c.item.icone) + '"></i>' + c.item.id + '<span>' + esc(c.item.status_nome) + '</span></a>';
        }
        h += '</div>';
        h += '<div class="kanban-card-titulo" data-titulo="' + esc(c.titulo) + '">' + esc(c.titulo) + '</div>';

        var meta = '';
        if (c.prazo) {
            meta += '<span class="kanban-card-meta' + (atrasado ? ' kanban-card-atrasado' : '') + '" title="Prazo"><i class="ti ti-calendar"></i>' + formatarData(c.prazo) + '</span>';
        }
        if (c.comentarios) {
            meta += '<span class="kanban-card-meta' + (c.nao_lido ? ' kanban-card-meta-novo' : '') + '" title="Comentários' + (c.nao_lido ? ' (há novidades)' : '') + '"><i class="ti ti-message"></i>' + c.comentarios + '</span>';
        }
        if (c.anexos) {
            meta += '<span class="kanban-card-meta" title="Anexos"><i class="ti ti-paperclip"></i>' + c.anexos + '</span>';
        }
        if (c.validacao === 2) {
            meta += '<span class="kanban-card-meta kanban-card-meta-aviso" title="Aguardando validação"><i class="ti ti-hourglass"></i></span>';
        } else if (c.validacao === 3) {
            meta += '<span class="kanban-card-meta kanban-card-meta-ok" title="Validação aprovada"><i class="ti ti-shield-check"></i></span>';
        } else if (c.validacao === 4) {
            meta += '<span class="kanban-card-meta kanban-card-meta-erro" title="Validação recusada"><i class="ti ti-shield-x"></i></span>';
        }
        var pessoas = '';
        (c.responsaveis || []).slice(0, 3).forEach(function (r) {
            pessoas += '<span class="kanban-avatar kanban-avatar-p" title="' + esc(r.nome) + '">' + esc(r.iniciais) + '</span>';
        });
        if ((c.responsaveis || []).length > 3) {
            pessoas += '<span class="kanban-avatar kanban-avatar-p kanban-avatar-mais" title="' + esc(c.responsaveis.slice(3).map(function (r) { return r.nome; }).join(', ')) + '">+' + (c.responsaveis.length - 3) + '</span>';
        }
        if (meta || pessoas) {
            h += '<div class="kanban-card-rodape"><div class="kanban-card-metas">' + meta + '</div><div class="kanban-card-pessoas">' + pessoas + '</div></div>';
        }
        h += '</article>';
        return h;
    }

    // =====================================================================
    // Filtros e pesquisa (com destaque do termo)
    // =====================================================================
    function aplicarFiltros() {
        var f = estado.filtros;
        var termo = f.texto.trim().toLowerCase();
        var porId = {};
        estado.cards.forEach(function (c) { porId[c.id] = c; });

        areaQuadro.querySelectorAll('.kanban-card').forEach(function (el) {
            var c = porId[parseInt(el.getAttribute('data-card'), 10)];
            if (!c) { return; }
            var visivel = true;
            if (termo && c.busca.indexOf(termo) === -1) { visivel = false; }
            if (f.prioridade && String(c.prioridade) !== f.prioridade) { visivel = false; }
            if (f.vinculo === 'com' && !c.item) { visivel = false; }
            if (f.vinculo === 'sem' && c.item) { visivel = false; }
            if (f.meus && c.autor_id !== CFG.eu && !(c.responsaveis || []).some(function (r) { return r.id === CFG.eu; })) { visivel = false; }
            el.hidden = !visivel;

            var titulo = el.querySelector('.kanban-card-titulo');
            var original = titulo.getAttribute('data-titulo');
            if (termo && visivel) {
                titulo.innerHTML = esc(original).replace(new RegExp('(' + escRegex(esc(termo)) + ')', 'ig'), '<mark class="kanban-destaque">$1</mark>');
            } else {
                titulo.textContent = original;
            }
        });

        areaQuadro.querySelectorAll('.kanban-coluna').forEach(function (col) {
            var total = col.querySelectorAll('.kanban-card').length;
            var visiveis = col.querySelectorAll('.kanban-card:not([hidden])').length;
            var filtrando = termo || f.prioridade || f.vinculo || f.meus;
            col.querySelector('[data-total]').textContent = filtrando ? visiveis + '/' + total : total;
            col.classList.toggle('kanban-coluna-vazia', visiveis === 0);
        });
    }

    var pesquisa = document.getElementById('kanban-pesquisa');
    if (pesquisa) {
        pesquisa.addEventListener('input', debounce(function () {
            estado.filtros.texto = pesquisa.value;
            aplicarFiltros();
        }, 300));
    }
    [['kanban-filtro-prioridade', 'prioridade'], ['kanban-filtro-vinculo', 'vinculo']].forEach(function (par) {
        var el = document.getElementById(par[0]);
        if (el) {
            el.addEventListener('change', function () { estado.filtros[par[1]] = el.value; aplicarFiltros(); });
        }
    });
    var meus = document.getElementById('kanban-filtro-meus');
    if (meus) {
        meus.addEventListener('change', function () { estado.filtros.meus = meus.checked; aplicarFiltros(); });
    }

    // =====================================================================
    // Arrastar e soltar
    // =====================================================================
    var marcador = document.createElement('div');
    marcador.className = 'kanban-marcador';

    function posicaoNaLista(lista, y) {
        var cards = Array.prototype.filter.call(lista.querySelectorAll('.kanban-card'), function (el) {
            return !el.classList.contains('kanban-card-arrastando') && !el.hidden;
        });
        for (var i = 0; i < cards.length; i++) {
            var r = cards[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) {
                return cards[i];
            }
        }
        return null;
    }

    areaQuadro.addEventListener('dragstart', function (e) {
        var card = e.target.closest && e.target.closest('.kanban-card');
        if (!card) { return; }
        estado.arrastando = parseInt(card.getAttribute('data-card'), 10);
        card.classList.add('kanban-card-arrastando');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(estado.arrastando));
    });

    areaQuadro.addEventListener('dragover', function (e) {
        if (!estado.arrastando) { return; }
        var lista = e.target.closest && e.target.closest('.kanban-lista');
        if (!lista) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        areaQuadro.querySelectorAll('.kanban-lista-alvo').forEach(function (l) { if (l !== lista) { l.classList.remove('kanban-lista-alvo'); } });
        lista.classList.add('kanban-lista-alvo');
        var antes = posicaoNaLista(lista, e.clientY);
        if (antes) {
            lista.insertBefore(marcador, antes);
        } else {
            lista.insertBefore(marcador, lista.querySelector('.kanban-lista-vazia'));
        }
    });

    function limparArraste() {
        areaQuadro.querySelectorAll('.kanban-card-arrastando').forEach(function (el) { el.classList.remove('kanban-card-arrastando'); });
        areaQuadro.querySelectorAll('.kanban-lista-alvo').forEach(function (el) { el.classList.remove('kanban-lista-alvo'); });
        if (marcador.parentNode) { marcador.parentNode.removeChild(marcador); }
        estado.arrastando = 0;
    }

    areaQuadro.addEventListener('drop', function (e) {
        if (!estado.arrastando) { return; }
        var lista = e.target.closest && e.target.closest('.kanban-lista');
        if (!lista) { limparArraste(); return; }
        e.preventDefault();
        var id = estado.arrastando;
        var colunaId = parseInt(lista.getAttribute('data-coluna'), 10);
        var cards = Array.prototype.filter.call(lista.children, function (el) {
            return el === marcador || (el.classList.contains('kanban-card') && parseInt(el.getAttribute('data-card'), 10) !== id);
        });
        var posicao = Math.max(0, cards.indexOf(marcador));
        var elCard = areaQuadro.querySelector('.kanban-card[data-card="' + id + '"]');
        if (elCard && marcador.parentNode) {
            marcador.parentNode.insertBefore(elCard, marcador);
        }
        limparArraste();
        moverCard(id, colunaId, posicao, '');
    });

    areaQuadro.addEventListener('dragend', limparArraste);

    function nomeColuna(id) {
        var c = estado.colunas.find(function (x) { return x.id === id; });
        return c ? c.nome : '';
    }

    /** Move no servidor; pede comentário quando a fileira exige */
    function moverCard(id, colunaId, posicao, comentario) {
        return post('mover', { id: id, coluna: colunaId, posicao: posicao, comentario: comentario || '' }).then(function (d) {
            if (d.success) {
                if (d.mensagem) { avisar(d.mensagem, false); }
                return carregar();
            }
            if (d.pedir_comentario) {
                return pedirComentario(nomeColuna(colunaId)).then(function (html) {
                    if (html === null) { return carregar(); }
                    return moverCard(id, colunaId, posicao, html);
                });
            }
            avisar(d.mensagem || 'Não foi possível mover o card.', true);
            return carregar();
        }).catch(function () {
            avisar('Falha de comunicação ao mover o card.', true);
            return carregar();
        });
    }

    // =====================================================================
    // Modal do card
    // =====================================================================
    var modalCard = modal('kanban-modal-card');
    var corpoModal = document.getElementById('kanban-modal-card-corpo');
    var botaoSalvar = modalCard.el.querySelector('[data-kanban-salvar]');
    var botaoExcluir = modalCard.el.querySelector('[data-kanban-excluir]');

    function atualizarUrl(cardId) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('quadro', CFG.quadro);
            if (cardId) { url.searchParams.set('card', cardId); } else { url.searchParams.delete('card'); }
            history.replaceState(null, '', url.toString());
        } catch (e) { }
    }

    function abrirCard(id, coluna) {
        var params = id ? { id: id } : { quadro: CFG.quadro, coluna: coluna || 0 };
        return get('card_formulario', params).then(function (d) {
            if (!d.success) {
                avisar(d.mensagem || 'Não foi possível abrir o card.', true);
                return;
            }
            destruirEditores(corpoModal);
            estado.cardAberto = id || 0;
            document.getElementById('kanban-modal-card-titulo').textContent = d.titulo;
            corpoModal.innerHTML = d.html;
            botaoExcluir.hidden = !id;
            botaoSalvar.innerHTML = id ? '<i class="ti ti-device-floppy me-1"></i>Salvar' : '<i class="ti ti-plus me-1"></i>Criar card';
            prepararFormulario(corpoModal.querySelector('.kanban-form'));
            atualizarUrl(id);
            if (!modalCard.el.classList.contains('show')) {
                modalCard.bs.show();
            } else {
                criarEditores(corpoModal);
            }
            if (id) {
                var local = estado.cards.find(function (c) { return c.id === id; });
                if (local && local.nao_lido) {
                    local.nao_lido = false;
                    var el = areaQuadro.querySelector('.kanban-card[data-card="' + id + '"]');
                    if (el) { el.classList.remove('kanban-card-novidade'); }
                }
            }
        }).catch(function () {
            avisar('Falha de comunicação ao abrir o card.', true);
        });
    }

    modalCard.el.addEventListener('shown.bs.modal', function () {
        criarEditores(corpoModal);
        var titulo = corpoModal.querySelector('.kanban-form-titulo');
        if (titulo && !estado.cardAberto) { titulo.focus(); }
    });
    modalCard.el.addEventListener('hidden.bs.modal', function () {
        destruirEditores(corpoModal);
        corpoModal.innerHTML = '';
        estado.cardAberto = 0;
        atualizarUrl(0);
    });

    /** Liga os componentes do formulário do card */
    function prepararFormulario(form) {
        if (!form) { return; }
        prepararBuscaEntidade(form.querySelector('[data-kanban-busca="entidades"]'));
        prepararPessoas(form.querySelector('[data-kanban-pessoas]'));
        prepararVinculo(form);
    }

    // ---- Entidade: seleção única com pesquisa ----
    function prepararBuscaEntidade(caixa) {
        if (!caixa) { return; }
        var botao = caixa.querySelector('.kanban-busca-atual');
        var painel = caixa.querySelector('.kanban-busca-painel');
        var campo = painel.querySelector('input');
        var lista = painel.querySelector('.kanban-busca-lista');
        var oculto = caixa.querySelector('input[type=hidden]');

        function buscar() {
            get('buscar_entidades', { termo: campo.value }).then(function (d) {
                lista.innerHTML = (d.itens || []).map(function (i) {
                    return '<button type="button" class="kanban-busca-opcao' + (String(i.id) === oculto.value ? ' kanban-busca-opcao-ativa' : '') + '" data-id="' + i.id + '">' + esc(i.nome) + '</button>';
                }).join('') || '<div class="kanban-busca-nada">Nenhuma entidade encontrada.</div>';
            });
        }
        botao.addEventListener('click', function () {
            painel.hidden = !painel.hidden;
            if (!painel.hidden) { campo.value = ''; buscar(); campo.focus(); }
        });
        campo.addEventListener('input', debounce(buscar, 300));
        lista.addEventListener('click', function (e) {
            var op = e.target.closest('.kanban-busca-opcao');
            if (!op) { return; }
            oculto.value = op.getAttribute('data-id');
            botao.textContent = op.textContent;
            campo.value = '';
            painel.hidden = true;
        });
        document.addEventListener('click', function (e) {
            if (!caixa.contains(e.target)) { painel.hidden = true; }
        });
    }

    // ---- Responsáveis: várias pessoas com pesquisa ----
    function prepararPessoas(caixa) {
        if (!caixa) { return; }
        var escolhidos = [];
        try { escolhidos = JSON.parse(caixa.getAttribute('data-inicial') || '[]'); } catch (e) { escolhidos = []; }
        var oculto = caixa.querySelector('input[type=hidden]');
        var chips = caixa.querySelector('.kanban-pessoas-lista');
        var campo = caixa.querySelector('.kanban-busca-campo input');
        var lista = caixa.querySelector('.kanban-busca-campo .kanban-busca-lista');

        function desenharChips() {
            oculto.value = JSON.stringify(escolhidos.map(function (p) { return p.id; }));
            chips.innerHTML = escolhidos.map(function (p) {
                return '<span class="kanban-pessoa"><span class="kanban-avatar kanban-avatar-p">' + esc(p.iniciais) + '</span>' + esc(p.nome)
                    + '<button type="button" data-remover="' + p.id + '" title="Remover"><i class="ti ti-x"></i></button></span>';
            }).join('') || '<span class="kanban-pessoas-nenhum">Ninguém ainda</span>';
        }
        function buscar() {
            var termo = campo.value.trim();
            get('buscar_usuarios', { termo: termo }).then(function (d) {
                var ids = escolhidos.map(function (p) { return p.id; });
                var itens = (d.itens || []).filter(function (u) { return ids.indexOf(u.id) === -1; });
                lista.innerHTML = itens.map(function (u) {
                    return '<button type="button" class="kanban-busca-opcao" data-usuario=\'' + esc(JSON.stringify(u)) + '\'>'
                        + '<span class="kanban-avatar kanban-avatar-p">' + esc(u.iniciais) + '</span>' + esc(u.nome) + ' <small>' + esc(u.login) + '</small></button>';
                }).join('') || '<div class="kanban-busca-nada">Ninguém encontrado.</div>';
                lista.hidden = false;
            });
        }
        chips.addEventListener('click', function (e) {
            var b = e.target.closest('[data-remover]');
            if (!b) { return; }
            var id = parseInt(b.getAttribute('data-remover'), 10);
            escolhidos = escolhidos.filter(function (p) { return p.id !== id; });
            desenharChips();
        });
        campo.addEventListener('input', debounce(buscar, 300));
        campo.addEventListener('focus', buscar);
        lista.addEventListener('click', function (e) {
            var op = e.target.closest('[data-usuario]');
            if (!op) { return; }
            escolhidos.push(JSON.parse(op.getAttribute('data-usuario')));
            desenharChips();
            campo.value = '';
            lista.hidden = true;
            campo.focus();
        });
        document.addEventListener('click', function (e) {
            if (!caixa.contains(e.target)) { lista.hidden = true; }
        });
        desenharChips();
    }

    // ---- Vínculo com Chamado/Problema/Mudança ----
    function prepararVinculo(form) {
        var caixa = form.querySelector('[data-kanban-vinculo]');
        if (!caixa) { return; }
        var busca = caixa.querySelector('[data-kanban-busca-item]');
        var aplicar = caixa.querySelector('[data-kanban-aplicar-vinculo]');
        var escolhido = caixa.querySelector('[data-kanban-item-escolhido]');
        var idOculto = caixa.querySelector('input[name=vinculo_id]');
        var tipo = caixa.querySelector('select[name=vinculo_tipo]');

        function modo() {
            var r = caixa.querySelector('input[name=vinculo_modo]:checked');
            return r ? r.value : 'nenhum';
        }
        function atualizar() {
            if (!tipo) { return; }
            var m = modo();
            tipo.hidden = m === 'nenhum';
            busca.hidden = m !== 'existente';
            escolhido.hidden = m !== 'existente' || idOculto.value === '0';
            if (aplicar) {
                aplicar.hidden = !(m === 'novo' || (m === 'existente' && idOculto.value !== '0'));
            }
        }

        if (busca) {
            var campo = busca.querySelector('input');
            var lista = busca.querySelector('.kanban-busca-lista');
            var buscar = function () {
                get('buscar_itens', { tipo: tipo.value, termo: campo.value }).then(function (d) {
                    lista.innerHTML = (d.itens || []).map(function (i) {
                        return '<button type="button" class="kanban-busca-opcao" data-item="' + i.id + '" data-rotulo="' + esc('#' + i.id + ' ' + i.nome) + '">'
                            + '<b>#' + i.id + '</b> ' + esc(i.nome) + ' <small>' + esc(i.status) + '</small></button>';
                    }).join('') || '<div class="kanban-busca-nada">Nada encontrado.</div>';
                    lista.hidden = false;
                });
            };
            campo.addEventListener('input', debounce(buscar, 300));
            campo.addEventListener('focus', buscar);
            lista.addEventListener('click', function (e) {
                var op = e.target.closest('[data-item]');
                if (!op) { return; }
                idOculto.value = op.getAttribute('data-item');
                escolhido.innerHTML = '<i class="ti ti-link"></i> ' + esc(op.getAttribute('data-rotulo'))
                    + '<button type="button" data-kanban-limpar-item title="Trocar"><i class="ti ti-x"></i></button>';
                campo.value = '';
                lista.hidden = true;
                atualizar();
            });
            escolhido.addEventListener('click', function (e) {
                if (e.target.closest('[data-kanban-limpar-item]')) {
                    idOculto.value = '0';
                    atualizar();
                }
            });
            document.addEventListener('click', function (e) {
                if (!busca.contains(e.target)) { lista.hidden = true; }
            });
            tipo.addEventListener('change', function () { idOculto.value = '0'; atualizar(); });
        }
        caixa.addEventListener('change', function (e) {
            if (e.target.name === 'vinculo_modo') { atualizar(); }
        });
        if (aplicar) {
            aplicar.addEventListener('click', function () {
                var id = parseInt(form.getAttribute('data-card'), 10);
                var pedido = modo() === 'novo'
                    ? post('gerar_item', { id: id, tipo: tipo.value })
                    : post('vincular', { id: id, tipo: tipo.value, item: idOculto.value });
                aplicar.disabled = true;
                pedido.then(function (d) {
                    avisar(d.mensagem, !d.success);
                    if (d.success) { abrirCard(id); carregar(); }
                }).finally(function () { aplicar.disabled = false; });
            });
        }
        atualizar();
    }

    // ---- Ações dentro do modal ----
    corpoModal.addEventListener('click', function (e) {
        var form = corpoModal.querySelector('.kanban-form');
        if (!form) { return; }
        var id = parseInt(form.getAttribute('data-card'), 10);

        if (e.target.closest('[data-kanban-comentar]')) {
            var area = form.querySelector('[data-kanban-comentario]');
            var html = conteudo(area.id);
            if (vazio(html)) {
                avisar('Escreva o comentário.', true);
                return;
            }
            var botao = e.target.closest('[data-kanban-comentar]');
            botao.disabled = true;
            post('comentar', { id: id, conteudo: html }).then(function (d) {
                if (!d.success) { avisar(d.mensagem, true); return; }
                limparEditor(area.id);
                form.querySelector('[data-kanban-linha-do-tempo]').innerHTML = d.html;
                carregar();
            }).finally(function () { botao.disabled = false; });
            return;
        }

        var apagar = e.target.closest('[data-kanban-apagar-comentario]');
        if (apagar) {
            confirmar('Apagar este comentário?', 'Apagar').then(function (ok) {
                if (!ok) { return; }
                post('comentario_excluir', { id: id, comentario: apagar.getAttribute('data-kanban-apagar-comentario') }).then(function (d) {
                    if (!d.success) { avisar(d.mensagem, true); return; }
                    form.querySelector('[data-kanban-linha-do-tempo]').innerHTML = d.html;
                    carregar();
                });
            });
            return;
        }

        var remover = e.target.closest('[data-kanban-remover-anexo]');
        if (remover) {
            confirmar('Remover este anexo do card?', 'Remover').then(function (ok) {
                if (!ok) { return; }
                post('anexo_remover', { id: id, doc: remover.getAttribute('data-kanban-remover-anexo') }).then(function (d) {
                    if (!d.success) { avisar(d.mensagem, true); return; }
                    form.querySelector('[data-kanban-anexos]').innerHTML = d.anexos;
                    carregar();
                });
            });
            return;
        }

        if (e.target.closest('[data-kanban-desvincular]')) {
            confirmar('Remover o vínculo deste card com o item do GLPI? O item continua existindo.', 'Remover vínculo').then(function (ok) {
                if (!ok) { return; }
                post('desvincular', { id: id }).then(function (d) {
                    avisar(d.mensagem, !d.success);
                    abrirCard(id);
                    carregar();
                });
            });
        }
    });

    corpoModal.addEventListener('change', function (e) {
        var entrada = e.target.closest('[data-kanban-anexar]');
        if (!entrada || !entrada.files.length) { return; }
        var form = corpoModal.querySelector('.kanban-form');
        var id = parseInt(form.getAttribute('data-card'), 10);
        var fd = new FormData();
        fd.append('id', id);
        Array.prototype.forEach.call(entrada.files, function (f) { fd.append('arquivos[]', f); });
        entrada.value = '';
        form.querySelector('[data-kanban-anexos]').innerHTML = '<div class="kanban-vazio kanban-vazio-p"><i class="ti ti-loader-2"></i> Enviando...</div>';
        post('anexar', fd).then(function (d) {
            avisar(d.mensagem, !d.success);
            if (d.anexos) { form.querySelector('[data-kanban-anexos]').innerHTML = d.anexos; }
            if (d.html) { form.querySelector('[data-kanban-linha-do-tempo]').innerHTML = d.html; }
            carregar();
        });
    });

    function dadosDoFormulario(form) {
        var dados = {};
        ['titulo', 'prioridade', 'prazo', 'entities_id', 'responsaveis', 'coluna', 'vinculo_tipo', 'vinculo_id'].forEach(function (n) {
            var el = form.querySelector('[name="' + n + '"]');
            if (el) { dados[n] = el.value; }
        });
        var modoVinculo = form.querySelector('input[name=vinculo_modo]:checked');
        dados.vinculo_modo = modoVinculo ? modoVinculo.value : 'nenhum';
        var desc = form.querySelector('textarea[name=descricao]');
        dados.descricao = desc ? conteudo(desc.id) : '';
        return dados;
    }

    botaoSalvar.addEventListener('click', function () {
        var form = corpoModal.querySelector('.kanban-form');
        if (!form) { return; }
        var dados = dadosDoFormulario(form);
        if (!dados.titulo || !dados.titulo.trim()) {
            avisar('Informe o título do card.', true);
            form.querySelector('.kanban-form-titulo').focus();
            return;
        }
        var id = parseInt(form.getAttribute('data-card'), 10);
        botaoSalvar.disabled = true;

        if (!id) {
            dados.quadro = CFG.quadro;
            if (dados.vinculo_modo === 'existente' && (!dados.vinculo_id || dados.vinculo_id === '0')) {
                avisar('Escolha o item que será vinculado ou mude a opção de vínculo.', true);
                botaoSalvar.disabled = false;
                return;
            }
            post('card_criar', dados).then(function (d) {
                if (!d.success) { avisar(d.mensagem, true); return; }
                avisar(d.mensagem, false);
                modalCard.bs.hide();
                carregar();
            }).finally(function () { botaoSalvar.disabled = false; });
            return;
        }

        dados.id = id;
        var colunaNova = parseInt(dados.coluna, 10);
        var colunaOriginal = parseInt(form.getAttribute('data-coluna-original'), 10);
        delete dados.vinculo_modo; delete dados.vinculo_tipo; delete dados.vinculo_id; delete dados.coluna;
        post('card_salvar', dados).then(function (d) {
            if (!d.success) { avisar(d.mensagem, true); return; }
            modalCard.bs.hide();
            if (colunaNova && colunaNova !== colunaOriginal) {
                return moverCard(id, colunaNova, 0, '');
            }
            avisar(d.mensagem, false);
            return carregar();
        }).finally(function () { botaoSalvar.disabled = false; });
    });

    botaoExcluir.addEventListener('click', function () {
        var form = corpoModal.querySelector('.kanban-form');
        var id = form ? parseInt(form.getAttribute('data-card'), 10) : 0;
        if (!id) { return; }
        confirmar('Excluir este card do quadro? O item vinculado no GLPI não é alterado.', 'Excluir').then(function (ok) {
            if (!ok) { return; }
            post('card_excluir', { id: id }).then(function (d) {
                avisar(d.mensagem, !d.success);
                if (d.success) {
                    modalCard.bs.hide();
                    carregar();
                }
            });
        });
    });

    // ---- Abrir cards e criar novos ----
    areaQuadro.addEventListener('click', function (e) {
        if (e.target.closest('[data-kanban-link]')) { return; }
        var novo = e.target.closest('[data-kanban-novo-coluna]');
        if (novo) {
            abrirCard(0, parseInt(novo.getAttribute('data-kanban-novo-coluna'), 10));
            return;
        }
        var card = e.target.closest('.kanban-card');
        if (card) {
            abrirCard(parseInt(card.getAttribute('data-card'), 10));
        }
    });
    document.querySelectorAll('[data-kanban-novo]').forEach(function (b) {
        b.addEventListener('click', function () { abrirCard(0, 0); });
    });

    // =====================================================================
    // Atualização automática (atualizações feitas por outras pessoas e pelo GLPI)
    // =====================================================================
    function verificar() {
        if (document.hidden || estado.arrastando || estado.carregando || estado.pendentes > 0) { return; }
        get('versao', { id: CFG.quadro }).then(function (d) {
            if (!d.success || d.versao === estado.versao) {
                marcarSinc(false);
                return;
            }
            carregar().then(function () {
                // Card aberto: atualiza só a linha do tempo e os anexos (não mexe no que está sendo editado)
                var form = corpoModal.querySelector('.kanban-form');
                var id = form ? parseInt(form.getAttribute('data-card'), 10) : 0;
                if (!id) { return; }
                get('linha_do_tempo', { id: id }).then(function (r) {
                    if (!r.success) { return; }
                    var tl = form.querySelector('[data-kanban-linha-do-tempo]');
                    var an = form.querySelector('[data-kanban-anexos]');
                    if (tl) { tl.innerHTML = r.html; }
                    if (an) { an.innerHTML = r.anexos; }
                });
            });
        }).catch(function () { });
    }
    setInterval(verificar, Math.max(5, parseInt(CFG.intervalo, 10) || 15) * 1000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { verificar(); }
    });

    carregar();
})();
