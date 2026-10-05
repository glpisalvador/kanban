<?php

/**
 * Plugin Kanban - quem abriu cada card (primeira e última vez, quantas vezes)
 */
class PluginKanbanVisualizacao extends CommonDBTM
{
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_kanban_visualizacoes';
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Visualizações' : 'Visualização';
    }

    public static function canView(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function registrar(int $cards_id): void
    {
        global $DB;
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0 || $cards_id <= 0) {
            return;
        }
        $agora = date('Y-m-d H:i:s');
        foreach ($DB->request(['SELECT' => ['id', 'total'], 'FROM' => self::getTable(), 'WHERE' => ['plugin_kanban_cards_id' => $cards_id, 'users_id' => $uid], 'LIMIT' => 1]) as $r) {
            $DB->update(self::getTable(), ['total' => (int) $r['total'] + 1, 'date_mod' => $agora], ['id' => (int) $r['id']]);
            return;
        }
        $DB->insert(self::getTable(), ['plugin_kanban_cards_id' => $cards_id, 'users_id' => $uid, 'total' => 1, 'date_creation' => $agora, 'date_mod' => $agora]);
    }

    public static function listar(int $cards_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_kanban_cards_id' => $cards_id],
            'ORDER' => 'date_mod DESC',
        ]) as $r) {
            $nome = PluginKanbanConfig::nomeUsuario((int) $r['users_id']);
            if ($nome !== '') {
                $lista[] = [
                    'nome'     => $nome,
                    'iniciais' => PluginKanbanConfig::iniciais($nome),
                    'total'    => (int) $r['total'],
                    'primeira' => Html::convDateTime($r['date_creation']),
                    'ultima'   => Html::convDateTime($r['date_mod']),
                ];
            }
        }
        return $lista;
    }
}
