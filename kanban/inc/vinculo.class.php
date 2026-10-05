<?php

/**
 * Plugin Kanban - interligação com Chamados, Problemas e Mudanças.
 *
 * Quadro -> GLPI: mover o card aplica o status configurado na fileira, comentários viram
 * acompanhamentos, responsáveis viram técnicos e fileiras podem pedir validação nativa.
 * GLPI -> quadro: mudança de status move o card para a fileira mapeada; acompanhamentos,
 * soluções e respostas de validação aparecem na linha do tempo do card.
 * Uma trava evita eco: o que o próprio plugin faz no item não volta para o card.
 */
class PluginKanbanVinculo extends CommonGLPI
{
    /** Trava de eco: > 0 enquanto o plugin altera itens do GLPI */
    private static int $travado = 0;

    public static function getTypeName($nb = 0): string
    {
        return 'Kanban';
    }

    private static function semEco(callable $acao)
    {
        self::$travado++;
        try {
            return $acao();
        } finally {
            self::$travado--;
        }
    }

    private static function ecoDoPlugin(CommonDBTM $item): bool
    {
        return self::$travado > 0 || !empty($item->input['_plugin_kanban']);
    }

    // =====================================================================
    // Itens vinculados
    // =====================================================================

    public static function itemExiste(string $itemtype, int $items_id): bool
    {
        global $DB;
        if (!in_array($itemtype, PluginKanbanConfig::ITEMTYPES, true) || $items_id <= 0) {
            return false;
        }
        return count($DB->request(['FROM' => $itemtype::getTable(), 'WHERE' => ['id' => $items_id, 'is_deleted' => 0], 'LIMIT' => 1])) > 0;
    }

    /** Cards ativos vinculados ao item */
    public static function cardsDoItem(string $itemtype, int $items_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => PluginKanbanCard::getTable(),
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id, 'is_deleted' => 0],
        ]) as $r) {
            $card = PluginKanbanCard::carregar((int) $r['id']);
            if ($card !== null) {
                $lista[] = $card;
            }
        }
        return $lista;
    }

    private static function tituloItem(string $itemtype, int $items_id): string
    {
        return PluginKanbanConfig::nomeItemtype($itemtype) . ' #' . $items_id;
    }

    /** Vincula o card a um item existente; o card passa a acompanhar o status do item */
    public static function vincular(int $cards_id, string $itemtype, int $items_id): array
    {
        global $DB;
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null) {
            return [false, 'Card não encontrado.'];
        }
        if (!in_array($itemtype, $quadro['itemtypes'], true)) {
            return [false, 'Este quadro não aceita vínculo com ' . PluginKanbanConfig::nomeItemtype($itemtype, 2) . '.'];
        }
        $item = new $itemtype();
        if (!self::itemExiste($itemtype, $items_id) || !$item->can($items_id, READ)) {
            return [false, self::tituloItem($itemtype, $items_id) . ' não encontrado ou sem permissão.'];
        }

        $DB->update(PluginKanbanCard::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id], ['id' => $cards_id]);
        PluginKanbanComentario::registrar($cards_id, 'sistema', 'Vinculado a <b>' . self::tituloItem($itemtype, $items_id) . '</b>.', (int) Session::getLoginUserID());

        $mensagem = 'Card vinculado a ' . self::tituloItem($itemtype, $items_id) . '.';
        if ($quadro['sincronizar_status']) {
            $coluna = PluginKanbanColuna::colunaParaStatus($quadro['id'], $itemtype, (int) $item->fields['status']);
            if ($coluna !== null && $coluna['id'] !== $card['plugin_kanban_colunas_id']) {
                PluginKanbanCard::moverAutomatico($cards_id, $coluna, 'acompanhando o status atual do item (' . PluginKanbanConfig::nomeStatus($itemtype, (int) $item->fields['status']) . ').');
                $mensagem .= ' O card foi para a fileira "' . $coluna['name'] . '", que corresponde ao status atual do item.';
            }
        }
        return [true, $mensagem];
    }

    /** Cria um Chamado/Problema/Mudança a partir do card e vincula */
    public static function gerarItem(int $cards_id, string $itemtype): array
    {
        global $DB;
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null) {
            return [0, 'Card não encontrado.'];
        }
        if (!in_array($itemtype, $quadro['itemtypes'], true)) {
            return [0, 'Este quadro não aceita ' . PluginKanbanConfig::nomeItemtype($itemtype, 2) . '.'];
        }
        if (!$itemtype::canCreate()) {
            return [0, 'Seu perfil não pode criar ' . PluginKanbanConfig::nomeItemtype($itemtype, 2) . '.'];
        }

        $descricao = (string) $card['descricao'];
        if (PluginKanbanConfig::richtextVazio($descricao)) {
            $descricao = '<p>' . PluginKanbanConfig::e($card['titulo']) . '</p>';
        }
        $descricao .= '<p style="color:#6c757d;font-size:12px;">Criado a partir do card #' . $cards_id
            . ' do quadro Kanban &laquo;' . PluginKanbanConfig::e($quadro['name']) . '&raquo;.</p>';

        $entrada = [
            'entities_id'         => $card['entities_id'],
            'name'                => $card['titulo'],
            'content'             => $descricao,
            '_users_id_requester' => $card['users_id'] ?: (int) Session::getLoginUserID(),
            '_plugin_kanban'      => true,
        ];
        $categoria = (int) ($quadro['categorias'][$itemtype] ?? 0);
        if ($categoria > 0) {
            $entrada['itilcategories_id'] = $categoria;
        }
        if ($itemtype === 'Ticket') {
            $entrada['type'] = (int) $quadro['ticket_tipo'];
        }
        if ($quadro['atribuir_responsaveis']) {
            $resp = PluginKanbanCard::responsaveisIds($cards_id);
            if ($resp) {
                $entrada['_users_id_assign'] = $resp;
            }
        }
        $coluna = PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']);
        $campo = PluginKanbanConfig::campoStatus($itemtype);
        $statusColuna = $coluna ? (int) $coluna[$campo] : 0;
        $solucionado = in_array($statusColuna, array_merge($itemtype::getSolvedStatusArray(), $itemtype::getClosedStatusArray()), true);
        if ($quadro['sincronizar_status'] && $statusColuna > 0 && !$solucionado) {
            $entrada['status'] = $statusColuna;
        }

        $novoId = (int) self::semEco(function () use ($itemtype, $entrada) {
            $item = new $itemtype();
            return $item->add($entrada);
        });
        if ($novoId <= 0) {
            return [0, 'Não foi possível criar o ' . mb_strtolower(PluginKanbanConfig::nomeItemtype($itemtype)) . '. Confira os campos obrigatórios do GLPI.'];
        }

        $DB->update(PluginKanbanCard::getTable(), ['itemtype' => $itemtype, 'items_id' => $novoId], ['id' => $cards_id]);
        PluginKanbanComentario::registrar($cards_id, 'sistema', '<b>' . self::tituloItem($itemtype, $novoId) . '</b> criado e vinculado ao card.', (int) Session::getLoginUserID());
        $mensagem = self::tituloItem($itemtype, $novoId) . ' criado e vinculado.';

        // O GLPI pode ajustar o status na criação (ex.: técnico atribuído -> "Em atendimento").
        // O item manda: o card vai para a fileira do status real, se houver uma.
        $criado = new $itemtype();
        if ($quadro['sincronizar_status'] && $solucionado && $coluna !== null) {
            // Card criado direto numa fileira de solução: o item nasce e já é solucionado
            $erro = self::aplicarStatus($itemtype, $novoId, $statusColuna, '', $quadro['name'], $coluna['name']);
            if ($erro !== '') {
                $mensagem .= ' ' . $erro;
            }
        } elseif ($quadro['sincronizar_status'] && $criado->getFromDB($novoId)) {
            $real = (int) $criado->fields['status'];
            if ($real !== $statusColuna) {
                $destino = PluginKanbanColuna::colunaParaStatus($quadro['id'], $itemtype, $real);
                if ($destino !== null && $destino['id'] !== $card['plugin_kanban_colunas_id']) {
                    PluginKanbanCard::moverAutomatico($cards_id, $destino, 'o GLPI criou ' . self::tituloItem($itemtype, $novoId)
                        . ' como "' . PluginKanbanConfig::nomeStatus($itemtype, $real) . '".');
                    $mensagem .= ' O GLPI criou o item como "' . PluginKanbanConfig::nomeStatus($itemtype, $real) . '", então o card foi para "' . $destino['name'] . '".';
                }
            }
        }
        return [$novoId, $mensagem];
    }

    public static function desvincular(int $cards_id): void
    {
        global $DB;
        $card = PluginKanbanCard::carregar($cards_id);
        if ($card === null || $card['items_id'] <= 0) {
            return;
        }
        $DB->update(PluginKanbanCard::getTable(), ['itemtype' => '', 'items_id' => 0, 'validacao_status' => 0, 'validacao_colunas_id' => 0], ['id' => $cards_id]);
        PluginKanbanComentario::registrar($cards_id, 'sistema', 'Vínculo com <b>' . self::tituloItem($card['itemtype'], $card['items_id']) . '</b> removido.', (int) Session::getLoginUserID());
    }

    // =====================================================================
    // Quadro -> GLPI
    // =====================================================================

    /** Após mover: status do item, acompanhamento e validação. Devolve avisos para a tela. */
    public static function aoMoverCard(int $cards_id, ?array $origem, array $destino, string $comentario): string
    {
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null) {
            return '';
        }
        $avisos = [];

        if ($card['items_id'] > 0 && self::itemExiste($card['itemtype'], $card['items_id'])) {
            $tipo = $card['itemtype'];
            $campo = PluginKanbanConfig::campoStatus($tipo);
            $novoStatus = (int) ($destino[$campo] ?? 0);

            if ($quadro['espelhar_comentarios']) {
                $texto = '<p>Card movido no quadro Kanban &laquo;' . PluginKanbanConfig::e($quadro['name']) . '&raquo;: <b>'
                    . PluginKanbanConfig::e($origem['name'] ?? '—') . '</b> &rarr; <b>' . PluginKanbanConfig::e($destino['name']) . '</b>.</p>';
                if (!PluginKanbanConfig::richtextVazio($comentario)) {
                    $texto .= $comentario;
                }
                self::adicionarAcompanhamento($tipo, $card['items_id'], $texto, (bool) $quadro['followup_privado']);
            }

            if ($quadro['sincronizar_status'] && $novoStatus > 0) {
                $erro = self::aplicarStatus($tipo, $card['items_id'], $novoStatus, $comentario, $quadro['name'], $destino['name']);
                if ($erro !== '') {
                    $avisos[] = $erro;
                }
            }
        }

        $aviso = self::aoEntrarNaColuna($cards_id, $destino);
        if ($aviso !== '') {
            $avisos[] = $aviso;
        }
        return implode(' ', $avisos);
    }

    /**
     * Aplica o status no item pelo método nativo (gera histórico). Status de solução usa uma
     * solução nativa; os campos de "levado em conta" do chamado são preservados.
     */
    public static function aplicarStatus(string $itemtype, int $items_id, int $status, string $comentario, string $quadroNome, string $colunaNome): string
    {
        global $DB;
        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return '';
        }
        $atual = (int) $item->fields['status'];
        if ($atual === $status) {
            return '';
        }

        $preservar = [];
        if ($itemtype === 'Ticket') {
            $preservar = [
                'takeintoaccountdate'        => $item->fields['takeintoaccountdate'] ?? null,
                'takeintoaccount_delay_stat' => $item->fields['takeintoaccount_delay_stat'] ?? 0,
            ];
        }

        $solucao    = $itemtype::getSolvedStatusArray();
        $fechamento = $itemtype::getClosedStatusArray();

        self::semEco(function () use ($itemtype, $items_id, $status, $atual, $comentario, $quadroNome, $colunaNome, $solucao, $fechamento) {
            $item = new $itemtype();
            $jaSolucionado = in_array($atual, array_merge($solucao, $fechamento), true);
            if ((in_array($status, $solucao, true) || in_array($status, $fechamento, true)) && !$jaSolucionado) {
                $texto = !PluginKanbanConfig::richtextVazio($comentario)
                    ? $comentario
                    : '<p>Solucionado ao mover o card para a fileira &laquo;' . PluginKanbanConfig::e($colunaNome)
                        . '&raquo; do quadro Kanban &laquo;' . PluginKanbanConfig::e($quadroNome) . '&raquo;.</p>';
                $sol = new ITILSolution();
                $sol->add([
                    'itemtype'       => $itemtype,
                    'items_id'       => $items_id,
                    'content'        => $texto,
                    '_plugin_kanban' => true,
                ]);
            }
            $item->getFromDB($items_id);
            if ((int) $item->fields['status'] !== $status) {
                $item->update(['id' => $items_id, 'status' => $status, '_plugin_kanban' => true]);
            }
        });

        if ($preservar) {
            $DB->update('glpi_tickets', $preservar, ['id' => $items_id]);
        }

        $item->getFromDB($items_id);
        if ((int) $item->fields['status'] !== $status) {
            return 'O GLPI não aceitou mudar ' . self::tituloItem($itemtype, $items_id) . ' para "'
                . PluginKanbanConfig::nomeStatus($itemtype, $status) . '" (regras do fluxo ou permissões).';
        }
        return '';
    }

    /** Acompanhamento no item (marcado para não voltar ao card) */
    public static function adicionarAcompanhamento(string $itemtype, int $items_id, string $conteudo, bool $privado = false): int
    {
        return (int) self::semEco(function () use ($itemtype, $items_id, $conteudo, $privado) {
            $f = new ITILFollowup();
            return $f->add([
                'itemtype'       => $itemtype,
                'items_id'       => $items_id,
                'content'        => $conteudo,
                'is_private'     => $privado ? 1 : 0,
                'users_id'       => (int) Session::getLoginUserID(),
                '_plugin_kanban' => true,
            ]);
        });
    }

    /** Comentário do card vira acompanhamento no item vinculado. Devolve o id do acompanhamento. */
    public static function espelharComentario(int $cards_id, string $conteudo, int $users_id): int
    {
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null || !$quadro['espelhar_comentarios']) {
            return 0;
        }
        if ($card['items_id'] <= 0 || !self::itemExiste($card['itemtype'], $card['items_id'])) {
            return 0;
        }
        $texto = $conteudo . '<p style="color:#6c757d;font-size:11px;">Comentário do card #' . $cards_id
            . ' no quadro Kanban &laquo;' . PluginKanbanConfig::e($quadro['name']) . '&raquo;.</p>';
        return self::adicionarAcompanhamento($card['itemtype'], $card['items_id'], $texto, (bool) $quadro['followup_privado']);
    }

    /** Novos responsáveis do card entram como técnicos do item */
    public static function atribuirResponsaveis(int $cards_id, array $users_ids): void
    {
        global $DB;
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null || !$quadro['atribuir_responsaveis'] || $card['items_id'] <= 0) {
            return;
        }
        $classes = ['Ticket' => ['Ticket_User', 'tickets_id'], 'Problem' => ['Problem_User', 'problems_id'], 'Change' => ['Change_User', 'changes_id']];
        [$classe, $fk] = $classes[$card['itemtype']] ?? [null, null];
        if ($classe === null || !self::itemExiste($card['itemtype'], $card['items_id'])) {
            return;
        }
        foreach ($users_ids as $uid) {
            $ja = count($DB->request([
                'FROM'  => $classe::getTable(),
                'WHERE' => [$fk => $card['items_id'], 'users_id' => (int) $uid, 'type' => CommonITILActor::ASSIGN],
                'LIMIT' => 1,
            ])) > 0;
            if (!$ja) {
                self::semEco(function () use ($classe, $fk, $card, $uid) {
                    $rel = new $classe();
                    $rel->add([$fk => $card['items_id'], 'users_id' => (int) $uid, 'type' => CommonITILActor::ASSIGN, '_plugin_kanban' => true]);
                });
            }
        }
    }

    /** Fileira com validação: pede validação nativa aos validadores configurados */
    public static function aoEntrarNaColuna(int $cards_id, array $coluna): string
    {
        global $DB;
        if (!$coluna['validacao_ativa'] || !$coluna['validadores']) {
            return '';
        }
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null) {
            return '';
        }
        $classes = ['Ticket' => ['TicketValidation', 'tickets_id'], 'Change' => ['ChangeValidation', 'changes_id']];
        if (!isset($classes[$card['itemtype']]) || !self::itemExiste($card['itemtype'], $card['items_id'])) {
            PluginKanbanComentario::registrar($cards_id, 'validacao', 'A fileira <b>' . PluginKanbanConfig::e($coluna['name'])
                . '</b> pede validação, mas o card não está vinculado a um Chamado ou Mudança.');
            return 'A fileira "' . $coluna['name'] . '" pede validação, mas o card não está vinculado a um Chamado ou Mudança.';
        }
        [$classe, $fk] = $classes[$card['itemtype']];
        // Mesma referência de tempo que o GLPI usa para carimbar a validação (início da requisição)
        $desde = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $pedidos = 0;
        $comentario = 'Validação solicitada pelo quadro Kanban «' . $quadro['name'] . '» (card #' . $cards_id . ', fileira «' . $coluna['name'] . '»).';
        foreach ($coluna['validadores'] as $validador) {
            $ok = self::semEco(function () use ($classe, $fk, $card, $validador, $comentario) {
                $v = new $classe();
                return $v->add([
                    $fk                  => $card['items_id'],
                    'itemtype_target'    => 'User',
                    'items_id_target'    => (int) $validador,
                    'comment_submission' => $comentario,
                    '_plugin_kanban'     => true,
                ]);
            });
            if ($ok) {
                $pedidos++;
            }
        }
        $DB->update(PluginKanbanCard::getTable(), [
            'validacao_status'     => $pedidos > 0 ? CommonITILValidation::WAITING : 0,
            'validacao_colunas_id' => $pedidos > 0 ? $coluna['id'] : 0,
            'validacao_desde'      => $pedidos > 0 ? $desde : null,
        ], ['id' => $cards_id]);

        $nomes = implode(', ', array_filter(array_map([PluginKanbanConfig::class, 'nomeUsuario'], $coluna['validadores'])));
        PluginKanbanComentario::registrar($cards_id, 'validacao', $pedidos > 0
            ? 'Validação solicitada em <b>' . self::tituloItem($card['itemtype'], $card['items_id']) . '</b> para: ' . PluginKanbanConfig::e($nomes) . '.'
            : 'Não foi possível solicitar a validação em ' . self::tituloItem($card['itemtype'], $card['items_id']) . '.', (int) Session::getLoginUserID());
        return $pedidos > 0 ? '' : 'Não foi possível solicitar a validação no item vinculado.';
    }

    // =====================================================================
    // GLPI -> quadro
    // =====================================================================

    /** Status do Chamado/Problema/Mudança mudou no GLPI: o card acompanha */
    public static function aoAtualizarItem(CommonDBTM $item): void
    {
        if (self::ecoDoPlugin($item) || !in_array('status', (array) $item->updates, true)) {
            return;
        }
        $tipo   = $item::class;
        $id     = (int) $item->getID();
        $status = (int) $item->fields['status'];
        foreach (self::cardsDoItem($tipo, $id) as $card) {
            $quadro = PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']);
            if ($quadro === null || !$quadro['sincronizar_status']) {
                continue;
            }
            $atual = PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']);
            $campo = PluginKanbanConfig::campoStatus($tipo);
            // Se a fileira atual já corresponde ao novo status, o card fica onde está
            if ($atual !== null && (int) $atual[$campo] === $status) {
                continue;
            }
            $motivo = self::tituloItem($tipo, $id) . ' passou para "' . PluginKanbanConfig::nomeStatus($tipo, $status) . '" no GLPI.';
            $destino = PluginKanbanColuna::colunaParaStatus($quadro['id'], $tipo, $status);
            if ($destino !== null) {
                PluginKanbanCard::moverAutomatico($card['id'], $destino, $motivo);
            } else {
                PluginKanbanComentario::registrar($card['id'], 'sistema', $motivo . ' Nenhuma fileira corresponde a esse status.');
            }
        }
    }

    /** Acompanhamento feito no item aparece na linha do tempo do card */
    public static function aoAdicionarAcompanhamento(CommonDBTM $followup): void
    {
        global $DB;
        if (self::ecoDoPlugin($followup)) {
            return;
        }
        $tipo = (string) ($followup->fields['itemtype'] ?? '');
        $id   = (int) ($followup->fields['items_id'] ?? 0);
        if (!in_array($tipo, PluginKanbanConfig::ITEMTYPES, true) || $id <= 0) {
            return;
        }
        foreach (self::cardsDoItem($tipo, $id) as $card) {
            $quadro = PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']);
            if ($quadro === null || !$quadro['importar_followups']) {
                continue;
            }
            $ja = count($DB->request([
                'FROM'  => PluginKanbanComentario::getTable(),
                'WHERE' => ['plugin_kanban_cards_id' => $card['id'], 'itilfollowups_id' => $followup->getID()],
                'LIMIT' => 1,
            ])) > 0;
            if ($ja) {
                continue;
            }
            PluginKanbanComentario::registrar(
                $card['id'],
                'followup',
                (string) $followup->fields['content'],
                (int) $followup->fields['users_id'],
                0,
                0,
                (int) $followup->getID(),
                (int) ($followup->fields['is_private'] ?? 0)
            );
        }
    }

    /** Solução registrada no item aparece no card (a mudança de status vem por aoAtualizarItem) */
    public static function aoAdicionarSolucao(CommonDBTM $solucao): void
    {
        if (self::ecoDoPlugin($solucao)) {
            return;
        }
        $tipo = (string) ($solucao->fields['itemtype'] ?? '');
        $id   = (int) ($solucao->fields['items_id'] ?? 0);
        if (!in_array($tipo, PluginKanbanConfig::ITEMTYPES, true) || $id <= 0) {
            return;
        }
        foreach (self::cardsDoItem($tipo, $id) as $card) {
            $quadro = PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']);
            if ($quadro !== null && $quadro['importar_followups']) {
                PluginKanbanComentario::registrar($card['id'], 'solucao', (string) $solucao->fields['content'], (int) $solucao->fields['users_id']);
            }
        }
    }

    /** Resposta de validação: aprovado/recusado leva o card às fileiras configuradas */
    public static function aoAtualizarValidacao(CommonDBTM $validacao): void
    {
        global $DB;
        if (self::ecoDoPlugin($validacao) || !in_array('status', (array) $validacao->updates, true)) {
            return;
        }
        $mapa = ['TicketValidation' => ['Ticket', 'tickets_id'], 'ChangeValidation' => ['Change', 'changes_id']];
        [$tipo, $fk] = $mapa[$validacao::class] ?? [null, null];
        if ($tipo === null) {
            return;
        }
        $itemId = (int) $validacao->fields[$fk];

        foreach (self::cardsDoItem($tipo, $itemId) as $card) {
            if ($card['validacao_status'] !== CommonITILValidation::WAITING) {
                continue;
            }
            // Só contam os pedidos feitos a partir da entrada na fileira
            $aguardando = 0;
            $aprovados = 0;
            $recusados = 0;
            foreach ($DB->request([
                'SELECT' => ['status'],
                'FROM'   => $validacao::getTable(),
                'WHERE'  => [$fk => $itemId, 'submission_date' => ['>=', $card['validacao_desde'] ?? '1970-01-01']],
            ]) as $v) {
                match ((int) $v['status']) {
                    CommonITILValidation::WAITING  => $aguardando++,
                    CommonITILValidation::ACCEPTED => $aprovados++,
                    CommonITILValidation::REFUSED  => $recusados++,
                    default => null,
                };
            }
            $resultado = $recusados > 0 ? CommonITILValidation::REFUSED
                : (($aguardando === 0 && $aprovados > 0) ? CommonITILValidation::ACCEPTED : 0);

            $quem = PluginKanbanConfig::nomeUsuario((int) ($validacao->fields['users_id_validate'] ?? 0)) ?: 'validador';
            $parecer = trim(strip_tags((string) ($validacao->fields['comment_validation'] ?? '')));
            $resposta = (int) $validacao->fields['status'] === CommonITILValidation::ACCEPTED ? 'aprovou' : 'recusou';
            PluginKanbanComentario::registrar(
                $card['id'],
                'validacao',
                PluginKanbanConfig::e($quem) . ' ' . $resposta . ' a validação em <b>' . self::tituloItem($tipo, $itemId) . '</b>.'
                    . ($parecer !== '' ? '<div class="kanban-mov-comentario">' . PluginKanbanConfig::e($parecer) . '</div>' : ''),
                (int) ($validacao->fields['users_id_validate'] ?? 0)
            );

            if ($resultado === 0) {
                continue;
            }
            $DB->update(PluginKanbanCard::getTable(), ['validacao_status' => $resultado], ['id' => $card['id']]);

            $origem = PluginKanbanColuna::carregar($card['validacao_colunas_id']);
            $destinoId = $origem ? ($resultado === CommonITILValidation::ACCEPTED ? $origem['coluna_aprovado'] : $origem['coluna_recusado']) : 0;
            $destino = $destinoId > 0 ? PluginKanbanColuna::carregar($destinoId) : null;
            $rotulo = $resultado === CommonITILValidation::ACCEPTED ? 'aprovada' : 'recusada';
            if ($destino !== null && $destino['plugin_kanban_quadros_id'] === $card['plugin_kanban_quadros_id']) {
                PluginKanbanCard::moverAutomatico($card['id'], $destino, 'validação ' . $rotulo . '.');
                // A nova fileira também aplica o seu status no item
                $quadro = PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']);
                $campo = PluginKanbanConfig::campoStatus($tipo);
                if ($quadro && $quadro['sincronizar_status'] && (int) $destino[$campo] > 0) {
                    self::aplicarStatus($tipo, $itemId, (int) $destino[$campo], '', $quadro['name'], $destino['name']);
                }
                self::aoEntrarNaColuna($card['id'], $destino);
            } else {
                PluginKanbanComentario::registrar($card['id'], 'validacao', 'Validação ' . $rotulo . '.');
            }
        }
    }

    /** Item excluído definitivamente: o card perde o vínculo */
    public static function aoExcluirItem(CommonDBTM $item): void
    {
        global $DB;
        $tipo = $item::class;
        $id = (int) $item->getID();
        foreach (self::cardsDoItem($tipo, $id) as $card) {
            $DB->update(PluginKanbanCard::getTable(), ['itemtype' => '', 'items_id' => 0, 'validacao_status' => 0], ['id' => $card['id']]);
            PluginKanbanComentario::registrar($card['id'], 'sistema', self::tituloItem($tipo, $id) . ' foi excluído do GLPI; o vínculo foi removido.');
        }
    }

    // =====================================================================
    // Busca de itens para vincular
    // =====================================================================

    public static function buscarItens(string $itemtype, string $termo, int $limite = 20): array
    {
        global $DB;
        if (!in_array($itemtype, PluginKanbanConfig::ITEMTYPES, true) || !$itemtype::canView()) {
            return [];
        }
        $where = ['is_deleted' => 0];
        $termo = trim($termo);
        if ($termo !== '') {
            $numero = (int) ltrim($termo, '#');
            $or = [['name' => ['LIKE', '%' . $termo . '%']]];
            if ($numero > 0) {
                $or[] = ['id' => $numero];
            }
            $where[] = ['OR' => $or];
        }
        $where[] = (new DbUtils())->getEntitiesRestrictCriteria($itemtype::getTable(), '', '', true);
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'status'],
            'FROM'   => $itemtype::getTable(),
            'WHERE'  => $where,
            'ORDER'  => 'id DESC',
            'LIMIT'  => $limite,
        ]) as $r) {
            $lista[] = [
                'id'     => (int) $r['id'],
                'nome'   => (string) $r['name'],
                'status' => PluginKanbanConfig::nomeStatus($itemtype, (int) $r['status']),
            ];
        }
        return $lista;
    }

    // =====================================================================
    // Aba "Kanban" nos Chamados, Problemas e Mudanças
    // =====================================================================

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!in_array($item::class, PluginKanbanConfig::ITEMTYPES, true) || $item->isNewItem() || !PluginKanbanMenu::canView()) {
            return '';
        }
        $total = count(self::cardsDoItem($item::class, (int) $item->getID()));
        return self::createTabEntry('Kanban', $total, null, 'ti ti-layout-kanban');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!in_array($item::class, PluginKanbanConfig::ITEMTYPES, true)) {
            return false;
        }
        self::renderAba($item::class, (int) $item->getID());
        return true;
    }

    public static function renderAba(string $itemtype, int $items_id): void
    {
        global $CFG_GLPI;
        $raiz  = $CFG_GLPI['root_doc'] . '/plugins/kanban';
        $cards = self::cardsDoItem($itemtype, $items_id);
        $e = [PluginKanbanConfig::class, 'e'];

        echo '<link rel="stylesheet" href="' . $raiz . '/css/kanban.css?v=' . PLUGIN_KANBAN_VERSION . '">';
        echo '<div class="kanban-aba">';

        echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-layout-kanban me-2"></i>Cards vinculados</h3></div>';
        if (!$cards) {
            echo '<div class="card-body text-secondary small"><i class="ti ti-info-circle me-1"></i>Nenhum card de quadro Kanban vinculado a este item.</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm table-hover card-table mb-0"><thead><tr>';
            echo '<th>Card</th><th>Quadro</th><th>Fileira</th><th>Responsáveis</th><th>Atualizado</th><th></th></tr></thead><tbody>';
            foreach ($cards as $card) {
                $quadro = PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']);
                $coluna = PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']);
                $nomes = array_map(fn($r) => $r['nome'], PluginKanbanCard::responsaveis($card['id']));
                echo '<tr>';
                echo '<td><b>#' . $card['id'] . '</b> ' . $e($card['titulo']) . '</td>';
                echo '<td>' . $e($quadro['name'] ?? '—') . '</td>';
                echo '<td>';
                if ($coluna) {
                    echo '<span class="kanban-pilula" style="--kanban-cor:' . $e($coluna['cor']) . '">' . $e($coluna['name']) . '</span>';
                }
                echo '</td>';
                echo '<td>' . $e(implode(', ', $nomes) ?: '—') . '</td>';
                echo '<td class="text-nowrap">' . $e(Html::convDateTime($card['date_mod'])) . '</td>';
                echo '<td class="text-end">';
                if ($quadro && PluginKanbanQuadro::podeAcessar($quadro)) {
                    echo '<a class="btn btn-sm btn-ghost-secondary" href="' . $raiz . '/front/kanban.php?quadro=' . $quadro['id'] . '&amp;card=' . $card['id'] . '"><i class="ti ti-external-link me-1"></i>Abrir no quadro</a>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Adicionar o item a um quadro
        $quadros = array_filter(PluginKanbanQuadro::quadrosAcessiveis(), fn($q) => in_array($itemtype, $q['itemtypes'], true) && $q['is_active']);
        if ($quadros) {
            $opcoes = [];
            foreach ($quadros as $q) {
                $opcoes[$q['id']] = ['nome' => $q['name'], 'colunas' => array_values(array_map(fn($c) => ['id' => $c['id'], 'nome' => $c['name']], PluginKanbanColuna::listar($q['id'])))];
            }
            $uid = 'kanban-aba-' . mt_rand();
            echo '<div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-plus me-2"></i>Adicionar a um quadro</h3></div>';
            echo '<div class="card-body"><div class="kanban-aba-form" id="' . $uid . '">';
            echo '<label>Quadro<select class="form-select form-select-sm" data-kanban-quadro></select></label>';
            echo '<label>Fileira<select class="form-select form-select-sm" data-kanban-coluna></select></label>';
            echo '<button type="button" class="btn btn-sm btn-primary" data-kanban-adicionar><i class="ti ti-layout-kanban me-1"></i>Criar card vinculado</button>';
            echo '<span class="kanban-aba-status small text-secondary" data-kanban-status></span>';
            echo '</div></div></div>';

            $config = [
                'ajax'     => $raiz . '/front/ajax.php',
                'token'    => PluginKanbanConfig::tokenCsrf(),
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'quadros'  => $opcoes,
            ];
            echo '<script>(function(){'
                . 'var cfg=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
                . 'var raiz=document.getElementById(' . json_encode($uid) . ');if(!raiz){return;}'
                . 'var selQ=raiz.querySelector("[data-kanban-quadro]"),selC=raiz.querySelector("[data-kanban-coluna]"),st=raiz.querySelector("[data-kanban-status]");'
                . 'Object.keys(cfg.quadros).forEach(function(id){var o=document.createElement("option");o.value=id;o.textContent=cfg.quadros[id].nome;selQ.appendChild(o);});'
                . 'function cols(){selC.innerHTML="";(cfg.quadros[selQ.value]||{colunas:[]}).colunas.forEach(function(c){var o=document.createElement("option");o.value=c.id;o.textContent=c.nome;selC.appendChild(o);});}'
                . 'selQ.addEventListener("change",cols);cols();'
                . 'raiz.querySelector("[data-kanban-adicionar]").addEventListener("click",function(){'
                . 'var b=this;b.disabled=true;st.textContent="Criando...";'
                . 'var fd=new FormData();fd.append("action","adicionar_item");fd.append("itemtype",cfg.itemtype);fd.append("items_id",cfg.items_id);fd.append("quadro",selQ.value);fd.append("coluna",selC.value);fd.append("_glpi_csrf_token",cfg.token);'
                . 'fetch(cfg.ajax,{method:"POST",body:fd,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest","X-Glpi-Csrf-Token":cfg.token}})'
                . '.then(function(r){return r.text();}).then(function(t){var d=null;try{d=JSON.parse(t);}catch(e){var m=t.match(/\{[\s\S]*\}\s*$/);if(m){try{d=JSON.parse(m[0]);}catch(e2){}}}'
                . 'if(d&&d.new_token){cfg.token=d.new_token;}'
                . 'if(d&&d.success){st.textContent=d.mensagem||"Card criado.";window.location.reload();}else{b.disabled=false;st.textContent=(d&&d.mensagem)||"Não foi possível criar o card.";}})'
                . '.catch(function(){b.disabled=false;st.textContent="Falha de comunicação.";});});'
                . '})();</script>';
        }
        echo '</div>';
    }

    /** Cria um card a partir do item (aba "Kanban") */
    public static function adicionarItemAoQuadro(string $itemtype, int $items_id, int $quadros_id, int $colunas_id): array
    {
        $quadro = PluginKanbanQuadro::carregar($quadros_id);
        if (!PluginKanbanQuadro::podeAcessar($quadro) || !in_array($itemtype, $quadro['itemtypes'], true)) {
            return [0, 'Sem acesso a este quadro ou tipo não aceito.'];
        }
        $item = new $itemtype();
        if (!$item->can($items_id, READ)) {
            return [0, 'Item não encontrado ou sem permissão.'];
        }
        [$cardId, $erro] = PluginKanbanCard::criar([
            'quadro'       => $quadros_id,
            'coluna'       => $colunas_id,
            'titulo'       => (string) $item->fields['name'],
            'descricao'    => (string) $item->fields['content'],
            'entities_id'  => (int) $item->fields['entities_id'],
            'vinculo_modo' => 'depois',
        ]);
        if ($cardId <= 0) {
            return [0, $erro];
        }
        [, $mensagem] = self::vincular($cardId, $itemtype, $items_id);
        $card = PluginKanbanCard::carregar($cardId);
        $coluna = $card ? PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']) : null;
        if ($coluna !== null) {
            self::aoEntrarNaColuna($cardId, $coluna);
        }
        return [$cardId, 'Card #' . $cardId . ' criado. ' . $mensagem];
    }
}
