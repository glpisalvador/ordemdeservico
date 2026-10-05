<?php

/**
 * Plugin Ordem de Serviço - item "Ordens de serviço" no menu Assistência
 */
class PluginOrdemdeservicoMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Ordens de serviço';
    }

    public static function getMenuName(): string
    {
        return 'Ordens de serviço';
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-certificate';
    }

    public static function canView(): bool
    {
        return PluginOrdemdeservicoOrdem::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $lista = PluginOrdemdeservicoConfig::url('ordem.php');
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $lista,
            'icon'  => self::getIcon(),
            'links' => ['search' => $lista],
        ];
        if (PluginOrdemdeservicoConfig::ehAdmin()) {
            $menu['links']['config'] = PluginOrdemdeservicoConfig::url('config.form.php');
        }
        return $menu;
    }
}
