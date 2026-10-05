<?php

/**
 * Plugin Kanban - quadro
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

if (!PluginKanbanMenu::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$raiz    = $CFG_GLPI['root_doc'] . '/plugins/kanban';
$quadros = PluginKanbanQuadro::quadrosAcessiveis();
$atual   = (int) ($_GET['quadro'] ?? 0);
if (!isset($quadros[$atual])) {
    $atual = (int) array_key_first($quadros);
}
$e = [PluginKanbanConfig::class, 'e'];

// A biblioteca do editor precisa estar na página antes do cabeçalho
Html::requireJs('tinymce');

Html::header('Kanban', $_SERVER['PHP_SELF'], 'tools', 'PluginKanbanMenu', 'quadros');

echo '<link rel="stylesheet" href="' . $raiz . '/css/kanban.css?v=' . PLUGIN_KANBAN_VERSION . '">';

if (!$quadros) {
    echo '<div class="kanban-pagina"><div class="kanban-sem-quadro card"><div class="card-body text-center">';
    echo '<i class="ti ti-layout-kanban"></i><h3>Nenhum quadro disponível</h3>';
    if (PluginKanbanConfig::ehAdmin()) {
        echo '<p class="text-secondary">Crie o primeiro quadro e as fileiras dele na configuração do plugin.</p>';
        echo '<a class="btn btn-primary" href="' . $raiz . '/front/config.form.php?quadro=novo"><i class="ti ti-plus me-1"></i>Criar quadro</a>';
    } else {
        echo '<p class="text-secondary">Você ainda não tem acesso a nenhum quadro. Peça acesso a um administrador do GLPI.</p>';
    }
    echo '</div></div></div>';
    Html::footer();
    return;
}

$config = [
    'ajax'       => $raiz . '/front/ajax.php',
    'pagina'     => $raiz . '/front/kanban.php',
    'config'     => PluginKanbanConfig::ehAdmin() ? $raiz . '/front/config.form.php?quadro=' . $atual : '',
    'token'      => PluginKanbanConfig::tokenCsrf(),
    'quadro'     => $atual,
    'card'       => (int) ($_GET['card'] ?? 0),
    'intervalo'  => PluginKanbanConfig::intervaloAtualizacao(),
    'eu'         => (int) Session::getLoginUserID(),
    'prioridades' => PluginKanbanConfig::prioridades(),
    'quadros'    => array_values(array_map(fn($q) => ['id' => $q['id'], 'nome' => $q['name'], 'cor' => $q['cor']], $quadros)),
];

echo '<div class="kanban-pagina" id="kanban-app" data-config="' . $e(json_encode($config)) . '">';

// Barra do quadro
echo '<div class="kanban-barra">';
echo '<div class="kanban-barra-quadro">';
if (count($quadros) > 1) {
    echo '<div class="dropdown">';
    echo '<button class="kanban-quadro-nome dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
        . '<span class="kanban-quadro-cor" style="--kanban-cor:' . $e($quadros[$atual]['cor']) . '"></span>' . $e($quadros[$atual]['name']) . '</button>';
    echo '<ul class="dropdown-menu">';
    foreach ($quadros as $q) {
        echo '<li><a class="dropdown-item' . ($q['id'] === $atual ? ' active' : '') . '" href="' . $raiz . '/front/kanban.php?quadro=' . $q['id'] . '">'
            . '<span class="kanban-quadro-cor" style="--kanban-cor:' . $e($q['cor']) . '"></span>' . $e($q['name']) . '</a></li>';
    }
    echo '</ul></div>';
} else {
    echo '<span class="kanban-quadro-nome"><span class="kanban-quadro-cor" style="--kanban-cor:' . $e($quadros[$atual]['cor']) . '"></span>' . $e($quadros[$atual]['name']) . '</span>';
}
if (trim((string) $quadros[$atual]['comment']) !== '') {
    echo '<span class="kanban-quadro-descricao">' . $e($quadros[$atual]['comment']) . '</span>';
}
echo '</div>';

echo '<div class="kanban-barra-filtros">';
echo '<div class="kanban-pesquisa"><i class="ti ti-search"></i><input type="search" class="form-control form-control-sm" id="kanban-pesquisa" placeholder="Pesquisar cards..."></div>';
echo '<select class="form-select form-select-sm" id="kanban-filtro-prioridade" title="Prioridade"><option value="">Todas as prioridades</option>';
foreach (PluginKanbanConfig::prioridades() as $valor => $rotulo) {
    echo '<option value="' . $valor . '">' . $rotulo . '</option>';
}
echo '</select>';
echo '<select class="form-select form-select-sm" id="kanban-filtro-vinculo" title="Vínculo"><option value="">Com e sem vínculo</option><option value="com">Com vínculo</option><option value="sem">Sem vínculo</option></select>';
echo '<label class="form-check form-switch kanban-filtro-meus"><input class="form-check-input" type="checkbox" id="kanban-filtro-meus"><span class="form-check-label">Só os meus</span></label>';
echo '</div>';

echo '<div class="kanban-barra-acoes">';
echo '<span class="kanban-sinc" id="kanban-sinc" title="Atualização automática"><i class="ti ti-refresh"></i><span>—</span></span>';
if ($config['config'] !== '') {
    echo '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($config['config']) . '" title="Configurar este quadro"><i class="ti ti-settings"></i></a>';
}
echo '<button type="button" class="btn btn-sm btn-primary" data-kanban-novo><i class="ti ti-plus me-1"></i>Novo card</button>';
echo '</div>';
echo '</div>';

// Fileiras (desenhadas pelo kanban.js)
echo '<div class="kanban-quadro" id="kanban-quadro"><div class="kanban-carregando"><i class="ti ti-loader-2"></i> Carregando o quadro...</div></div>';

// Editor-base: a configuração nativa do GLPI é copiada para os editores dos modais
echo '<div class="kanban-editor-base" hidden>';
Html::textarea([
    'name'            => 'kanban_editor_base',
    'editor_id'       => 'kanban-editor-base',
    'enable_richtext' => true,
    'enable_images'   => true,
    'rows'            => 2,
]);
echo '</div>';

// Modal do card
echo '<div class="modal fade" id="kanban-modal-card" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">';
echo '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">';
echo '<div class="modal-header"><h5 class="modal-title" id="kanban-modal-card-titulo">Card</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>';
echo '<div class="modal-body" id="kanban-modal-card-corpo"></div>';
echo '<div class="modal-footer">';
echo '<button type="button" class="btn btn-ghost-danger me-auto" data-kanban-excluir hidden><i class="ti ti-trash me-1"></i>Excluir card</button>';
echo '<button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Fechar</button>';
echo '<button type="button" class="btn btn-primary" data-kanban-salvar><i class="ti ti-device-floppy me-1"></i>Salvar</button>';
echo '</div></div></div></div>';

// Modal: comentário ao mover (fileiras que pedem comentário)
echo '<div class="modal fade" id="kanban-modal-mover" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">';
echo '<div class="modal-dialog modal-lg"><div class="modal-content">';
echo '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-message me-2"></i>Comentário da movimentação</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>';
echo '<div class="modal-body"><p class="text-secondary small" id="kanban-modal-mover-texto"></p><textarea id="kanban-comentario-mover" class="kanban-editor" rows="4"></textarea></div>';
echo '<div class="modal-footer"><button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button>'
    . '<button type="button" class="btn btn-primary" data-kanban-confirmar-mover><i class="ti ti-arrows-right-left me-1"></i>Mover</button></div>';
echo '</div></div></div>';

// Modal de confirmação (sem caixas do navegador)
echo '<div class="modal fade" id="kanban-modal-confirmar" tabindex="-1" aria-hidden="true">';
echo '<div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">';
echo '<div class="modal-body"><div class="kanban-confirmar"><i class="ti ti-alert-triangle"></i><p id="kanban-modal-confirmar-texto"></p></div></div>';
echo '<div class="modal-footer"><button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button>'
    . '<button type="button" class="btn btn-danger" data-kanban-confirmar-sim>Confirmar</button></div>';
echo '</div></div></div>';

echo '</div>';

echo '<script src="' . $raiz . '/js/kanban.js?v=' . PLUGIN_KANBAN_VERSION . '"></script>';

Html::footer();
