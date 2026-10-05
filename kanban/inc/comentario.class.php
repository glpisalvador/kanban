<?php

/**
 * Plugin Kanban - linha do tempo do card.
 * Tipos: comentario (pessoa), movimentacao, sistema, followup (importado do item ITIL),
 * solucao (solução do item ITIL) e validacao.
 */
class PluginKanbanComentario extends CommonDBTM
{
    public const TIPOS = [
        'comentario'   => ['Comentário', 'ti ti-message'],
        'movimentacao' => ['Movimentação', 'ti ti-arrows-right-left'],
        'sistema'      => ['Registro', 'ti ti-info-circle'],
        'followup'     => ['Acompanhamento', 'ti ti-message-forward'],
        'solucao'      => ['Solução', 'ti ti-circle-check'],
        'validacao'    => ['Validação', 'ti ti-shield-check'],
    ];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Comentários' : 'Comentário';
    }

    public static function canView(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function canCreate(): bool
    {
        return PluginKanbanMenu::canView();
    }

    public static function registrar(
        int $cards_id,
        string $tipo,
        string $conteudo,
        int $users_id = 0,
        int $de = 0,
        int $para = 0,
        int $itilfollowups_id = 0,
        int $is_private = 0
    ): int {
        global $DB;
        $DB->insert(self::getTable(), [
            'plugin_kanban_cards_id' => $cards_id,
            'users_id'         => $users_id,
            'conteudo'         => $conteudo,
            'tipo'             => isset(self::TIPOS[$tipo]) ? $tipo : 'sistema',
            'colunas_id_de'    => $de,
            'colunas_id_para'  => $para,
            'itilfollowups_id' => $itilfollowups_id,
            'is_private'       => $is_private,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $DB->insertId();
        PluginKanbanCard::toque($cards_id);
        return $id;
    }

    /** Comentário de uma pessoa: grava, espelha no item ITIL e notifica */
    public static function comentar(int $cards_id, string $conteudo): array
    {
        if (PluginKanbanConfig::richtextVazio($conteudo)) {
            return [0, 'Escreva o comentário.'];
        }
        $uid = (int) Session::getLoginUserID();
        $id  = self::registrar($cards_id, 'comentario', $conteudo, $uid);
        $followup = PluginKanbanVinculo::espelharComentario($cards_id, $conteudo, $uid);
        if ($followup > 0) {
            global $DB;
            $DB->update(self::getTable(), ['itilfollowups_id' => $followup], ['id' => $id]);
        }
        PluginKanbanNotificacao::enviar($cards_id, 'comentario', ['comentario' => $conteudo]);
        return [$id, ''];
    }

    public static function listar(int $cards_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_kanban_cards_id' => $cards_id],
            'ORDER' => ['date_creation DESC', 'id DESC'],
        ]) as $r) {
            $lista[] = $r;
        }
        return $lista;
    }

    /** Só o autor (ou um administrador) apaga os próprios comentários */
    public static function excluir(int $id): string
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $r) {
            if ($r['tipo'] !== 'comentario') {
                return 'Somente comentários podem ser apagados.';
            }
            if ((int) $r['users_id'] !== (int) Session::getLoginUserID() && !PluginKanbanConfig::ehAdmin()) {
                return 'Você só pode apagar os seus comentários.';
            }
            if (!PluginKanbanCard::podeAcessar((int) $r['plugin_kanban_cards_id'])) {
                return 'Sem acesso a este card.';
            }
            $DB->delete(self::getTable(), ['id' => $id]);
            PluginKanbanCard::toque((int) $r['plugin_kanban_cards_id']);
            return '';
        }
        return 'Comentário não encontrado.';
    }

    /** HTML seguro de conteúdo rico (as imagens coladas ficam em base64 dentro do HTML) */
    public static function htmlSeguro(string $html): string
    {
        return \Glpi\RichText\RichText::getSafeHtml($html);
    }

    public static function renderLinhaDoTempo(int $cards_id): string
    {
        $itens = self::listar($cards_id);
        if (!$itens) {
            return '<div class="kanban-vazio"><i class="ti ti-message-off"></i> Nenhum comentário ainda.</div>';
        }
        $eu  = (int) Session::getLoginUserID();
        $adm = PluginKanbanConfig::ehAdmin();
        $h = '';
        foreach ($itens as $r) {
            $tipo  = isset(self::TIPOS[$r['tipo']]) ? $r['tipo'] : 'sistema';
            $uid   = (int) $r['users_id'];
            $nome  = $uid > 0 ? PluginKanbanConfig::nomeUsuario($uid) : 'Automático';
            $data  = Html::convDateTime($r['date_creation']);
            $pessoa = in_array($tipo, ['comentario', 'followup', 'solucao'], true);

            $h .= '<div class="kanban-tl-item kanban-tl-' . $tipo . '">';
            if ($pessoa) {
                $h .= '<span class="kanban-avatar" title="' . PluginKanbanConfig::e($nome) . '">' . PluginKanbanConfig::e(PluginKanbanConfig::iniciais($nome)) . '</span>';
            } else {
                $h .= '<span class="kanban-tl-icone"><i class="' . self::TIPOS[$tipo][1] . '"></i></span>';
            }
            $h .= '<div class="kanban-tl-corpo">';
            $h .= '<div class="kanban-tl-cabecalho"><b>' . PluginKanbanConfig::e($nome) . '</b>';
            if ($tipo !== 'comentario') {
                $h .= '<span class="kanban-tl-tipo">' . self::TIPOS[$tipo][0] . '</span>';
            }
            if ((int) $r['is_private'] === 1) {
                $h .= '<span class="kanban-tl-tipo kanban-tl-privado"><i class="ti ti-lock"></i> Privado</span>';
            }
            if ((int) $r['itilfollowups_id'] > 0 && $tipo === 'comentario') {
                $h .= '<span class="kanban-tl-tipo" title="Também registrado como acompanhamento no item vinculado"><i class="ti ti-link"></i> no item</span>';
            }
            $h .= '<span class="kanban-tl-data">' . PluginKanbanConfig::e($data) . '</span>';
            if ($tipo === 'comentario' && ($uid === $eu || $adm)) {
                $h .= '<button type="button" class="kanban-tl-apagar" data-kanban-apagar-comentario="' . (int) $r['id'] . '" title="Apagar comentário"><i class="ti ti-trash"></i></button>';
            }
            $h .= '</div>';
            $h .= '<div class="kanban-tl-texto">' . self::htmlSeguro((string) $r['conteudo']) . '</div>';
            $h .= '</div></div>';
        }
        return $h;
    }
}
