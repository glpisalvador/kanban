<?php

/**
 * Plugin Kanban - configuração: quadros, fileiras, acesso, integração ITIL e opções gerais
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

if (!PluginKanbanConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$raiz  = $CFG_GLPI['root_doc'] . '/plugins/kanban';
$acao  = $raiz . '/front/config.form.php';
$e     = [PluginKanbanConfig::class, 'e'];
$token = PluginKanbanConfig::tokenCsrf();

$parametro = (string) ($_GET['quadro'] ?? '');
$quadroId  = $parametro === 'novo' ? -1 : (int) $parametro;

// ---------------------------------------------------------------- processamento
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_geral':
            PluginKanbanConfig::setConfig('intervalo_atualizacao', (string) max(5, min(300, (int) ($_POST['intervalo_atualizacao'] ?? 15))));
            PluginKanbanConfig::setConfig('email_ativo', !empty($_POST['email_ativo']) ? '1' : '0');
            Session::addMessageAfterRedirect('Configurações gerais salvas.', true, INFO);
            break;

        case 'salvar_quadro':
            $eraNovo = (int) ($_POST['id'] ?? 0) <= 0;
            $id = PluginKanbanQuadro::salvar($_POST);
            if ($id > 0) {
                if ($eraNovo) {
                    PluginKanbanColuna::criarPadrao($id);
                    Session::addMessageAfterRedirect('Quadro criado com fileiras iniciais. Ajuste as fileiras abaixo.', true, INFO);
                } else {
                    Session::addMessageAfterRedirect('Quadro salvo.', true, INFO);
                }
                $quadroId = $id;
            }
            break;

        case 'salvar_colunas':
            $id = (int) ($_POST['quadro'] ?? 0);
            if (PluginKanbanQuadro::carregar($id) !== null
                && PluginKanbanColuna::salvarDoFormulario($id, (array) ($_POST['colunas'] ?? []))) {
                Session::addMessageAfterRedirect('Fileiras salvas.', true, INFO);
            }
            $quadroId = $id;
            break;

        case 'duplicar_quadro':
            $novo = PluginKanbanQuadro::duplicar((int) ($_POST['quadro'] ?? 0));
            if ($novo > 0) {
                Session::addMessageAfterRedirect('Quadro duplicado (sem os cards).', true, INFO);
                $quadroId = $novo;
            }
            break;

        case 'excluir_quadro':
            PluginKanbanQuadro::excluir((int) ($_POST['quadro'] ?? 0));
            Session::addMessageAfterRedirect('Quadro enviado para a lixeira. Os cards continuam guardados no banco.', true, INFO);
            $quadroId = 0;
            break;
    }
}

/** Multiselect com pesquisa, marcar todos e selecionados primeiro */
function kanban_multiselect(string $nome, array $opcoes, array $selecionados, string $placeholder): string
{
    $e = [PluginKanbanConfig::class, 'e'];
    $sel = [];
    $resto = [];
    foreach ($opcoes as $id => $rotulo) {
        if (in_array((int) $id, $selecionados, true)) {
            $sel[$id] = $rotulo;
        } else {
            $resto[$id] = $rotulo;
        }
    }
    asort($sel, SORT_NATURAL | SORT_FLAG_CASE);
    asort($resto, SORT_NATURAL | SORT_FLAG_CASE);

    $h  = '<div class="kanban-ms" data-kanban-ms data-placeholder="' . $e($placeholder) . '">';
    $h .= '<button type="button" class="kanban-ms-cabecalho form-select form-select-sm" data-kanban-ms-abrir><span class="kanban-ms-texto"></span></button>';
    $h .= '<div class="kanban-ms-dropdown" hidden>';
    $h .= '<input type="search" class="form-control form-control-sm kanban-ms-busca" placeholder="Pesquisar...">';
    $h .= '<label class="kanban-ms-todos"><input type="checkbox" class="form-check-input" data-kanban-ms-todos> Marcar/desmarcar todos</label>';
    $h .= '<div class="kanban-ms-opcoes">';
    foreach ($sel + $resto as $id => $rotulo) {
        $marcado = isset($sel[$id]);
        $h .= '<label class="kanban-ms-opcao' . ($marcado ? ' selected' : '') . '" data-label="' . $e(mb_strtolower((string) $rotulo)) . '">'
            . '<input type="checkbox" class="form-check-input" name="' . $e($nome) . '" value="' . (int) $id . '"' . ($marcado ? ' checked' : '') . '>'
            . '<span>' . $e($rotulo) . '</span></label>';
    }
    $h .= '</div></div>';
    $h .= '<div class="kanban-ms-contador"></div>';
    $h .= '</div>';
    return $h;
}

function kanban_switch(string $nome, bool $marcado, string $rotulo, string $dica = ''): string
{
    $e = [PluginKanbanConfig::class, 'e'];
    $h  = '<label class="form-check form-switch kanban-cfg-switch">';
    $h .= '<input class="form-check-input" type="checkbox" name="' . $e($nome) . '" value="1"' . ($marcado ? ' checked' : '') . '>';
    $h .= '<span class="form-check-label">' . $rotulo . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</span>';
    return $h . '</label>';
}

function kanban_paleta(string $nome, string $atual): string
{
    $e = [PluginKanbanConfig::class, 'e'];
    $h = '<div class="kanban-paleta">';
    foreach (PluginKanbanConfig::paleta() as $cor => $rotulo) {
        $h .= '<label title="' . $e($rotulo) . '"><input type="radio" name="' . $e($nome) . '" value="' . $cor . '"' . ($cor === $atual ? ' checked' : '') . '>'
            . '<span style="--kanban-cor:' . $cor . '"></span></label>';
    }
    return $h . '</div>';
}

/** Linha do editor de fileiras (também usada como modelo para linhas novas) */
function kanban_linha_coluna(string $chave, array $c, array $validadores, array $destinos): string
{
    $e = [PluginKanbanConfig::class, 'e'];
    $n = 'colunas[' . $chave . ']';
    $h  = '<div class="kanban-fileira" data-chave="' . $e($chave) . '" style="--kanban-cor:' . $e($c['cor']) . '">';
    $h .= '<div class="kanban-fileira-topo">';
    $h .= '<span class="kanban-fileira-alca" draggable="true" title="Arraste para reordenar"><i class="ti ti-grip-vertical"></i></span>';
    $h .= '<input type="text" class="form-control form-control-sm kanban-fileira-nome" name="' . $n . '[name]" value="' . $e($c['name']) . '" placeholder="Nome da fileira" maxlength="255" required>';
    $h .= '<select class="form-select form-select-sm kanban-fileira-cor" name="' . $n . '[cor]" title="Cor">';
    foreach (PluginKanbanConfig::paleta() as $cor => $rotulo) {
        $h .= '<option value="' . $cor . '"' . ($cor === $c['cor'] ? ' selected' : '') . '>' . $rotulo . '</option>';
    }
    $h .= '</select>';
    $h .= '<label class="kanban-fileira-wip" title="Limite de cards (0 = sem limite)"><i class="ti ti-stack-2"></i><input type="number" min="0" max="999" class="form-control form-control-sm" name="' . $n . '[limite_wip]" value="' . (int) $c['limite_wip'] . '"></label>';
    $h .= '<div class="kanban-fileira-botoes">';
    $h .= '<button type="button" class="btn btn-sm btn-ghost-secondary" data-kanban-subir title="Subir"><i class="ti ti-arrow-up"></i></button>';
    $h .= '<button type="button" class="btn btn-sm btn-ghost-secondary" data-kanban-descer title="Descer"><i class="ti ti-arrow-down"></i></button>';
    $h .= '<button type="button" class="btn btn-sm btn-ghost-danger" data-kanban-remover-fileira title="Remover fileira"><i class="ti ti-trash"></i></button>';
    $h .= '</div></div>';

    $h .= '<div class="kanban-fileira-corpo">';

    $h .= '<div class="kanban-fileira-grupo"><div class="kanban-fileira-titulo">Comportamento</div>';
    $h .= kanban_switch($n . '[is_final]', (bool) $c['is_final'], 'Conclui o card', 'Cards nesta fileira ficam como concluídos');
    $h .= kanban_switch($n . '[pedir_comentario]', (bool) $c['pedir_comentario'], 'Pede comentário', 'Ao receber um card, pede um comentário');
    $h .= '</div>';

    $h .= '<div class="kanban-fileira-grupo"><div class="kanban-fileira-titulo">Status do item vinculado ao entrar</div>';
    foreach (PluginKanbanConfig::ITEMTYPES as $tipo) {
        $campo = PluginKanbanConfig::campoStatus($tipo);
        $h .= '<label class="kanban-fileira-status"><span><i class="' . PluginKanbanConfig::iconeItemtype($tipo) . '"></i> ' . PluginKanbanConfig::nomeItemtype($tipo) . '</span>';
        $h .= '<select class="form-select form-select-sm" name="' . $n . '[' . $campo . ']"><option value="0">— não altera —</option>';
        foreach (PluginKanbanConfig::statusDoTipo($tipo) as $valor => $rotulo) {
            $h .= '<option value="' . $valor . '"' . ((int) $c[$campo] === $valor ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
        }
        $h .= '</select></label>';
    }
    $h .= '</div>';

    $h .= '<div class="kanban-fileira-grupo"><div class="kanban-fileira-titulo">Validação (Chamado e Mudança)</div>';
    $h .= kanban_switch($n . '[validacao_ativa]', (bool) $c['validacao_ativa'], 'Pedir validação ao entrar', 'Cria a validação nativa no item vinculado');
    $h .= '<div class="kanban-fileira-validacao"' . ($c['validacao_ativa'] ? '' : ' hidden') . '>';
    $h .= '<label class="kanban-cfg-campo"><span>Validadores</span>' . kanban_multiselect($n . '[validadores][]', $validadores, $c['validadores'], 'Escolha os validadores') . '</label>';
    foreach (['coluna_aprovado' => 'Se aprovada, mover para', 'coluna_recusado' => 'Se recusada, mover para'] as $campo => $rotulo) {
        $h .= '<label class="kanban-cfg-campo"><span>' . $rotulo . '</span><select class="form-select form-select-sm" name="' . $n . '[' . $campo . ']" data-kanban-destino data-valor="' . $e((string) ($destinos[$campo] ?? '')) . '"><option value="">— permanece —</option></select></label>';
    }
    $h .= '</div></div>';

    $h .= '</div></div>';
    return $h;
}

// ---------------------------------------------------------------- página
Html::header('Configuração do Kanban', $_SERVER['PHP_SELF'], 'tools', 'PluginKanbanMenu', 'config');

echo '<link rel="stylesheet" href="' . $raiz . '/css/config.css?v=' . PLUGIN_KANBAN_VERSION . '">';
echo '<script src="' . $raiz . '/js/multiselect.js?v=' . PLUGIN_KANBAN_VERSION . '"></script>';
echo '<script src="' . $raiz . '/js/config.js?v=' . PLUGIN_KANBAN_VERSION . '" defer></script>';

// Entidades: só as raízes e as que não são filhas (as filhas ficam escondidas nos seletores do GLPI)
echo '<script>(function(){var esconder=' . json_encode(PluginKanbanConfig::idsEntidadesFilhas()) . ';'
    . 'if(!window.jQuery||!esconder.length){return;}'
    . 'jQuery.ajaxPrefilter(function(op){if(!op.url||op.url.indexOf("getDropdownValue")===-1||op.url.indexOf("Entity")===-1&&String(op.data||"").indexOf("Entity")===-1){return;}'
    . 'var ok=op.success;op.success=function(d){try{if(d&&d.results){d.results=d.results.filter(function(r){if(r.children){r.children=r.children.filter(function(c){return esconder.indexOf(parseInt(c.id,10))===-1;});return r.children.length>0;}return esconder.indexOf(parseInt(r.id,10))===-1;});}}catch(e){}'
    . 'if(ok){return ok.apply(this,arguments);}};});})();</script>';

echo '<div class="kanban-cfg">';

// Depois de criar/duplicar, a URL passa a apontar para o quadro
if ($quadroId > 0 && (string) $quadroId !== $parametro) {
    echo '<script>try{history.replaceState(null,"",' . json_encode($acao . '?quadro=' . $quadroId) . ');}catch(e){}</script>';
}

if ($quadroId === 0) {
    // ============================ Visão geral ============================
    $quadros = PluginKanbanQuadro::listarTodos();
    echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-layout-kanban me-2"></i>Quadros</h3>';
    echo '<div class="card-actions"><a class="btn btn-sm btn-primary" href="' . $acao . '?quadro=novo"><i class="ti ti-plus me-1"></i>Novo quadro</a></div></div>';
    if (!$quadros) {
        echo '<div class="card-body"><p class="text-secondary small mb-0"><i class="ti ti-info-circle me-1"></i>Nenhum quadro criado. Cada quadro tem as próprias fileiras, as pessoas com acesso e a integração com Chamados, Problemas e Mudanças.</p></div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-hover table-sm card-table kanban-cfg-tabela"><thead><tr>';
        echo '<th>Quadro</th><th>Entidade</th><th>Fileiras</th><th>Cards</th><th>Itens do GLPI</th><th>Situação</th><th></th></tr></thead><tbody>';
        foreach ($quadros as $q) {
            $colunas = PluginKanbanColuna::listar($q['id']);
            echo '<tr>';
            echo '<td><span class="kanban-cfg-cor" style="--kanban-cor:' . $e($q['cor']) . '"></span><a href="' . $acao . '?quadro=' . $q['id'] . '"><b>' . $e($q['name']) . '</b></a>'
                . ($q['comment'] !== '' && $q['comment'] !== null ? '<div class="small text-secondary">' . $e($q['comment']) . '</div>' : '') . '</td>';
            echo '<td>' . $e(PluginKanbanConfig::nomeEntidade((int) $q['entities_id'])) . ($q['is_recursive'] ? ' <small class="text-secondary">(e filhas)</small>' : '') . '</td>';
            echo '<td>';
            foreach ($colunas as $c) {
                echo '<span class="kanban-cfg-pilula" style="--kanban-cor:' . $e($c['cor']) . '">' . $e($c['name']) . '</span>';
            }
            echo '</td>';
            echo '<td>' . PluginKanbanQuadro::contarCards($q['id']) . '</td>';
            echo '<td>' . $e(implode(', ', array_map(fn($t) => PluginKanbanConfig::nomeItemtype($t, 2), $q['itemtypes'])) ?: '—') . '</td>';
            echo '<td>' . ($q['is_active'] ? '<span class="badge bg-green-lt">Ativo</span>' : '<span class="badge bg-secondary-lt">Inativo</span>') . '</td>';
            echo '<td class="text-end text-nowrap">';
            echo '<a class="btn btn-sm btn-ghost-secondary" href="' . $raiz . '/front/kanban.php?quadro=' . $q['id'] . '" title="Abrir quadro"><i class="ti ti-external-link"></i></a>';
            echo '<a class="btn btn-sm btn-ghost-primary" href="' . $acao . '?quadro=' . $q['id'] . '" title="Configurar"><i class="ti ti-settings"></i></a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Geral
    echo '<form method="post" action="' . $acao . '" class="card mt-3">';
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . $e($token) . '"><input type="hidden" name="save_action" value="salvar_geral">';
    echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-adjustments me-2"></i>Geral</h3></div>';
    echo '<div class="card-body"><div class="kanban-cfg-grade">';
    echo '<label class="kanban-cfg-campo"><span>Atualização automática do quadro (segundos)</span>'
        . '<input type="number" class="form-control form-control-sm" name="intervalo_atualizacao" min="5" max="300" value="' . PluginKanbanConfig::intervaloAtualizacao() . '">'
        . '<small>O quadro aberto confere mudanças nesse intervalo e só recarrega quando algo mudou.</small></label>';
    echo '<div class="kanban-cfg-campo"><span>E-mails</span>'
        . kanban_switch('email_ativo', PluginKanbanConfig::emailAtivo(), 'Enviar e-mails do Kanban', 'Usa o servidor de e-mail do GLPI; os eventos são escolhidos em cada quadro')
        . '</div>';
    echo '</div></div>';
    echo '<div class="card-footer text-end"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1"></i>Salvar</button></div>';
    echo '</form>';

    echo '<p class="text-secondary small mt-3"><i class="ti ti-info-circle me-1"></i>Administradores do GLPI (direito de atualizar a configuração) veem e configuram todos os quadros.</p>';
} else {
    // ============================ Editor do quadro ============================
    $quadro = $quadroId > 0 ? PluginKanbanQuadro::carregar($quadroId) : null;
    if ($quadroId > 0 && $quadro === null) {
        echo '<div class="alert alert-warning">Quadro não encontrado.</div>';
        echo '</div>';
        Html::footer();
        return;
    }
    $novo = $quadro === null;
    $q = $quadro ?? [
        'id' => 0, 'name' => '', 'comment' => '', 'entities_id' => (int) ($_SESSION['glpiactive_entity'] ?? 0), 'is_recursive' => 1,
        'is_active' => 1, 'ordem' => 0, 'cor' => '#206bc4', 'perfis' => [], 'usuarios' => [], 'grupos' => [],
        'itemtypes' => PluginKanbanConfig::ITEMTYPES, 'vinculo_auto' => '', 'categorias' => [], 'ticket_tipo' => 2,
        'atribuir_responsaveis' => 1, 'sincronizar_status' => 1, 'espelhar_comentarios' => 1, 'importar_followups' => 1,
        'followup_privado' => 0, 'notificacoes' => ['criacao' => 0, 'movimentacao' => 1, 'comentario' => 1, 'responsavel' => 1],
    ];
    $usuarios = PluginKanbanConfig::listarUsuarios();

    echo '<div class="kanban-cfg-topo">';
    echo '<a class="btn btn-sm btn-ghost-secondary" href="' . $acao . '"><i class="ti ti-arrow-left me-1"></i>Quadros</a>';
    echo '<h2>' . ($novo ? 'Novo quadro' : $e($q['name'])) . '</h2>';
    if (!$novo) {
        echo '<a class="btn btn-sm btn-ghost-secondary ms-auto" href="' . $raiz . '/front/kanban.php?quadro=' . $q['id'] . '"><i class="ti ti-external-link me-1"></i>Abrir quadro</a>';
    }
    echo '</div>';

    // -------- Dados, acesso, integração e notificações (um formulário) --------
    echo '<form method="post" action="' . $acao . ($novo ? '?quadro=novo' : '?quadro=' . $q['id']) . '" class="kanban-cfg-form">';
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . $e($token) . '"><input type="hidden" name="save_action" value="salvar_quadro">';
    echo '<input type="hidden" name="id" value="' . (int) $q['id'] . '">';
    echo '<div class="kanban-cfg-cards">';

    // Dados
    echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-id me-2"></i>Dados do quadro</h3></div><div class="card-body kanban-cfg-pilha">';
    echo '<label class="kanban-cfg-campo"><span>Nome</span><input type="text" class="form-control form-control-sm" name="name" maxlength="255" required value="' . $e($q['name']) . '"></label>';
    echo '<label class="kanban-cfg-campo"><span>Descrição curta</span><input type="text" class="form-control form-control-sm" name="comment" maxlength="255" value="' . $e($q['comment'] ?? '') . '" placeholder="Aparece ao lado do nome do quadro"></label>';
    echo '<div class="kanban-cfg-campo"><span>Cor</span>' . kanban_paleta('cor', PluginKanbanConfig::corValida($q['cor'], '#206bc4')) . '</div>';
    echo '<div class="kanban-cfg-campo"><span>Entidade</span>';
    Entity::dropdown(['name' => 'entities_id', 'value' => (int) $q['entities_id'], 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
    echo '</div>';
    echo '<div class="kanban-cfg-linha">';
    echo kanban_switch('is_recursive', (bool) $q['is_recursive'], 'Vale também para as entidades filhas');
    echo kanban_switch('is_active', (bool) $q['is_active'], 'Quadro ativo');
    echo '</div>';
    echo '<label class="kanban-cfg-campo kanban-cfg-curto"><span>Ordem no menu</span><input type="number" class="form-control form-control-sm" name="ordem" value="' . (int) $q['ordem'] . '"></label>';
    echo '</div></div>';

    // Acesso
    echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-shield-lock me-2"></i>Quem acessa</h3></div><div class="card-body kanban-cfg-pilha">';
    echo '<p class="text-secondary small mb-0"><i class="ti ti-info-circle me-1"></i>Quem acessa pode criar, mover, editar e comentar cards. Administradores do GLPI sempre acessam.</p>';
    echo '<label class="kanban-cfg-campo"><span>Perfis</span>' . kanban_multiselect('perfis[]', PluginKanbanConfig::listarPerfis(), $q['perfis'], 'Nenhum perfil') . '</label>';
    echo '<label class="kanban-cfg-campo"><span>Grupos</span>' . kanban_multiselect('grupos[]', PluginKanbanConfig::listarGrupos(), $q['grupos'], 'Nenhum grupo') . '</label>';
    echo '<label class="kanban-cfg-campo"><span>Usuários</span>' . kanban_multiselect('usuarios[]', $usuarios, $q['usuarios'], 'Nenhum usuário') . '</label>';
    echo '</div></div>';

    // Integração ITIL
    echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-link me-2"></i>Chamados, Problemas e Mudanças</h3></div><div class="card-body kanban-cfg-pilha">';
    echo '<div class="kanban-cfg-campo"><span>Itens que os cards podem vincular</span><div class="kanban-cfg-linha">';
    foreach (PluginKanbanConfig::ITEMTYPES as $tipo) {
        echo '<label class="form-check kanban-cfg-check"><input class="form-check-input" type="checkbox" name="itemtypes[]" value="' . $tipo . '"' . (in_array($tipo, $q['itemtypes'], true) ? ' checked' : '') . '>'
            . '<span class="form-check-label"><i class="' . PluginKanbanConfig::iconeItemtype($tipo) . '"></i> ' . PluginKanbanConfig::nomeItemtype($tipo, 2) . '</span></label>';
    }
    echo '</div></div>';
    echo '<label class="kanban-cfg-campo"><span>Ao criar um card</span><select class="form-select form-select-sm" name="vinculo_auto">';
    echo '<option value="">Não criar item automaticamente</option>';
    foreach (PluginKanbanConfig::ITEMTYPES as $tipo) {
        echo '<option value="' . $tipo . '"' . ($q['vinculo_auto'] === $tipo ? ' selected' : '') . '>Criar ' . mb_strtolower(PluginKanbanConfig::nomeItemtype($tipo)) . ' vinculado</option>';
    }
    echo '</select></label>';
    echo '<div class="kanban-cfg-grade">';
    $condicoes = ['Ticket' => [], 'Problem' => ['is_problem' => 1], 'Change' => ['is_change' => 1]];
    foreach (PluginKanbanConfig::ITEMTYPES as $tipo) {
        echo '<div class="kanban-cfg-campo"><span>Categoria para ' . mb_strtolower(PluginKanbanConfig::nomeItemtype($tipo, 2)) . ' criados</span>';
        ITILCategory::dropdown([
            'name'      => 'categoria_' . $tipo,
            'value'     => (int) ($q['categorias'][$tipo] ?? 0),
            'condition' => $condicoes[$tipo],
            'width'     => '100%',
        ]);
        echo '</div>';
    }
    echo '<label class="kanban-cfg-campo"><span>Tipo dos chamados criados</span><select class="form-select form-select-sm" name="ticket_tipo">'
        . '<option value="1"' . ((int) $q['ticket_tipo'] === 1 ? ' selected' : '') . '>Incidente</option>'
        . '<option value="2"' . ((int) $q['ticket_tipo'] === 2 ? ' selected' : '') . '>Requisição</option></select></label>';
    echo '</div>';
    echo '<div class="kanban-cfg-opcoes">';
    echo kanban_switch('sincronizar_status', (bool) $q['sincronizar_status'], 'Sincronizar status nos dois sentidos', 'Mover o card aplica o status da fileira; mudar o status no GLPI move o card');
    echo kanban_switch('espelhar_comentarios', (bool) $q['espelhar_comentarios'], 'Comentários e movimentações viram acompanhamentos', 'Registrados no item vinculado');
    echo kanban_switch('followup_privado', (bool) $q['followup_privado'], 'Acompanhamentos criados pelo Kanban são privados');
    echo kanban_switch('importar_followups', (bool) $q['importar_followups'], 'Trazer acompanhamentos e soluções do item para o card');
    echo kanban_switch('atribuir_responsaveis', (bool) $q['atribuir_responsaveis'], 'Responsáveis do card entram como técnicos do item');
    echo '</div>';
    echo '</div></div>';

    // Notificações
    echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-mail me-2"></i>E-mails</h3></div><div class="card-body kanban-cfg-pilha">';
    if (!PluginKanbanConfig::emailAtivo()) {
        echo '<div class="kanban-cfg-alerta"><i class="ti ti-alert-triangle"></i> Os e-mails do Kanban estão desligados em <a href="' . $acao . '">Geral</a>. Estas opções só valem depois de ligar.</div>';
    }
    echo '<p class="text-secondary small mb-0">Avisa o autor e os responsáveis do card (nunca quem fez a ação).</p>';
    foreach (PluginKanbanQuadro::EVENTOS as $evento => $rotulo) {
        echo kanban_switch('notificar_' . $evento, !empty($q['notificacoes'][$evento]), $rotulo);
    }
    echo '</div></div>';

    echo '</div>';
    echo '<div class="kanban-cfg-rodape">';
    echo '<button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1"></i>' . ($novo ? 'Criar quadro' : 'Salvar quadro') . '</button>';
    echo '</div>';
    echo '</form>';

    if (!$novo) {
        // -------- Fileiras --------
        $colunas = PluginKanbanColuna::listar($q['id']);
        echo '<form method="post" action="' . $acao . '?quadro=' . $q['id'] . '" class="card mt-3" id="kanban-cfg-fileiras">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $e($token) . '"><input type="hidden" name="save_action" value="salvar_colunas">';
        echo '<input type="hidden" name="quadro" value="' . $q['id'] . '">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-columns me-2"></i>Fileiras</h3>';
        echo '<div class="card-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-kanban-adicionar-fileira><i class="ti ti-plus me-1"></i>Adicionar fileira</button></div></div>';
        echo '<div class="card-body">';
        echo '<p class="text-secondary small"><i class="ti ti-info-circle me-1"></i>As fileiras aparecem no quadro nesta ordem (arraste pela alça ou use as setas). '
            . 'No sentido inverso, quando o status de um item vinculado muda no GLPI, o card vai para a primeira fileira que tem aquele status. '
            . 'Fileiras removidas passam os cards para a primeira fileira.</p>';
        echo '<div class="kanban-fileiras" data-kanban-fileiras>';
        foreach ($colunas as $c) {
            echo kanban_linha_coluna((string) $c['id'], $c, $usuarios, [
                'coluna_aprovado' => $c['coluna_aprovado'] > 0 ? (string) $c['coluna_aprovado'] : '',
                'coluna_recusado' => $c['coluna_recusado'] > 0 ? (string) $c['coluna_recusado'] : '',
            ]);
        }
        echo '</div>';
        echo '<template id="kanban-modelo-fileira">' . kanban_linha_coluna('__CHAVE__', [
            'name' => '', 'cor' => '#667382', 'limite_wip' => 0, 'is_final' => 0, 'pedir_comentario' => 0,
            'status_ticket' => 0, 'status_problem' => 0, 'status_change' => 0, 'validacao_ativa' => 0, 'validadores' => [],
        ], $usuarios, []) . '</template>';
        echo '</div>';
        echo '<div class="card-footer text-end"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1"></i>Salvar fileiras</button></div>';
        echo '</form>';

        // -------- Duplicar / excluir --------
        echo '<div class="card mt-3"><div class="card-body kanban-cfg-perigo">';
        echo '<form method="post" action="' . $acao . '?quadro=' . $q['id'] . '"><input type="hidden" name="_glpi_csrf_token" value="' . $e($token) . '">'
            . '<input type="hidden" name="save_action" value="duplicar_quadro"><input type="hidden" name="quadro" value="' . $q['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-copy me-1"></i>Duplicar quadro (sem cards)</button></form>';
        echo '<form method="post" action="' . $acao . '" data-kanban-confirmar="Enviar o quadro &laquo;' . $e($q['name']) . '&raquo; para a lixeira? Os cards continuam guardados no banco.">'
            . '<input type="hidden" name="_glpi_csrf_token" value="' . $e($token) . '">'
            . '<input type="hidden" name="save_action" value="excluir_quadro"><input type="hidden" name="quadro" value="' . $q['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash me-1"></i>Excluir quadro</button></form>';
        echo '</div></div>';
    }
}

// Confirmação (sem caixas do navegador)
echo '<div class="modal fade" id="kanban-cfg-confirmar" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">';
echo '<div class="modal-body"><div class="kanban-cfg-confirmar"><i class="ti ti-alert-triangle"></i><p></p></div></div>';
echo '<div class="modal-footer"><button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-danger" data-kanban-confirmar-sim>Confirmar</button></div>';
echo '</div></div></div>';

echo '</div>';

Html::footer();
