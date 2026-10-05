<?php

/**
 * Plugin Kanban - instalação e ganchos de sincronização com os itens ITIL
 */

function plugin_kanban_install(): bool
{
    global $DB;

    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Configurações gerais (chave/valor)
    if (!$DB->tableExists('glpi_plugin_kanban_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }

    // Quadros
    if (!$DB->tableExists('glpi_plugin_kanban_quadros')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_quadros` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `comment` text,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `is_recursive` tinyint(1) NOT NULL DEFAULT 1,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `ordem` int NOT NULL DEFAULT 0,
            `cor` varchar(9) NOT NULL DEFAULT '#206bc4',
            `perfis` longtext,
            `usuarios` longtext,
            `grupos` longtext,
            `itemtypes` longtext,
            `vinculo_auto` varchar(20) NOT NULL DEFAULT '',
            `categorias` longtext,
            `ticket_tipo` tinyint NOT NULL DEFAULT 2,
            `atribuir_responsaveis` tinyint(1) NOT NULL DEFAULT 1,
            `sincronizar_status` tinyint(1) NOT NULL DEFAULT 1,
            `espelhar_comentarios` tinyint(1) NOT NULL DEFAULT 1,
            `importar_followups` tinyint(1) NOT NULL DEFAULT 1,
            `followup_privado` tinyint(1) NOT NULL DEFAULT 0,
            `notificacoes` longtext,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`)
        ) $opcoes");
    }

    // Fileiras (colunas) de cada quadro, com o mapa de status dos itens ITIL
    if (!$DB->tableExists('glpi_plugin_kanban_colunas')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_colunas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_kanban_quadros_id` int unsigned NOT NULL DEFAULT 0,
            `name` varchar(255) NOT NULL DEFAULT '',
            `cor` varchar(9) NOT NULL DEFAULT '#667382',
            `ordem` int NOT NULL DEFAULT 0,
            `limite_wip` int unsigned NOT NULL DEFAULT 0,
            `is_final` tinyint(1) NOT NULL DEFAULT 0,
            `pedir_comentario` tinyint(1) NOT NULL DEFAULT 0,
            `status_ticket` int NOT NULL DEFAULT 0,
            `status_problem` int NOT NULL DEFAULT 0,
            `status_change` int NOT NULL DEFAULT 0,
            `validacao_ativa` tinyint(1) NOT NULL DEFAULT 0,
            `validadores` longtext,
            `coluna_aprovado` int unsigned NOT NULL DEFAULT 0,
            `coluna_recusado` int unsigned NOT NULL DEFAULT 0,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `plugin_kanban_quadros_id` (`plugin_kanban_quadros_id`),
            KEY `ordem` (`ordem`)
        ) $opcoes");
    }

    // Cards
    if (!$DB->tableExists('glpi_plugin_kanban_cards')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_cards` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_kanban_quadros_id` int unsigned NOT NULL DEFAULT 0,
            `plugin_kanban_colunas_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `titulo` varchar(255) NOT NULL DEFAULT '',
            `descricao` longtext,
            `prioridade` tinyint NOT NULL DEFAULT 3,
            `prazo` date NULL DEFAULT NULL,
            `ordem` int NOT NULL DEFAULT 0,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `is_fechado` tinyint(1) NOT NULL DEFAULT 0,
            `validacao_status` tinyint NOT NULL DEFAULT 0,
            `validacao_colunas_id` int unsigned NOT NULL DEFAULT 0,
            `validacao_desde` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `plugin_kanban_quadros_id` (`plugin_kanban_quadros_id`),
            KEY `plugin_kanban_colunas_id` (`plugin_kanban_colunas_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `users_id` (`users_id`),
            KEY `is_deleted` (`is_deleted`),
            KEY `date_mod` (`date_mod`)
        ) $opcoes");
    }

    // Responsáveis de cada card
    if (!$DB->tableExists('glpi_plugin_kanban_cards_users')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_cards_users` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_kanban_cards_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `card_user` (`plugin_kanban_cards_id`, `users_id`),
            KEY `users_id` (`users_id`)
        ) $opcoes");
    }

    // Comentários e histórico do card (movimentações, acompanhamentos importados, validações)
    if (!$DB->tableExists('glpi_plugin_kanban_comentarios')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_comentarios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_kanban_cards_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `conteudo` longtext,
            `tipo` varchar(20) NOT NULL DEFAULT 'comentario',
            `colunas_id_de` int unsigned NOT NULL DEFAULT 0,
            `colunas_id_para` int unsigned NOT NULL DEFAULT 0,
            `itilfollowups_id` int unsigned NOT NULL DEFAULT 0,
            `is_private` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `plugin_kanban_cards_id` (`plugin_kanban_cards_id`),
            KEY `itilfollowups_id` (`itilfollowups_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // Quem abriu cada card
    if (!$DB->tableExists('glpi_plugin_kanban_visualizacoes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_kanban_visualizacoes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_kanban_cards_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `total` int unsigned NOT NULL DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `card_user` (`plugin_kanban_cards_id`, `users_id`)
        ) $opcoes");
    }

    // Configurações padrão
    $padroes = [
        'intervalo_atualizacao' => '15',
        'email_ativo'           => '0',
    ];
    foreach ($padroes as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_kanban_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_kanban_configs', ['name' => $nome, 'value' => $valor]);
        }
    }

    // Pasta temporária dos anexos enviados pelo quadro
    @mkdir(GLPI_TMP_DIR, 0755, true);

    return true;
}

function plugin_kanban_uninstall(): bool
{
    // As tabelas e os dados do plugin são mantidos: reinstalar recupera tudo.
    return true;
}

// =========================================================================
// Ganchos: o que acontece no Chamado/Problema/Mudança reflete nos cards
// =========================================================================

function plugin_kanban_item_update_itil(CommonDBTM $item): void
{
    if (class_exists('PluginKanbanVinculo')) {
        PluginKanbanVinculo::aoAtualizarItem($item);
    }
}

function plugin_kanban_item_add_followup(CommonDBTM $item): void
{
    if (class_exists('PluginKanbanVinculo')) {
        PluginKanbanVinculo::aoAdicionarAcompanhamento($item);
    }
}

function plugin_kanban_item_add_solucao(CommonDBTM $item): void
{
    if (class_exists('PluginKanbanVinculo')) {
        PluginKanbanVinculo::aoAdicionarSolucao($item);
    }
}

function plugin_kanban_item_validacao(CommonDBTM $item): void
{
    if (class_exists('PluginKanbanVinculo')) {
        PluginKanbanVinculo::aoAtualizarValidacao($item);
    }
}

function plugin_kanban_item_purge_itil(CommonDBTM $item): void
{
    if (class_exists('PluginKanbanVinculo')) {
        PluginKanbanVinculo::aoExcluirItem($item);
    }
}
