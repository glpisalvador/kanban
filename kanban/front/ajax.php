<?php

/**
 * Plugin Kanban - endpoint AJAX (JSON).
 * Leituras por GET (não consomem token CSRF); alterações por POST.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno ao processar a solicitação.']);
    }
});

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

while (ob_get_level() > 0) {
    ob_end_clean();
}

function kanban_json(array $dados): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $dados['new_token'] = PluginKanbanConfig::tokenCsrf();
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function kanban_erro(string $mensagem): never
{
    kanban_json(['success' => false, 'mensagem' => $mensagem]);
}

/** Card do quadro que o usuário pode abrir, ou encerra com erro */
function kanban_card_acessivel(int $id): array
{
    $card = PluginKanbanCard::carregar($id);
    if ($card === null || !PluginKanbanCard::podeAcessar($id)) {
        kanban_erro('Card não encontrado ou sem acesso.');
    }
    return $card;
}

Session::checkLoginUser();
if (!PluginKanbanMenu::canView()) {
    kanban_erro('Sem acesso ao Kanban.');
}

$acao = (string) ($_REQUEST['action'] ?? '');
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$somenteLeitura = ['quadro', 'versao', 'card_formulario', 'linha_do_tempo', 'buscar_usuarios', 'buscar_entidades', 'buscar_itens', 'anexo'];
if (!$post && !in_array($acao, $somenteLeitura, true)) {
    kanban_erro('Método não permitido.');
}

switch ($acao) {

    // ------------------------------------------------------------------ leituras
    case 'quadro':
        $quadro = PluginKanbanQuadro::carregar((int) ($_GET['id'] ?? 0));
        if (!PluginKanbanQuadro::podeAcessar($quadro)) {
            kanban_erro('Quadro não encontrado ou sem acesso.');
        }
        $colunas = [];
        foreach (PluginKanbanColuna::listar($quadro['id']) as $c) {
            $colunas[] = [
                'id'               => $c['id'],
                'nome'             => $c['name'],
                'cor'              => $c['cor'],
                'limite'           => $c['limite_wip'],
                'final'            => (bool) $c['is_final'],
                'pedir_comentario' => (bool) $c['pedir_comentario'],
                'validacao'        => (bool) $c['validacao_ativa'],
            ];
        }
        kanban_json([
            'success' => true,
            'quadro'  => ['id' => $quadro['id'], 'nome' => $quadro['name'], 'cor' => $quadro['cor'], 'descricao' => $quadro['comment']],
            'colunas' => $colunas,
            'cards'   => PluginKanbanCard::listarDoQuadro($quadro['id']),
            'versao'  => PluginKanbanQuadro::versao($quadro['id']),
            'hoje'    => date('Y-m-d'),
        ]);

    case 'versao':
        $quadro = PluginKanbanQuadro::carregar((int) ($_GET['id'] ?? 0));
        if (!PluginKanbanQuadro::podeAcessar($quadro)) {
            kanban_erro('Quadro não encontrado ou sem acesso.');
        }
        kanban_json(['success' => true, 'versao' => PluginKanbanQuadro::versao($quadro['id'])]);

    case 'card_formulario':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
            $card = kanban_card_acessivel($id);
            $quadro = PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']);
            PluginKanbanVisualizacao::registrar($id);
            kanban_json(['success' => true, 'titulo' => '#' . $id . ' ' . $card['titulo'], 'html' => PluginKanbanCard::renderFormulario($quadro, $card)]);
        }
        $quadro = PluginKanbanQuadro::carregar((int) ($_GET['quadro'] ?? 0));
        if (!PluginKanbanQuadro::podeAcessar($quadro)) {
            kanban_erro('Quadro não encontrado ou sem acesso.');
        }
        kanban_json(['success' => true, 'titulo' => 'Novo card', 'html' => PluginKanbanCard::renderFormulario($quadro, null, (int) ($_GET['coluna'] ?? 0))]);

    case 'linha_do_tempo':
        $card = kanban_card_acessivel((int) ($_GET['id'] ?? 0));
        kanban_json(['success' => true, 'html' => PluginKanbanComentario::renderLinhaDoTempo($card['id']), 'anexos' => PluginKanbanCard::renderAnexos($card['id'])]);

    case 'buscar_usuarios':
        kanban_json(['success' => true, 'itens' => PluginKanbanConfig::buscarUsuarios((string) ($_GET['termo'] ?? ''))]);

    case 'buscar_entidades':
        kanban_json(['success' => true, 'itens' => PluginKanbanConfig::buscarEntidades((string) ($_GET['termo'] ?? ''))]);

    case 'buscar_itens':
        $tipo = (string) ($_GET['tipo'] ?? '');
        kanban_json(['success' => true, 'itens' => PluginKanbanVinculo::buscarItens($tipo, (string) ($_GET['termo'] ?? ''))]);

    case 'anexo':
        $card = kanban_card_acessivel((int) ($_GET['card'] ?? 0));
        PluginKanbanAnexo::enviarArquivo($card['id'], (int) ($_GET['doc'] ?? 0));
        exit;

    // ------------------------------------------------------------------ alterações
    case 'card_criar':
        [$id, $mensagem] = PluginKanbanCard::criar($_POST);
        if ($id <= 0) {
            kanban_erro($mensagem);
        }
        kanban_json(['success' => true, 'id' => $id, 'mensagem' => $mensagem !== '' ? $mensagem : 'Card criado.']);

    case 'card_salvar':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        $erro = PluginKanbanCard::atualizar($card['id'], $_POST);
        if ($erro !== '') {
            kanban_erro($erro);
        }
        kanban_json(['success' => true, 'mensagem' => 'Card salvo.']);

    case 'card_excluir':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        PluginKanbanCard::excluir($card['id']);
        kanban_json(['success' => true, 'mensagem' => 'Card excluído.']);

    case 'mover':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        $r = PluginKanbanCard::mover($card['id'], (int) ($_POST['coluna'] ?? 0), (int) ($_POST['posicao'] ?? 0), (string) ($_POST['comentario'] ?? ''));
        kanban_json([
            'success'          => $r['ok'],
            'mensagem'         => $r['mensagem'],
            'pedir_comentario' => !empty($r['pedir_comentario']),
        ]);

    case 'comentar':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        [$id, $erro] = PluginKanbanComentario::comentar($card['id'], (string) ($_POST['conteudo'] ?? ''));
        if ($id <= 0) {
            kanban_erro($erro);
        }
        kanban_json(['success' => true, 'html' => PluginKanbanComentario::renderLinhaDoTempo($card['id'])]);

    case 'comentario_excluir':
        $erro = PluginKanbanComentario::excluir((int) ($_POST['comentario'] ?? 0));
        if ($erro !== '') {
            kanban_erro($erro);
        }
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        kanban_json(['success' => true, 'html' => PluginKanbanComentario::renderLinhaDoTempo($card['id'])]);

    case 'anexar':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        if (empty($_FILES['arquivos'])) {
            kanban_erro(empty($_POST) ? 'Os arquivos são maiores que o permitido pelo servidor (' . ini_get('post_max_size') . ').' : 'Nenhum arquivo enviado.');
        }
        [$total, $falhas] = PluginKanbanAnexo::receber($card['id'], $_FILES['arquivos']);
        kanban_json([
            'success'  => $total > 0,
            'mensagem' => $falhas ? implode(' | ', $falhas) : $total . ' anexo(s) adicionado(s).',
            'anexos'   => PluginKanbanCard::renderAnexos($card['id']),
            'html'     => PluginKanbanComentario::renderLinhaDoTempo($card['id']),
        ]);

    case 'anexo_remover':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        $erro = PluginKanbanAnexo::remover($card['id'], (int) ($_POST['doc'] ?? 0));
        if ($erro !== '') {
            kanban_erro($erro);
        }
        kanban_json(['success' => true, 'anexos' => PluginKanbanCard::renderAnexos($card['id'])]);

    case 'vincular':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        [$ok, $mensagem] = PluginKanbanVinculo::vincular($card['id'], (string) ($_POST['tipo'] ?? ''), (int) ($_POST['item'] ?? 0));
        if (!$ok) {
            kanban_erro($mensagem);
        }
        $novo = PluginKanbanCard::carregar($card['id']);
        $coluna = PluginKanbanColuna::carregar($novo['plugin_kanban_colunas_id']);
        if ($coluna !== null) {
            PluginKanbanVinculo::aoEntrarNaColuna($card['id'], $coluna);
        }
        kanban_json(['success' => true, 'mensagem' => $mensagem]);

    case 'gerar_item':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        [$id, $mensagem] = PluginKanbanVinculo::gerarItem($card['id'], (string) ($_POST['tipo'] ?? ''));
        if ($id <= 0) {
            kanban_erro($mensagem);
        }
        $coluna = PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']);
        if ($coluna !== null) {
            PluginKanbanVinculo::aoEntrarNaColuna($card['id'], $coluna);
        }
        kanban_json(['success' => true, 'mensagem' => $mensagem]);

    case 'desvincular':
        $card = kanban_card_acessivel((int) ($_POST['id'] ?? 0));
        PluginKanbanVinculo::desvincular($card['id']);
        kanban_json(['success' => true, 'mensagem' => 'Vínculo removido.']);

    case 'adicionar_item':
        [$id, $mensagem] = PluginKanbanVinculo::adicionarItemAoQuadro(
            (string) ($_POST['itemtype'] ?? ''),
            (int) ($_POST['items_id'] ?? 0),
            (int) ($_POST['quadro'] ?? 0),
            (int) ($_POST['coluna'] ?? 0)
        );
        if ($id <= 0) {
            kanban_erro($mensagem);
        }
        kanban_json(['success' => true, 'id' => $id, 'mensagem' => $mensagem]);

    default:
        kanban_erro('Ação desconhecida.');
}
