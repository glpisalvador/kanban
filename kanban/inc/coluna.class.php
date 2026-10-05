<?php

/**
 * Plugin Kanban - fileiras (colunas) de cada quadro.
 *
 * Cada fileira define, por tipo ITIL, o status que o item vinculado recebe quando o card entra
 * nela (0 = não altera) e, no sentido inverso, para qual fileira o card vai quando o status do
 * item muda no GLPI. Opcionalmente pede validação nativa (Chamado/Mudança) ao entrar.
 */
class PluginKanbanColuna extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Fileiras' : 'Fileira';
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

    public static function normalizar(array $c): array
    {
        foreach (['id', 'plugin_kanban_quadros_id', 'ordem', 'limite_wip', 'is_final', 'pedir_comentario',
                  'status_ticket', 'status_problem', 'status_change', 'validacao_ativa',
                  'coluna_aprovado', 'coluna_recusado'] as $campo) {
            $c[$campo] = (int) ($c[$campo] ?? 0);
        }
        $c['validadores'] = PluginKanbanConfig::jsonParaIds($c['validadores'] ?? '[]');
        $c['cor'] = PluginKanbanConfig::corValida($c['cor'] ?? '');
        return $c;
    }

    /** Fileiras do quadro na ordem de exibição */
    public static function listar(int $quadros_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_kanban_quadros_id' => $quadros_id],
            'ORDER' => ['ordem ASC', 'id ASC'],
        ]) as $c) {
            $lista[(int) $c['id']] = self::normalizar($c);
        }
        return $lista;
    }

    public static function carregar(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $c) {
            return self::normalizar($c);
        }
        return null;
    }

    public static function primeira(int $quadros_id): ?array
    {
        $todas = self::listar($quadros_id);
        return $todas ? reset($todas) : null;
    }

    /** Primeira fileira do quadro mapeada para o status do tipo (sentido GLPI -> quadro) */
    public static function colunaParaStatus(int $quadros_id, string $itemtype, int $status): ?array
    {
        $campo = PluginKanbanConfig::campoStatus($itemtype);
        if ($campo === '' || $status <= 0) {
            return null;
        }
        foreach (self::listar($quadros_id) as $c) {
            if ($c[$campo] === $status) {
                return $c;
            }
        }
        return null;
    }

    public static function contarCards(int $colunas_id): int
    {
        global $DB;
        return (int) ($DB->request([
            'COUNT' => 'total',
            'FROM'  => PluginKanbanCard::getTable(),
            'WHERE' => ['plugin_kanban_colunas_id' => $colunas_id, 'is_deleted' => 0],
        ])->current()['total'] ?? 0);
    }

    /**
     * Grava todas as fileiras do quadro de uma vez, na ordem recebida.
     * $linhas: chave => dados. A chave é o id (fileira existente) ou "nN" (nova);
     * coluna_aprovado/coluna_recusado também chegam como chave e são convertidas em id.
     * Fileiras ausentes são removidas e seus cards vão para a primeira fileira restante.
     */
    public static function salvarDoFormulario(int $quadros_id, array $linhas): bool
    {
        global $DB;

        $linhas = array_filter($linhas, fn($l) => is_array($l) && trim((string) ($l['name'] ?? '')) !== '');
        if (!$linhas) {
            Session::addMessageAfterRedirect('O quadro precisa de pelo menos uma fileira.', false, ERROR);
            return false;
        }

        $existentes = self::listar($quadros_id);
        $mapa  = [];  // chave do formulário => id gravado
        $ordem = 0;

        foreach ($linhas as $chave => $l) {
            $ordem += 10;
            $status = [];
            foreach (PluginKanbanConfig::ITEMTYPES as $tipo) {
                $campo = PluginKanbanConfig::campoStatus($tipo);
                $valor = (int) ($l[$campo] ?? 0);
                $status[$campo] = isset(PluginKanbanConfig::statusDoTipo($tipo)[$valor]) ? $valor : 0;
            }
            $campos = [
                'plugin_kanban_quadros_id' => $quadros_id,
                'name'             => mb_substr(trim((string) $l['name']), 0, 255),
                'cor'              => PluginKanbanConfig::corValida($l['cor'] ?? ''),
                'ordem'            => $ordem,
                'limite_wip'       => max(0, (int) ($l['limite_wip'] ?? 0)),
                'is_final'         => !empty($l['is_final']) ? 1 : 0,
                'pedir_comentario' => !empty($l['pedir_comentario']) ? 1 : 0,
                'validacao_ativa'  => !empty($l['validacao_ativa']) ? 1 : 0,
                'validadores'      => json_encode(PluginKanbanConfig::jsonParaIds($l['validadores'] ?? [])),
            ] + $status;

            $id = ctype_digit((string) $chave) ? (int) $chave : 0;
            if ($id > 0 && isset($existentes[$id])) {
                $DB->update(self::getTable(), $campos, ['id' => $id]);
            } else {
                $DB->insert(self::getTable(), $campos);
                $id = (int) $DB->insertId();
            }
            $mapa[(string) $chave] = $id;
        }

        // Destinos da validação (chaves -> ids); só fileiras deste quadro
        foreach ($linhas as $chave => $l) {
            $aprovado = $mapa[(string) ($l['coluna_aprovado'] ?? '')] ?? 0;
            $recusado = $mapa[(string) ($l['coluna_recusado'] ?? '')] ?? 0;
            $DB->update(self::getTable(), [
                'coluna_aprovado' => $aprovado,
                'coluna_recusado' => $recusado,
            ], ['id' => $mapa[(string) $chave]]);
        }

        // Fileiras removidas: cards vão para a primeira fileira
        $manter   = array_values($mapa);
        $destino  = $manter[0];
        foreach (array_keys($existentes) as $idAntigo) {
            if (in_array($idAntigo, $manter, true)) {
                continue;
            }
            $movidos = self::contarCards($idAntigo);
            $DB->update(PluginKanbanCard::getTable(), ['plugin_kanban_colunas_id' => $destino], ['plugin_kanban_colunas_id' => $idAntigo]);
            $DB->delete(self::getTable(), ['id' => $idAntigo]);
            if ($movidos > 0) {
                Session::addMessageAfterRedirect(
                    sprintf('Fileira "%s" removida: %d card(s) foram para a primeira fileira.', $existentes[$idAntigo]['name'], $movidos),
                    true,
                    WARNING
                );
            }
        }

        // Toca o quadro para os navegadores abertos recarregarem
        $DB->update(PluginKanbanQuadro::getTable(), ['date_mod' => date('Y-m-d H:i:s')], ['id' => $quadros_id]);
        return true;
    }

    /** Fileiras sugeridas para um quadro novo */
    public static function criarPadrao(int $quadros_id): void
    {
        global $DB;
        $padrao = [
            ['A fazer', '#667382', 1, 1, 1, 0],
            ['Em andamento', '#206bc4', 2, 2, 7, 0],
            ['Pendente', '#f59f00', 4, 4, 4, 0],
            ['Concluído', '#2fb344', 5, 5, 5, 1],
        ];
        $ordem = 0;
        foreach ($padrao as [$nome, $cor, $st, $sp, $sc, $final]) {
            $ordem += 10;
            $DB->insert(self::getTable(), [
                'plugin_kanban_quadros_id' => $quadros_id,
                'name'           => $nome,
                'cor'            => $cor,
                'ordem'          => $ordem,
                'is_final'       => $final,
                'status_ticket'  => isset(PluginKanbanConfig::statusDoTipo('Ticket')[$st]) ? $st : 0,
                'status_problem' => isset(PluginKanbanConfig::statusDoTipo('Problem')[$sp]) ? $sp : 0,
                'status_change'  => isset(PluginKanbanConfig::statusDoTipo('Change')[$sc]) ? $sc : 0,
                'validadores'    => '[]',
            ]);
        }
    }
}
