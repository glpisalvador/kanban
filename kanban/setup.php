<?php

/**
 * Plugin Kanban para GLPI 11 e 12
 * Quadros Kanban configuráveis (vários quadros, fileiras próprias) interligados a
 * Chamados, Problemas e Mudanças: status nos dois sentidos, acompanhamentos e validações.
 */

define('PLUGIN_KANBAN_VERSION', '1.0.1');
define('PLUGIN_KANBAN_MIN_GLPI', '11.0.0');
define('PLUGIN_KANBAN_MAX_GLPI', '12.99.99');

function plugin_init_kanban(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['kanban'] = true;

    if (!Plugin::isPluginActive('kanban')) {
        return;
    }

    Plugin::registerClass('PluginKanbanConfig');
    Plugin::registerClass('PluginKanbanMenu');
    Plugin::registerClass('PluginKanbanQuadro');
    Plugin::registerClass('PluginKanbanColuna');
    Plugin::registerClass('PluginKanbanCard', ['document_types' => true]);
    Plugin::registerClass('PluginKanbanComentario');
    Plugin::registerClass('PluginKanbanVisualizacao');
    Plugin::registerClass('PluginKanbanAnexo');
    Plugin::registerClass('PluginKanbanNotificacao');
    // Aba "Kanban" nos itens ITIL
    Plugin::registerClass('PluginKanbanVinculo', ['addtabon' => ['Ticket', 'Problem', 'Change']]);

    // Configuração pela engrenagem do marketplace
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['kanban'] = 'front/config.php';
    }

    // Menu em Ferramentas (aparece para quem tem acesso a algum quadro)
    $PLUGIN_HOOKS['menu_toadd']['kanban'] = ['tools' => 'PluginKanbanMenu'];

    // Atualizações em paralelo com os itens ITIL vinculados
    $PLUGIN_HOOKS['item_update']['kanban'] = [
        'Ticket'           => 'plugin_kanban_item_update_itil',
        'Problem'          => 'plugin_kanban_item_update_itil',
        'Change'           => 'plugin_kanban_item_update_itil',
        'TicketValidation' => 'plugin_kanban_item_validacao',
        'ChangeValidation' => 'plugin_kanban_item_validacao',
    ];
    $PLUGIN_HOOKS['item_add']['kanban'] = [
        'ITILFollowup' => 'plugin_kanban_item_add_followup',
        'ITILSolution' => 'plugin_kanban_item_add_solucao',
    ];
    $PLUGIN_HOOKS['item_purge']['kanban'] = [
        'Ticket'  => 'plugin_kanban_item_purge_itil',
        'Problem' => 'plugin_kanban_item_purge_itil',
        'Change'  => 'plugin_kanban_item_purge_itil',
    ];
}

function plugin_version_kanban(): array
{
    return [
        'name'         => 'Kanban',
        'version'      => PLUGIN_KANBAN_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_KANBAN_MIN_GLPI,
                'max' => PLUGIN_KANBAN_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_kanban_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_KANBAN_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI ' . PLUGIN_KANBAN_MIN_GLPI . ' ou superior.';
        return false;
    }
    return true;
}

function plugin_kanban_check_config($verbose = false): bool
{
    return true;
}
