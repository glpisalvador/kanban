<?php

/**
 * Plugin Kanban - quadros (cada um com suas fileiras, acesso e integração ITIL)
 */
class PluginKanbanQuadro extends CommonDBTM
{
    /** Eventos que podem gerar e-mail (configurados por quadro) */
    public const EVENTOS = [
        'criacao'      => 'Card criado',
        'movimentacao' => 'Card movido de fileira',
        'comentario'   => 'Novo comentário',
        'responsavel'  => 'Adicionado como responsável',
    ];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Quadros Kanban' : 'Quadro Kanban';
    }

    public static function canView(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function canCreate(): bool
    {
        return PluginKanbanConfig::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return PluginKanbanConfig::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return PluginKanbanConfig::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return PluginKanbanConfig::ehAdmin();
    }

    /** Quadro com as listas JSON já decodificadas (null se não existir) */
    public static function carregar(int $id): ?array
    {
        global $DB;
        if ($id <= 0) {
            return null;
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id, 'is_deleted' => 0], 'LIMIT' => 1]) as $q) {
            return self::normalizar($q);
        }
        return null;
    }

    public static function normalizar(array $q): array
    {
        $q['id']          = (int) $q['id'];
        $q['perfis']      = PluginKanbanConfig::jsonParaIds($q['perfis'] ?? '[]');
        $q['usuarios']    = PluginKanbanConfig::jsonParaIds($q['usuarios'] ?? '[]');
        $q['grupos']      = PluginKanbanConfig::jsonParaIds($q['grupos'] ?? '[]');
        $tipos            = PluginKanbanConfig::jsonParaLista($q['itemtypes'] ?? '[]');
        $q['itemtypes']   = array_values(array_intersect(PluginKanbanConfig::ITEMTYPES, $tipos));
        $q['categorias']  = array_map('intval', PluginKanbanConfig::jsonParaLista($q['categorias'] ?? '{}'));
        $q['notificacoes'] = array_map('intval', PluginKanbanConfig::jsonParaLista($q['notificacoes'] ?? '{}'));
        if (!in_array($q['vinculo_auto'] ?? '', $q['itemtypes'], true)) {
            $q['vinculo_auto'] = '';
        }
        return $q;
    }

    /** Grupos do usuário logado (cache por requisição) */
    public static function gruposDoUsuario(int $users_id): array
    {
        static $cache = [];
        if (!isset($cache[$users_id])) {
            global $DB;
            $cache[$users_id] = [];
            foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $users_id]]) as $r) {
                $cache[$users_id][] = (int) $r['groups_id'];
            }
        }
        return $cache[$users_id];
    }

    /** Pode abrir e trabalhar no quadro (admins sempre podem) */
    public static function podeAcessar(?array $quadro): bool
    {
        if ($quadro === null || !Session::getLoginUserID()) {
            return false;
        }
        if (PluginKanbanConfig::ehAdmin()) {
            return true;
        }
        if (empty($quadro['is_active'])) {
            return false;
        }
        $uid    = (int) Session::getLoginUserID();
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($perfil > 0 && in_array($perfil, $quadro['perfis'], true)) {
            return true;
        }
        if (in_array($uid, $quadro['usuarios'], true)) {
            return true;
        }
        return (bool) array_intersect(self::gruposDoUsuario($uid), $quadro['grupos']);
    }

    /** Quadros que o usuário pode abrir, na ordem configurada */
    public static function quadrosAcessiveis(): array
    {
        global $DB;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!Session::getLoginUserID() || !$DB->tableExists(self::getTable())) {
            return $cache;
        }
        $where = ['is_deleted' => 0];
        if (!PluginKanbanConfig::ehAdmin()) {
            $where['is_active'] = 1;
            $where[] = (new DbUtils())->getEntitiesRestrictCriteria(self::getTable(), '', '', true);
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => ['ordem ASC', 'name ASC']]) as $q) {
            $q = self::normalizar($q);
            if (self::podeAcessar($q)) {
                $cache[$q['id']] = $q;
            }
        }
        return $cache;
    }

    /** Lista para a configuração (todos, inclusive inativos) */
    public static function listarTodos(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['ordem ASC', 'name ASC'],
        ]) as $q) {
            $lista[(int) $q['id']] = self::normalizar($q);
        }
        return $lista;
    }

    /** Grava os dados do quadro vindos da configuração. Devolve o id ou 0. */
    public static function salvar(array $dados): int
    {
        global $DB;

        $id   = (int) ($dados['id'] ?? 0);
        $nome = trim((string) ($dados['name'] ?? ''));
        if ($nome === '') {
            Session::addMessageAfterRedirect('Informe o nome do quadro.', false, ERROR);
            return 0;
        }

        $tipos = array_values(array_intersect(PluginKanbanConfig::ITEMTYPES, (array) ($dados['itemtypes'] ?? [])));
        $categorias = [];
        foreach (PluginKanbanConfig::ITEMTYPES as $tipo) {
            $categorias[$tipo] = max(0, (int) ($dados['categoria_' . $tipo] ?? 0));
        }
        $notif = [];
        foreach (array_keys(self::EVENTOS) as $evento) {
            $notif[$evento] = !empty($dados['notificar_' . $evento]) ? 1 : 0;
        }
        $vinculoAuto = (string) ($dados['vinculo_auto'] ?? '');

        $campos = [
            'name'                  => mb_substr($nome, 0, 255),
            'comment'               => trim((string) ($dados['comment'] ?? '')),
            'entities_id'           => max(0, (int) ($dados['entities_id'] ?? 0)),
            'is_recursive'          => !empty($dados['is_recursive']) ? 1 : 0,
            'is_active'             => !empty($dados['is_active']) ? 1 : 0,
            'ordem'                 => (int) ($dados['ordem'] ?? 0),
            'cor'                   => PluginKanbanConfig::corValida($dados['cor'] ?? '', '#206bc4'),
            'perfis'                => json_encode(PluginKanbanConfig::jsonParaIds($dados['perfis'] ?? [])),
            'usuarios'              => json_encode(PluginKanbanConfig::jsonParaIds($dados['usuarios'] ?? [])),
            'grupos'                => json_encode(PluginKanbanConfig::jsonParaIds($dados['grupos'] ?? [])),
            'itemtypes'             => json_encode($tipos),
            'vinculo_auto'          => in_array($vinculoAuto, $tipos, true) ? $vinculoAuto : '',
            'categorias'            => json_encode($categorias),
            'ticket_tipo'           => ((int) ($dados['ticket_tipo'] ?? 2)) === 1 ? 1 : 2,
            'atribuir_responsaveis' => !empty($dados['atribuir_responsaveis']) ? 1 : 0,
            'sincronizar_status'    => !empty($dados['sincronizar_status']) ? 1 : 0,
            'espelhar_comentarios'  => !empty($dados['espelhar_comentarios']) ? 1 : 0,
            'importar_followups'    => !empty($dados['importar_followups']) ? 1 : 0,
            'followup_privado'      => !empty($dados['followup_privado']) ? 1 : 0,
            'notificacoes'          => json_encode($notif),
        ];

        if ($id > 0) {
            $DB->update(self::getTable(), $campos, ['id' => $id]);
            return $id;
        }
        $campos['date_creation'] = date('Y-m-d H:i:s');
        $DB->insert(self::getTable(), $campos);
        return (int) $DB->insertId();
    }

    /** Envia o quadro para a lixeira (cards e fileiras continuam no banco) */
    public static function excluir(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['is_deleted' => 1], ['id' => $id]);
    }

    /** Duplica um quadro com as fileiras (sem os cards) */
    public static function duplicar(int $id): int
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $q) {
            unset($q['id'], $q['date_creation'], $q['date_mod']);
            $q['name'] = mb_substr($q['name'] . ' (cópia)', 0, 255);
            $DB->insert(self::getTable(), $q);
            $novo = (int) $DB->insertId();
            $mapa = [];
            foreach (PluginKanbanColuna::listar($id) as $c) {
                $antigo = (int) $c['id'];
                unset($c['id'], $c['date_mod']);
                $c['plugin_kanban_quadros_id'] = $novo;
                $c['validadores'] = json_encode($c['validadores']);
                $DB->insert(PluginKanbanColuna::getTable(), $c);
                $mapa[$antigo] = (int) $DB->insertId();
            }
            // Fileiras de destino da validação apontam para as cópias
            foreach ($mapa as $antigo => $nova) {
                foreach ($DB->request(['FROM' => PluginKanbanColuna::getTable(), 'WHERE' => ['id' => $nova]]) as $c) {
                    $DB->update(PluginKanbanColuna::getTable(), [
                        'coluna_aprovado' => $mapa[(int) $c['coluna_aprovado']] ?? 0,
                        'coluna_recusado' => $mapa[(int) $c['coluna_recusado']] ?? 0,
                    ], ['id' => $nova]);
                }
            }
            return $novo;
        }
        return 0;
    }

    public static function contarCards(int $quadros_id): int
    {
        global $DB;
        return (int) ($DB->request([
            'COUNT' => 'total',
            'FROM'  => PluginKanbanCard::getTable(),
            'WHERE' => ['plugin_kanban_quadros_id' => $quadros_id, 'is_deleted' => 0],
        ])->current()['total'] ?? 0);
    }

    /**
     * "Versão" do quadro: muda sempre que algo muda (card, fileira, comentário).
     * O navegador consulta em intervalos e só recarrega quando a versão mudou.
     */
    public static function versao(int $quadros_id): string
    {
        global $DB;
        $partes = [];

        $c = $DB->request([
            'SELECT' => [
                new \Glpi\DBAL\QueryExpression('COUNT(*) AS total'),
                new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('date_mod') . ') AS ultima'),
                new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('ordem') . ' + ' . $DB->quoteName('plugin_kanban_colunas_id') . ' * 7) AS posicoes'),
            ],
            'FROM'   => PluginKanbanCard::getTable(),
            'WHERE'  => ['plugin_kanban_quadros_id' => $quadros_id],
        ])->current();
        $partes[] = implode(':', [(int) $c['total'], (string) $c['ultima'], (string) $c['posicoes']]);

        $m = $DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('co.id') . ') AS ultimo')],
            'FROM'   => PluginKanbanComentario::getTable() . ' AS co',
            'INNER JOIN' => [
                PluginKanbanCard::getTable() . ' AS ca' => ['FKEY' => ['co' => 'plugin_kanban_cards_id', 'ca' => 'id']],
            ],
            'WHERE'  => ['ca.plugin_kanban_quadros_id' => $quadros_id],
        ])->current();
        $partes[] = (string) ($m['ultimo'] ?? 0);

        $col = $DB->request([
            'SELECT' => [
                new \Glpi\DBAL\QueryExpression('COUNT(*) AS total'),
                new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('date_mod') . ') AS ultima'),
            ],
            'FROM'   => PluginKanbanColuna::getTable(),
            'WHERE'  => ['plugin_kanban_quadros_id' => $quadros_id],
        ])->current();
        $partes[] = (int) $col['total'] . ':' . (string) $col['ultima'];

        foreach ($DB->request(['SELECT' => ['date_mod'], 'FROM' => self::getTable(), 'WHERE' => ['id' => $quadros_id]]) as $q) {
            $partes[] = (string) $q['date_mod'];
        }

        return substr(md5(implode('|', $partes)), 0, 16);
    }
}
