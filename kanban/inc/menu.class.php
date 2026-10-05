<?php

/**
 * Plugin Kanban - item "Kanban" no menu Ferramentas
 */
class PluginKanbanMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Kanban';
    }

    public static function getMenuName(): string
    {
        return 'Kanban';
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-kanban';
    }

    public static function canView(): bool
    {
        return PluginKanbanConfig::ehAdmin() || count(PluginKanbanQuadro::quadrosAcessiveis()) > 0;
    }

    public static function canCreate(): bool
    {
        return self::canView();
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }

        $pagina = '/plugins/kanban/front/kanban.php';
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $pagina,
            'icon'  => self::getIcon(),
            'links' => ['search' => $pagina],
            'options' => [
                'quadros' => [
                    'title' => 'Quadros',
                    'page'  => $pagina,
                    'icon'  => self::getIcon(),
                    'links' => ['search' => $pagina],
                ],
            ],
        ];

        if (PluginKanbanConfig::ehAdmin()) {
            $config = '/plugins/kanban/front/config.form.php';
            $menu['links']['config'] = $config;
            $menu['options']['config'] = [
                'title' => 'Configuração',
                'page'  => $config,
                'icon'  => 'ti ti-settings',
                'links' => ['search' => $config],
            ];
        }

        return $menu;
    }
}
