<?php

/**
 * Plugin Kanban - configurações gerais e utilitários comuns
 */
class PluginKanbanConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    /** Tipos ITIL que um card pode ter vinculados */
    public const ITEMTYPES = ['Ticket', 'Problem', 'Change'];

    public static function getTypeName($nb = 0): string
    {
        return 'Kanban';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** Administra quadros e fileiras (tela de configuração) */
    public static function ehAdmin(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_plugin_kanban_configs',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]) as $row) {
            return $row['value'];
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => 'glpi_plugin_kanban_configs', 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            return $DB->update('glpi_plugin_kanban_configs', ['value' => $value], ['name' => $name]);
        }
        return $DB->insert('glpi_plugin_kanban_configs', ['name' => $name, 'value' => $value]);
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_kanban_configs']) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        return self::jsonParaLista(self::getConfig($name, '[]'));
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    /** Intervalo da atualização automática do quadro (segundos, 5 a 300) */
    public static function intervaloAtualizacao(): int
    {
        return max(5, min(300, (int) self::getConfig('intervalo_atualizacao', '15')));
    }

    public static function emailAtivo(): bool
    {
        return self::getConfig('email_ativo', '0') === '1';
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    /** JSON de lista -> array de inteiros positivos únicos */
    public static function jsonParaIds($valor): array
    {
        $lista = is_array($valor) ? $valor : (json_decode((string) $valor, true) ?: []);
        return array_values(array_unique(array_filter(array_map('intval', (array) $lista), fn($v) => $v > 0)));
    }

    public static function jsonParaLista($valor): array
    {
        $lista = is_array($valor) ? $valor : json_decode((string) $valor, true);
        return is_array($lista) ? $lista : [];
    }

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function richtextVazio(?string $valor): bool
    {
        return $valor === null || trim(strip_tags(str_replace('&nbsp;', ' ', $valor))) === '';
    }

    /** Nome de exibição do usuário (com cache por requisição) */
    public static function nomeUsuario(int $users_id): string
    {
        static $cache = [];
        if ($users_id <= 0) {
            return '';
        }
        if (!isset($cache[$users_id])) {
            global $DB;
            $cache[$users_id] = '';
            foreach ($DB->request([
                'SELECT' => ['name', 'firstname', 'realname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $users_id],
                'LIMIT'  => 1,
            ]) as $u) {
                $partes = array_filter([trim((string) $u['firstname']), trim((string) $u['realname'])]);
                $cache[$users_id] = $partes ? implode(' ', $partes) : (string) $u['name'];
            }
        }
        return $cache[$users_id];
    }

    public static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];
        $partes = array_values(array_filter($partes));
        if (!$partes) {
            return '?';
        }
        $ini = mb_substr($partes[0], 0, 1);
        if (count($partes) > 1) {
            $ini .= mb_substr($partes[count($partes) - 1], 0, 1);
        }
        return mb_strtoupper($ini);
    }

    public static function emailUsuario(int $users_id): string
    {
        global $DB;
        if ($users_id <= 0) {
            return '';
        }
        foreach ($DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $users_id],
            'ORDER'  => 'is_default DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            return trim((string) $row['email']);
        }
        return '';
    }

    /** Perfis do GLPI (glpi_profiles não tem is_deleted) */
    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = (string) $r['name'];
        }
        return $lista;
    }

    /** Grupos do GLPI (glpi_groups não tem is_deleted) */
    public static function listarGrupos(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename ASC']) as $r) {
            $lista[(int) $r['id']] = (string) ($r['completename'] ?: $r['name']);
        }
        return $lista;
    }

    /** Usuários ativos (para os seletores da configuração) */
    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
        ]) as $u) {
            $partes = array_filter([trim((string) $u['firstname']), trim((string) $u['realname'])]);
            $nome = $partes ? implode(' ', $partes) : (string) $u['name'];
            $lista[(int) $u['id']] = $nome . ($partes ? ' (' . $u['name'] . ')' : '');
        }
        return $lista;
    }

    /** Busca de usuários ativos por termo (seletor de responsáveis) */
    public static function buscarUsuarios(string $termo, int $limite = 25): array
    {
        global $DB;
        $where = ['is_active' => 1, 'is_deleted' => 0];
        $termo = trim($termo);
        if ($termo !== '') {
            $like = '%' . $termo . '%';
            $where[] = ['OR' => [
                ['name' => ['LIKE', $like]],
                ['firstname' => ['LIKE', $like]],
                ['realname' => ['LIKE', $like]],
                [new \Glpi\DBAL\QueryExpression(
                    'CONCAT(COALESCE(' . $DB->quoteName('firstname') . ", ''), ' ', COALESCE(" . $DB->quoteName('realname') . ", '')) LIKE " . $DB->quoteValue($like)
                )],
            ]];
        }
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => $where,
            'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
            'LIMIT'  => $limite,
        ]) as $u) {
            $partes = array_filter([trim((string) $u['firstname']), trim((string) $u['realname'])]);
            $nome = $partes ? implode(' ', $partes) : (string) $u['name'];
            $lista[] = ['id' => (int) $u['id'], 'nome' => $nome, 'login' => (string) $u['name'], 'iniciais' => self::iniciais($nome)];
        }
        return $lista;
    }

    /**
     * Entidades para os seletores: só as raízes e as que não são filhas de outras
     * (as entidades filhas ficam escondidas, como no restante dos plugins).
     */
    public static function idsEntidadesFilhas(): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    public static function buscarEntidades(string $termo, int $limite = 30): array
    {
        global $DB;
        $where = [];
        $filhas = self::idsEntidadesFilhas();
        if ($filhas) {
            $where['NOT'] = ['id' => $filhas];
        }
        // Somente entidades às quais o usuário tem acesso
        $ativas = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        if ($ativas) {
            $where['id'] = $ativas;
        }
        $termo = trim($termo);
        if ($termo !== '') {
            $like = '%' . $termo . '%';
            $where[] = ['OR' => [['name' => ['LIKE', $like]], ['completename' => ['LIKE', $like]]]];
        }
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'completename'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => $where,
            'ORDER'  => 'completename ASC',
            'LIMIT'  => $limite,
        ]) as $r) {
            $lista[] = ['id' => (int) $r['id'], 'nome' => (string) ($r['completename'] ?: $r['name'])];
        }
        return $lista;
    }

    public static function nomeEntidade(int $entities_id): string
    {
        return (string) Dropdown::getDropdownName('glpi_entities', $entities_id);
    }

    // =====================================================================
    // Tipos ITIL
    // =====================================================================

    public static function nomeItemtype(string $itemtype, int $nb = 1): string
    {
        return match ($itemtype) {
            'Ticket'  => $nb > 1 ? 'Chamados' : 'Chamado',
            'Problem' => $nb > 1 ? 'Problemas' : 'Problema',
            'Change'  => $nb > 1 ? 'Mudanças' : 'Mudança',
            default   => $itemtype,
        };
    }

    public static function iconeItemtype(string $itemtype): string
    {
        return match ($itemtype) {
            'Ticket'  => 'ti ti-alert-circle',
            'Problem' => 'ti ti-alert-triangle',
            'Change'  => 'ti ti-clipboard-check',
            default   => 'ti ti-link',
        };
    }

    /** Status nativos do tipo (inclui status acrescentados por outros plugins) */
    public static function statusDoTipo(string $itemtype): array
    {
        if (!in_array($itemtype, self::ITEMTYPES, true) || !class_exists($itemtype)) {
            return [];
        }
        $lista = [];
        foreach ($itemtype::getAllStatusArray() as $valor => $nome) {
            if (is_numeric($valor)) {
                $lista[(int) $valor] = (string) $nome;
            }
        }
        return $lista;
    }

    public static function nomeStatus(string $itemtype, int $status): string
    {
        return self::statusDoTipo($itemtype)[$status] ?? ('#' . $status);
    }

    /** Coluna da fileira que guarda o status para o tipo */
    public static function campoStatus(string $itemtype): string
    {
        return match ($itemtype) {
            'Ticket'  => 'status_ticket',
            'Problem' => 'status_problem',
            'Change'  => 'status_change',
            default   => '',
        };
    }

    public static function urlItem(string $itemtype, int $items_id): string
    {
        global $CFG_GLPI;
        if (!in_array($itemtype, self::ITEMTYPES, true) || $items_id <= 0) {
            return '';
        }
        return $itemtype::getFormURLWithID($items_id);
    }

    public static function prioridades(): array
    {
        return [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta', 6 => 'Urgente'];
    }

    /** Paleta leve para as fileiras e quadros (cores do tema Tabler do GLPI) */
    public static function paleta(): array
    {
        return [
            '#206bc4' => 'Azul', '#4299e1' => 'Azul claro', '#17a2b8' => 'Ciano', '#2fb344' => 'Verde',
            '#74b816' => 'Lima', '#f59f00' => 'Amarelo', '#f76707' => 'Laranja', '#d63939' => 'Vermelho',
            '#d6336c' => 'Rosa', '#ae3ec9' => 'Roxo', '#667382' => 'Cinza',
        ];
    }

    public static function corValida(?string $cor, string $padrao = '#667382'): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $cor) ? strtolower((string) $cor) : $padrao;
    }
}
