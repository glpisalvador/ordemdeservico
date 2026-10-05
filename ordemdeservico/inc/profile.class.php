<?php

/**
 * Plugin Ordem de Serviço - aba "Ordens de serviço" no perfil (direitos nativos)
 */
class PluginOrdemdeservicoProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Ordens de serviço';
    }

    public static function getAllRights(): array
    {
        return [[
            'itemtype' => 'PluginOrdemdeservicoOrdem',
            'label'    => 'Ordens de serviço',
            'field'    => PluginOrdemdeservicoConfig::DIREITO,
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Profile && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry('Ordens de serviço', 0, null, 'ti ti-file-certificate');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile) {
            return true;
        }
        $perfil = new Profile();
        $perfil->getFromDB($item->getID());
        $pode = Session::haveRight('profile', UPDATE);
        echo '<div class="spaced">';
        if ($pode) {
            echo '<form method="post" action="' . PluginOrdemdeservicoConfig::e(Profile::getFormURL()) . '">';
        }
        $perfil->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $pode,
            'default_class' => 'tab_bg_2',
            'title'         => 'Ordens de serviço',
        ]);
        if ($pode) {
            echo '<div class="center">' . Html::hidden('id', ['value' => $item->getID()])
                . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . '</div>';
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }
}
