<?php

/**
 * Plugin Kanban - cards
 */
class PluginKanbanCard extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Cards' : 'Card';
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-kanban';
    }

    public static function canView(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function canCreate(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function canUpdate(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function canDelete(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function canPurge(): bool
    {
        return PluginKanbanConfig::ehAdmin();
    }

    /** Documentos anexados ao card: acesso pelo quadro */
    public function canViewItem(): bool
    {
        return self::podeAcessar((int) ($this->fields['id'] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return $this->canViewItem();
    }

    // =====================================================================
    // Leitura
    // =====================================================================

    public static function carregar(int $id): ?array
    {
        global $DB;
        if ($id <= 0) {
            return null;
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id, 'is_deleted' => 0], 'LIMIT' => 1]) as $c) {
            foreach (['id', 'plugin_kanban_quadros_id', 'plugin_kanban_colunas_id', 'entities_id', 'users_id',
                      'prioridade', 'ordem', 'items_id', 'is_fechado', 'validacao_status', 'validacao_colunas_id'] as $campo) {
                $c[$campo] = (int) $c[$campo];
            }
            return $c;
        }
        return null;
    }

    /** O usuário logado pode abrir o quadro deste card? */
    public static function podeAcessar(int $cards_id): bool
    {
        $card = self::carregar($cards_id);
        return $card !== null && PluginKanbanQuadro::podeAcessar(PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']));
    }

    public static function responsaveisIds(int $cards_id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_plugin_kanban_cards_users',
            'WHERE'  => ['plugin_kanban_cards_id' => $cards_id],
            'ORDER'  => 'id ASC',
        ]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        return $ids;
    }

    public static function responsaveis(int $cards_id): array
    {
        $lista = [];
        foreach (self::responsaveisIds($cards_id) as $uid) {
            $nome = PluginKanbanConfig::nomeUsuario($uid);
            if ($nome !== '') {
                $lista[] = ['id' => $uid, 'nome' => $nome, 'iniciais' => PluginKanbanConfig::iniciais($nome)];
            }
        }
        return $lista;
    }

    /** Define os responsáveis; devolve os ids que entraram agora */
    public static function setResponsaveis(int $cards_id, array $users_ids): array
    {
        global $DB;
        $novos = [];
        foreach ($users_ids as $uid) {
            $uid = (int) $uid;
            if ($uid > 0 && !in_array($uid, $novos, true)) {
                $novos[] = $uid;
            }
        }
        if ($novos) {
            $validos = [];
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $novos, 'is_active' => 1, 'is_deleted' => 0]]) as $r) {
                $validos[] = (int) $r['id'];
            }
            $novos = array_values(array_intersect($novos, $validos));
        }
        $atuais = self::responsaveisIds($cards_id);
        $remover = array_diff($atuais, $novos);
        if ($remover) {
            $DB->delete('glpi_plugin_kanban_cards_users', ['plugin_kanban_cards_id' => $cards_id, 'users_id' => array_values($remover)]);
        }
        $adicionados = array_values(array_diff($novos, $atuais));
        foreach ($adicionados as $uid) {
            $DB->insert('glpi_plugin_kanban_cards_users', ['plugin_kanban_cards_id' => $cards_id, 'users_id' => $uid]);
        }
        return $adicionados;
    }

    /**
     * Cards do quadro no formato usado pelo navegador (tudo em lote: poucas consultas
     * mesmo com centenas de cards).
     */
    public static function listarDoQuadro(int $quadros_id): array
    {
        global $DB;
        $uid = (int) Session::getLoginUserID();

        $cards = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_kanban_quadros_id' => $quadros_id, 'is_deleted' => 0],
            'ORDER' => ['ordem ASC', 'id DESC'],
        ]) as $c) {
            $id = (int) $c['id'];
            $cards[$id] = [
                'id'          => $id,
                'coluna'      => (int) $c['plugin_kanban_colunas_id'],
                'titulo'      => (string) $c['titulo'],
                'prioridade'  => (int) $c['prioridade'],
                'prazo'       => $c['prazo'] ? (string) $c['prazo'] : '',
                'ordem'       => (int) $c['ordem'],
                'itemtype'    => (string) $c['itemtype'],
                'items_id'    => (int) $c['items_id'],
                'fechado'     => (int) $c['is_fechado'] === 1,
                'validacao'   => (int) $c['validacao_status'],
                'autor'       => PluginKanbanConfig::nomeUsuario((int) $c['users_id']),
                'autor_id'    => (int) $c['users_id'],
                'atualizado'  => (string) $c['date_mod'],
                'responsaveis' => [],
                'comentarios' => 0,
                'anexos'      => 0,
                'nao_lido'    => false,
                'item'        => null,
                'busca'       => mb_strtolower($c['titulo'] . ' #' . $id . ' ' . strip_tags(html_entity_decode((string) $c['descricao'], ENT_QUOTES, 'UTF-8'))),
            ];
        }
        if (!$cards) {
            return [];
        }
        $ids = array_keys($cards);

        // Responsáveis
        foreach ($DB->request([
            'SELECT' => ['plugin_kanban_cards_id', 'users_id'],
            'FROM'   => 'glpi_plugin_kanban_cards_users',
            'WHERE'  => ['plugin_kanban_cards_id' => $ids],
            'ORDER'  => 'id ASC',
        ]) as $r) {
            $nome = PluginKanbanConfig::nomeUsuario((int) $r['users_id']);
            if ($nome === '') {
                continue;
            }
            $cid = (int) $r['plugin_kanban_cards_id'];
            $cards[$cid]['responsaveis'][] = ['id' => (int) $r['users_id'], 'nome' => $nome, 'iniciais' => PluginKanbanConfig::iniciais($nome)];
            $cards[$cid]['busca'] .= ' ' . mb_strtolower($nome);
        }

        // Comentários (conta só os de pessoas, não os registros automáticos) e o mais recente
        $ultimoComentario = [];
        foreach ($DB->request([
            'SELECT' => [
                'plugin_kanban_cards_id',
                new \Glpi\DBAL\QueryExpression('COUNT(*) AS total'),
                new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('date_creation') . ') AS ultimo'),
            ],
            'FROM'    => PluginKanbanComentario::getTable(),
            'WHERE'   => ['plugin_kanban_cards_id' => $ids, 'tipo' => ['comentario', 'followup', 'solucao']],
            'GROUPBY' => 'plugin_kanban_cards_id',
        ]) as $r) {
            $cid = (int) $r['plugin_kanban_cards_id'];
            $cards[$cid]['comentarios'] = (int) $r['total'];
            $ultimoComentario[$cid] = (string) $r['ultimo'];
        }

        // Não lido: há comentário depois da última vez que o usuário abriu o card
        $vistos = [];
        foreach ($DB->request([
            'SELECT' => ['plugin_kanban_cards_id', 'date_mod'],
            'FROM'   => PluginKanbanVisualizacao::getTable(),
            'WHERE'  => ['plugin_kanban_cards_id' => $ids, 'users_id' => $uid],
        ]) as $r) {
            $vistos[(int) $r['plugin_kanban_cards_id']] = (string) $r['date_mod'];
        }
        foreach ($ultimoComentario as $cid => $data) {
            $cards[$cid]['nao_lido'] = !isset($vistos[$cid]) || $vistos[$cid] < $data;
        }

        // Anexos
        foreach ($DB->request([
            'SELECT' => ['di.items_id', new \Glpi\DBAL\QueryExpression('COUNT(*) AS total')],
            'FROM'   => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['FKEY' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE'  => ['di.itemtype' => self::class, 'di.items_id' => $ids, 'd.is_deleted' => 0],
            'GROUPBY' => 'di.items_id',
        ]) as $r) {
            $cards[(int) $r['items_id']]['anexos'] = (int) $r['total'];
        }

        // Itens ITIL vinculados: status atual, em lote por tipo
        $porTipo = [];
        foreach ($cards as $c) {
            if ($c['items_id'] > 0 && in_array($c['itemtype'], PluginKanbanConfig::ITEMTYPES, true)) {
                $porTipo[$c['itemtype']][] = $c['items_id'];
            }
        }
        $itens = [];
        foreach ($porTipo as $tipo => $itemIds) {
            foreach ($DB->request([
                'SELECT' => ['id', 'status', 'name', 'is_deleted'],
                'FROM'   => $tipo::getTable(),
                'WHERE'  => ['id' => array_values(array_unique($itemIds))],
            ]) as $r) {
                $itens[$tipo][(int) $r['id']] = $r;
            }
        }
        foreach ($cards as $cid => $c) {
            if ($c['items_id'] <= 0 || !isset($itens[$c['itemtype']][$c['items_id']])) {
                continue;
            }
            $r = $itens[$c['itemtype']][$c['items_id']];
            $cards[$cid]['item'] = [
                'tipo'        => $c['itemtype'],
                'tipo_nome'   => PluginKanbanConfig::nomeItemtype($c['itemtype']),
                'icone'       => PluginKanbanConfig::iconeItemtype($c['itemtype']),
                'id'          => $c['items_id'],
                'status'      => (int) $r['status'],
                'status_nome' => PluginKanbanConfig::nomeStatus($c['itemtype'], (int) $r['status']),
                'url'         => PluginKanbanConfig::urlItem($c['itemtype'], $c['items_id']),
                'lixeira'     => (int) $r['is_deleted'] === 1,
            ];
            $cards[$cid]['busca'] .= ' #' . $c['items_id'] . ' ' . mb_strtolower((string) $r['name']);
        }

        return array_values($cards);
    }

    // =====================================================================
    // Escrita
    // =====================================================================

    /** Cria o card. Devolve [id, mensagem de erro] */
    public static function criar(array $dados): array
    {
        global $DB;

        $quadro = PluginKanbanQuadro::carregar((int) ($dados['quadro'] ?? 0));
        if (!PluginKanbanQuadro::podeAcessar($quadro)) {
            return [0, 'Sem acesso a este quadro.'];
        }
        $colunas = PluginKanbanColuna::listar($quadro['id']);
        if (!$colunas) {
            return [0, 'O quadro não tem fileiras. Configure as fileiras antes de criar cards.'];
        }
        $coluna = $colunas[(int) ($dados['coluna'] ?? 0)] ?? reset($colunas);

        $titulo = trim((string) ($dados['titulo'] ?? ''));
        if ($titulo === '') {
            return [0, 'Informe o título do card.'];
        }
        if ($coluna['limite_wip'] > 0 && PluginKanbanColuna::contarCards($coluna['id']) >= $coluna['limite_wip']) {
            return [0, sprintf('A fileira "%s" já está no limite de %d card(s).', $coluna['name'], $coluna['limite_wip'])];
        }

        $uid = (int) Session::getLoginUserID();
        $menorOrdem = (int) ($DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('MIN(' . $DB->quoteName('ordem') . ') AS menor')],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_kanban_colunas_id' => $coluna['id'], 'is_deleted' => 0],
        ])->current()['menor'] ?? 10);

        $entidade = isset($dados['entities_id']) && $dados['entities_id'] !== ''
            ? max(0, (int) $dados['entities_id'])
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        $DB->insert(self::getTable(), [
            'plugin_kanban_quadros_id' => $quadro['id'],
            'plugin_kanban_colunas_id' => $coluna['id'],
            'entities_id' => $entidade,
            'users_id'    => $uid,
            'titulo'      => mb_substr($titulo, 0, 255),
            'descricao'   => (string) ($dados['descricao'] ?? ''),
            'prioridade'  => self::prioridadeValida($dados['prioridade'] ?? 3),
            'prazo'       => self::dataValida($dados['prazo'] ?? ''),
            'ordem'       => $menorOrdem - 10,
            'is_fechado'  => $coluna['is_final'],
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $DB->insertId();
        if ($id <= 0) {
            return [0, 'Não foi possível criar o card.'];
        }

        $adicionados = self::setResponsaveis($id, PluginKanbanConfig::jsonParaIds($dados['responsaveis'] ?? []));
        PluginKanbanComentario::registrar($id, 'sistema', 'Card criado na fileira <b>' . PluginKanbanConfig::e($coluna['name']) . '</b>.', $uid);

        // Vínculo: escolhido no formulário ou automático do quadro
        $modo = (string) ($dados['vinculo_modo'] ?? 'auto');
        $tipo = (string) ($dados['vinculo_tipo'] ?? '');
        $mensagem = '';
        if ($modo === 'existente' && in_array($tipo, $quadro['itemtypes'], true) && (int) ($dados['vinculo_id'] ?? 0) > 0) {
            [, $mensagem] = PluginKanbanVinculo::vincular($id, $tipo, (int) $dados['vinculo_id']);
        } elseif ($modo === 'novo' && in_array($tipo, $quadro['itemtypes'], true)) {
            [, $mensagem] = PluginKanbanVinculo::gerarItem($id, $tipo);
        } elseif ($modo === 'auto' && $quadro['vinculo_auto'] !== '') {
            [, $mensagem] = PluginKanbanVinculo::gerarItem($id, $quadro['vinculo_auto']);
        }

        // Validação na fileira de entrada ("depois": quem chamou vincula e dispara em seguida)
        if ($modo !== 'depois') {
            PluginKanbanVinculo::aoEntrarNaColuna($id, $coluna);
        }

        PluginKanbanNotificacao::enviar($id, 'criacao');
        if ($adicionados) {
            PluginKanbanNotificacao::enviar($id, 'responsavel', ['usuarios' => $adicionados]);
        }
        return [$id, $mensagem];
    }

    /** Atualiza os campos editáveis. Devolve mensagem de erro ou '' */
    public static function atualizar(int $id, array $dados): string
    {
        global $DB;
        $card = self::carregar($id);
        if ($card === null) {
            return 'Card não encontrado.';
        }
        $titulo = trim((string) ($dados['titulo'] ?? $card['titulo']));
        if ($titulo === '') {
            return 'Informe o título do card.';
        }

        $novo = [
            'titulo'     => mb_substr($titulo, 0, 255),
            'descricao'  => array_key_exists('descricao', $dados) ? (string) $dados['descricao'] : (string) $card['descricao'],
            'prioridade' => self::prioridadeValida($dados['prioridade'] ?? $card['prioridade']),
            'prazo'      => array_key_exists('prazo', $dados) ? self::dataValida($dados['prazo']) : $card['prazo'],
        ];
        if (isset($dados['entities_id']) && $dados['entities_id'] !== '') {
            $novo['entities_id'] = max(0, (int) $dados['entities_id']);
        }

        $rotulos = ['titulo' => 'título', 'descricao' => 'descrição', 'prioridade' => 'prioridade', 'prazo' => 'prazo', 'entities_id' => 'entidade'];
        $mudou = [];
        foreach ($novo as $campo => $valor) {
            if ((string) $valor !== (string) ($card[$campo] ?? '')) {
                $mudou[] = $rotulos[$campo];
            }
        }
        $DB->update(self::getTable(), $novo, ['id' => $id]);

        $adicionados = [];
        if (array_key_exists('responsaveis', $dados)) {
            $antes = self::responsaveisIds($id);
            $adicionados = self::setResponsaveis($id, PluginKanbanConfig::jsonParaIds($dados['responsaveis']));
            if ($antes !== self::responsaveisIds($id)) {
                $mudou[] = 'responsáveis';
            }
        }

        if ($mudou) {
            PluginKanbanComentario::registrar($id, 'sistema', 'Card editado: ' . implode(', ', $mudou) . '.', (int) Session::getLoginUserID());
        }
        if ($adicionados) {
            PluginKanbanVinculo::atribuirResponsaveis($id, $adicionados);
            PluginKanbanNotificacao::enviar($id, 'responsavel', ['usuarios' => $adicionados]);
        }
        return '';
    }

    /**
     * Move o card para a fileira e posição informadas.
     * Devolve ['ok' => bool, 'mensagem' => string, 'pedir_comentario' => bool].
     */
    public static function mover(int $id, int $colunas_id, int $posicao, string $comentario = ''): array
    {
        global $DB;
        $card = self::carregar($id);
        if ($card === null) {
            return ['ok' => false, 'mensagem' => 'Card não encontrado.'];
        }
        $colunas = PluginKanbanColuna::listar($card['plugin_kanban_quadros_id']);
        if (!isset($colunas[$colunas_id])) {
            return ['ok' => false, 'mensagem' => 'Fileira inválida.'];
        }
        $destino = $colunas[$colunas_id];
        $origemId = $card['plugin_kanban_colunas_id'];
        $trocou = $origemId !== $colunas_id;

        if ($trocou) {
            if ($destino['limite_wip'] > 0 && PluginKanbanColuna::contarCards($colunas_id) >= $destino['limite_wip']) {
                return ['ok' => false, 'mensagem' => sprintf('A fileira "%s" já está no limite de %d card(s).', $destino['name'], $destino['limite_wip'])];
            }
            if ($destino['pedir_comentario'] && PluginKanbanConfig::richtextVazio($comentario)) {
                return ['ok' => false, 'pedir_comentario' => true, 'mensagem' => 'Esta fileira pede um comentário ao receber o card.'];
            }
        }

        // Reordena a fileira de destino colocando o card na posição pedida
        $ordemIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_kanban_colunas_id' => $colunas_id, 'is_deleted' => 0, 'NOT' => ['id' => $id]],
            'ORDER'  => ['ordem ASC', 'id DESC'],
        ]) as $r) {
            $ordemIds[] = (int) $r['id'];
        }
        $posicao = max(0, min(count($ordemIds), $posicao));
        array_splice($ordemIds, $posicao, 0, [$id]);
        foreach ($ordemIds as $i => $cid) {
            $campos = ['ordem' => ($i + 1) * 10];
            if ($cid === $id) {
                $campos['plugin_kanban_colunas_id'] = $colunas_id;
                $campos['is_fechado'] = $destino['is_final'];
            }
            $DB->update(self::getTable(), $campos, ['id' => $cid]);
        }

        if (!$trocou) {
            return ['ok' => true, 'mensagem' => ''];
        }

        $origem = $colunas[$origemId] ?? null;
        $texto = 'Movido de <b>' . PluginKanbanConfig::e($origem['name'] ?? '—') . '</b> para <b>' . PluginKanbanConfig::e($destino['name']) . '</b>.';
        if (!PluginKanbanConfig::richtextVazio($comentario)) {
            $texto .= '<div class="kanban-mov-comentario">' . $comentario . '</div>';
        }
        PluginKanbanComentario::registrar($id, 'movimentacao', $texto, (int) Session::getLoginUserID(), $origemId, $colunas_id);

        $avisos = PluginKanbanVinculo::aoMoverCard($id, $origem, $destino, $comentario);
        PluginKanbanNotificacao::enviar($id, 'movimentacao', ['de' => $origem['name'] ?? '', 'para' => $destino['name'], 'comentario' => $comentario]);

        return ['ok' => true, 'mensagem' => $avisos];
    }

    /** Move sem regras de interface (usado pela sincronização com o GLPI) */
    public static function moverAutomatico(int $id, array $destino, string $motivo): void
    {
        global $DB;
        $card = self::carregar($id);
        if ($card === null || $card['plugin_kanban_colunas_id'] === $destino['id']) {
            return;
        }
        $origem = PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']);
        $menor = (int) ($DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('MIN(' . $DB->quoteName('ordem') . ') AS menor')],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_kanban_colunas_id' => $destino['id'], 'is_deleted' => 0],
        ])->current()['menor'] ?? 10);
        $DB->update(self::getTable(), [
            'plugin_kanban_colunas_id' => $destino['id'],
            'is_fechado' => $destino['is_final'],
            'ordem'      => $menor - 10,
        ], ['id' => $id]);
        PluginKanbanComentario::registrar(
            $id,
            'movimentacao',
            'Movido automaticamente de <b>' . PluginKanbanConfig::e($origem['name'] ?? '—') . '</b> para <b>'
                . PluginKanbanConfig::e($destino['name']) . '</b>: ' . $motivo,
            0,
            (int) ($origem['id'] ?? 0),
            $destino['id']
        );
    }

    public static function excluir(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['is_deleted' => 1], ['id' => $id]);
        PluginKanbanComentario::registrar($id, 'sistema', 'Card excluído do quadro.', (int) Session::getLoginUserID());
    }

    public static function prioridadeValida($valor): int
    {
        $p = (int) $valor;
        return isset(PluginKanbanConfig::prioridades()[$p]) ? $p : 3;
    }

    public static function dataValida($valor): ?string
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;
    }

    public static function toque(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    // =====================================================================
    // Formulário do card (modal): criação e edição
    // =====================================================================

    public static function renderFormulario(array $quadro, ?array $card, int $colunaPadrao = 0): string
    {
        $e = [PluginKanbanConfig::class, 'e'];
        $novo = $card === null;
        $colunas = PluginKanbanColuna::listar($quadro['id']);
        $colunaAtual = $novo ? ($colunas[$colunaPadrao]['id'] ?? (int) array_key_first($colunas)) : $card['plugin_kanban_colunas_id'];
        $sufixo = $novo ? 'novo' . mt_rand() : (string) $card['id'];
        $entidade = $novo ? (int) ($_SESSION['glpiactive_entity'] ?? 0) : $card['entities_id'];
        $responsaveis = $novo ? [] : self::responsaveis($card['id']);

        $h  = '<form class="kanban-form" data-card="' . ($novo ? 0 : $card['id']) . '" data-quadro="' . $quadro['id'] . '" data-coluna-original="' . $colunaAtual . '" autocomplete="off">';
        $h .= '<div class="kanban-form-grade">';

        // ---------------- Principal ----------------
        $h .= '<div class="kanban-form-principal">';
        $h .= '<input type="text" name="titulo" class="form-control kanban-form-titulo" maxlength="255" placeholder="Título do card" value="' . $e($card['titulo'] ?? '') . '" required>';
        if (!$novo) {
            $h .= '<div class="kanban-form-meta">Card #' . $card['id'] . ' · criado por ' . $e(PluginKanbanConfig::nomeUsuario($card['users_id'])) . ' em ' . $e(Html::convDateTime($card['date_creation'])) . '</div>';
        }
        $h .= '<div class="kanban-form-bloco"><div class="kanban-form-rotulo"><i class="ti ti-align-left"></i> Descrição</div>';
        $h .= '<textarea name="descricao" id="kanban-descricao-' . $sufixo . '" class="kanban-editor" rows="6">' . $e($card['descricao'] ?? '') . '</textarea></div>';

        if (!$novo) {
            $h .= '<div class="kanban-form-bloco"><div class="kanban-form-rotulo"><i class="ti ti-messages"></i> Comentários e histórico</div>';
            $h .= '<div class="kanban-comentar">';
            $h .= '<textarea id="kanban-comentario-' . $sufixo . '" class="kanban-editor" data-kanban-comentario rows="3"></textarea>';
            $aviso = ($card['items_id'] > 0 && $quadro['espelhar_comentarios']) ? '<span class="kanban-dica"><i class="ti ti-link"></i> também vira acompanhamento em ' . $e(PluginKanbanConfig::nomeItemtype($card['itemtype']) . ' #' . $card['items_id']) . '</span>' : '<span></span>';
            $h .= '<div class="kanban-comentar-acoes">' . $aviso . '<button type="button" class="btn btn-sm btn-primary" data-kanban-comentar><i class="ti ti-send me-1"></i>Comentar</button></div>';
            $h .= '</div>';
            $h .= '<div class="kanban-linha-do-tempo" data-kanban-linha-do-tempo>' . PluginKanbanComentario::renderLinhaDoTempo($card['id']) . '</div>';
            $h .= '</div>';
        }
        $h .= '</div>';

        // ---------------- Lateral ----------------
        $h .= '<aside class="kanban-form-lateral">';

        $h .= '<label class="kanban-campo"><span>Fileira</span><select name="coluna" class="form-select form-select-sm">';
        foreach ($colunas as $c) {
            $h .= '<option value="' . $c['id'] . '"' . ($c['id'] === $colunaAtual ? ' selected' : '') . '>' . $e($c['name']) . '</option>';
        }
        $h .= '</select></label>';

        $h .= '<div class="kanban-campo-duplo">';
        $h .= '<label class="kanban-campo"><span>Prioridade</span><select name="prioridade" class="form-select form-select-sm">';
        foreach (PluginKanbanConfig::prioridades() as $valor => $rotulo) {
            $h .= '<option value="' . $valor . '"' . ($valor === (int) ($card['prioridade'] ?? 3) ? ' selected' : '') . '>' . $rotulo . '</option>';
        }
        $h .= '</select></label>';
        $h .= '<label class="kanban-campo"><span>Prazo</span><input type="date" name="prazo" class="form-control form-control-sm" value="' . $e($card['prazo'] ?? '') . '"></label>';
        $h .= '</div>';

        $h .= '<div class="kanban-campo"><span>Entidade</span>';
        $h .= '<div class="kanban-busca" data-kanban-busca="entidades" data-nome="entities_id">';
        $h .= '<input type="hidden" name="entities_id" value="' . $entidade . '">';
        $h .= '<button type="button" class="kanban-busca-atual form-select form-select-sm">' . $e(PluginKanbanConfig::nomeEntidade($entidade)) . '</button>';
        $h .= '<div class="kanban-busca-painel" hidden><input type="search" class="form-control form-control-sm" placeholder="Buscar entidade..."><div class="kanban-busca-lista"></div></div>';
        $h .= '</div></div>';

        $h .= '<div class="kanban-campo"><span>Responsáveis</span>';
        $h .= '<div class="kanban-pessoas" data-kanban-pessoas data-inicial="' . $e(json_encode($responsaveis)) . '">';
        $h .= '<input type="hidden" name="responsaveis" value="' . $e(json_encode(array_column($responsaveis, 'id'))) . '">';
        $h .= '<div class="kanban-pessoas-lista"></div>';
        $h .= '<div class="kanban-busca-campo"><input type="search" class="form-control form-control-sm" placeholder="Adicionar pessoa..."><div class="kanban-busca-lista" hidden></div></div>';
        $h .= '</div></div>';

        $h .= self::renderBlocoVinculo($quadro, $card);

        if (!$novo) {
            $h .= '<div class="kanban-campo"><span>Anexos</span><div class="kanban-anexos" data-kanban-anexos>' . self::renderAnexos($card['id']) . '</div>';
            $h .= '<label class="kanban-anexar"><i class="ti ti-paperclip"></i> Anexar arquivos<input type="file" multiple hidden data-kanban-anexar></label></div>';

            $vistos = PluginKanbanVisualizacao::listar($card['id']);
            if ($vistos) {
                $h .= '<div class="kanban-campo"><span>Visualizado por</span><div class="kanban-vistos">';
                foreach ($vistos as $v) {
                    $h .= '<span class="kanban-avatar kanban-avatar-p" title="' . $e($v['nome'] . ' · ' . $v['total'] . 'x · última: ' . $v['ultima']) . '">' . $e($v['iniciais']) . '</span>';
                }
                $h .= '</div></div>';
            }
        }

        $h .= '</aside></div></form>';
        return $h;
    }

    public static function renderBlocoVinculo(array $quadro, ?array $card): string
    {
        $e = [PluginKanbanConfig::class, 'e'];
        if (!$quadro['itemtypes']) {
            return '';
        }
        $h = '<div class="kanban-campo"><span>Vínculo com o GLPI</span><div class="kanban-vinculo" data-kanban-vinculo>';

        if ($card !== null && $card['items_id'] > 0 && PluginKanbanVinculo::itemExiste($card['itemtype'], $card['items_id'])) {
            $item = new $card['itemtype']();
            $item->getFromDB($card['items_id']);
            $h .= '<div class="kanban-vinculo-atual">';
            $h .= '<i class="' . PluginKanbanConfig::iconeItemtype($card['itemtype']) . '"></i>';
            $h .= '<div><a href="' . $e(PluginKanbanConfig::urlItem($card['itemtype'], $card['items_id'])) . '" target="_blank">'
                . $e(PluginKanbanConfig::nomeItemtype($card['itemtype']) . ' #' . $card['items_id']) . '</a>';
            $h .= '<small>' . $e((string) $item->fields['name']) . '</small>';
            $h .= '<span class="kanban-pilula">' . $e(PluginKanbanConfig::nomeStatus($card['itemtype'], (int) $item->fields['status'])) . '</span>';
            if ($card['validacao_status'] === CommonITILValidation::WAITING) {
                $h .= ' <span class="kanban-pilula kanban-pilula-aviso"><i class="ti ti-hourglass"></i> aguardando validação</span>';
            }
            $h .= '</div>';
            $h .= '<button type="button" class="btn btn-sm btn-ghost-secondary" data-kanban-desvincular title="Remover vínculo"><i class="ti ti-unlink"></i></button>';
            $h .= '</div>';
            return $h . '</div></div>';
        }

        $novo = $card === null;
        $modo = $novo && $quadro['vinculo_auto'] !== '' ? 'novo' : 'nenhum';
        $tipoPadrao = $quadro['vinculo_auto'] ?: $quadro['itemtypes'][0];

        $h .= '<input type="hidden" name="vinculo_id" value="0">';
        $h .= '<div class="kanban-segmentos">';
        $modos = ['nenhum' => 'Sem vínculo', 'novo' => 'Criar novo', 'existente' => 'Existente'];
        foreach ($modos as $valor => $rotulo) {
            $h .= '<label><input type="radio" name="vinculo_modo" value="' . $valor . '"' . ($valor === $modo ? ' checked' : '') . '><span>' . $rotulo . '</span></label>';
        }
        $h .= '</div>';
        $h .= '<select name="vinculo_tipo" class="form-select form-select-sm">';
        foreach ($quadro['itemtypes'] as $tipo) {
            $h .= '<option value="' . $tipo . '"' . ($tipo === $tipoPadrao ? ' selected' : '') . '>' . PluginKanbanConfig::nomeItemtype($tipo) . '</option>';
        }
        $h .= '</select>';
        $h .= '<div class="kanban-busca-campo" data-kanban-busca-item hidden><input type="search" class="form-control form-control-sm" placeholder="Número ou título..."><div class="kanban-busca-lista" hidden></div></div>';
        $h .= '<div class="kanban-vinculo-escolhido" data-kanban-item-escolhido hidden></div>';
        if (!$novo) {
            $h .= '<button type="button" class="btn btn-sm btn-outline-primary w-100" data-kanban-aplicar-vinculo hidden><i class="ti ti-link me-1"></i>Aplicar vínculo</button>';
        }
        return $h . '</div></div>';
    }

    public static function renderAnexos(int $cards_id): string
    {
        global $CFG_GLPI;
        $e = [PluginKanbanConfig::class, 'e'];
        $anexos = PluginKanbanAnexo::listar($cards_id);
        if (!$anexos) {
            return '<div class="kanban-vazio kanban-vazio-p">Nenhum anexo.</div>';
        }
        $h = '';
        foreach ($anexos as $a) {
            $url = $CFG_GLPI['root_doc'] . '/plugins/kanban/front/ajax.php?action=anexo&card=' . $cards_id . '&doc=' . $a['id'];
            $tamanho = $a['tamanho'] > 1048576 ? number_format($a['tamanho'] / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($a['tamanho'] / 1024)) . ' KB';
            $h .= '<div class="kanban-anexo">';
            $h .= '<i class="ti ' . ($a['imagem'] ? 'ti-photo' : 'ti-file') . '"></i>';
            $h .= '<a href="' . $e($url) . '" target="_blank" title="' . $e($a['autor'] . ' · ' . $a['data']) . '">' . $e($a['nome']) . '</a>';
            $h .= '<small>' . $tamanho . '</small>';
            $h .= '<button type="button" class="kanban-anexo-remover" data-kanban-remover-anexo="' . $a['id'] . '" title="Remover anexo"><i class="ti ti-x"></i></button>';
            $h .= '</div>';
        }
        return $h;
    }
}
