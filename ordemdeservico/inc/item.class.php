<?php

/**
 * Plugin Ordem de Serviço - aba "Ordens de serviço" em chamados, problemas e mudanças:
 * lista as OS do item e gera uma nova (título, seções, itens privados, solicitante, técnico, observações).
 */
class PluginOrdemdeservicoItem extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Ordens de serviço';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonITILObject || $item->isNewItem() || !PluginOrdemdeservicoOrdem::canView()
            || !in_array($item->getType(), PluginOrdemdeservicoConfig::itensHabilitados(), true)) {
            return '';
        }
        $n = 0;
        if (!empty($_SESSION['glpishow_count_on_tabs'])) {
            $n = countElementsInTable(PluginOrdemdeservicoOrdem::TABELA, ['itemtype' => $item->getType(), 'items_id' => (int) $item->getID(), 'is_deleted' => 0]);
        }
        return self::createTabEntry('Ordens de serviço', $n, null, 'ti ti-file-certificate');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonITILObject) {
            self::mostrar($item);
        }
        return true;
    }

    public static function mostrar(CommonITILObject $item): void
    {
        $C = PluginOrdemdeservicoConfig::class;
        $e = [$C, 'e'];
        echo $C::assets();
        echo '<div class="ordemdeservico-pagina">';

        // OS já geradas
        $lista = PluginOrdemdeservicoOrdem::doItem($item->getType(), (int) $item->getID());
        echo '<div class="card ordemdeservico-card"><div class="card-header"><h5><i class="ti ti-file-certificate"></i> Ordens de serviço deste ' . $e(mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem($item->getType()))) . '</h5>'
            . '<a class="btn btn-sm btn-ghost-secondary ms-auto" href="' . $e($C::url('ordem.php')) . '"><i class="ti ti-list"></i><span>Todas as OS</span></a></div><div class="card-body p-0">';
        if (!$lista) {
            echo '<div class="ordemdeservico-vazio"><i class="ti ti-file-off"></i><span>Nenhuma ordem de serviço gerada ainda.</span></div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm table-hover ordemdeservico-tabela mb-0"><thead><tr>'
                . '<th>Número</th><th>Título</th><th>Status</th><th>Técnico</th><th>Solicitante</th><th>Emitida em</th><th>Gerada por</th></tr></thead><tbody>';
            $os = new PluginOrdemdeservicoOrdem();
            foreach ($lista as $l) {
                $os->fields = $l;
                echo '<tr><td class="text-nowrap"><a href="' . $e($os->getLinkURL()) . '"><strong>' . $e($l['numero']) . '</strong></a></td>'
                    . '<td>' . $e($l['name']) . '</td>'
                    . '<td>' . PluginOrdemdeservicoOrdem::seloStatus((string) $l['status']) . '</td>'
                    . '<td>' . $e($l['assinatura_tecnico_nome'] ?: $l['tecnico_nome']) . (!empty($l['assinatura_tecnico_data']) ? ' <i class="ti ti-circle-check text-success" title="Assinado"></i>' : '') . '</td>'
                    . '<td>' . $e($l['assinatura_solicitante_nome'] ?: $l['solicitante_nome']) . (!empty($l['assinatura_solicitante_data']) ? ' <i class="ti ti-circle-check text-success" title="Assinado"></i>' : '') . '</td>'
                    . '<td class="text-nowrap">' . $e(Html::convDateTime((string) $l['date_creation'])) . '</td>'
                    . '<td>' . $e(getUserName((int) $l['users_id'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';

        if (PluginOrdemdeservicoOrdem::canCreate()) {
            self::formularioGerar($item);
        }
        echo '</div>';
    }

    private static function formularioGerar(CommonITILObject $item): void
    {
        $C = PluginOrdemdeservicoConfig::class;
        $e = [$C, 'e'];
        $requerentes = PluginOrdemdeservicoDocumento::pessoas($item, CommonITILActor::REQUESTER);
        $tecnicos = PluginOrdemdeservicoDocumento::pessoas($item, CommonITILActor::ASSIGN);
        $titulo = $C::aplicarVariaveis((string) $C::getConfig('modelo_titulo'), PluginOrdemdeservicoDocumento::variaveisItem($item) + ['titulo' => '']);
        $padrao = $C::secoesPadrao();
        $podePrivado = Session::haveRight('followup', ITILFollowup::SEEPRIVATE) || Session::haveRight('task', CommonITILTask::SEEPRIVATE);
        $semValidacao = !class_exists($item->getType() . 'Validation');

        echo '<form method="post" action="' . $e($C::url('ordem.form.php')) . '" class="card ordemdeservico-card" data-ordemdeservico-gerar>';
        echo '<div class="card-header"><h5><i class="ti ti-file-plus"></i> Gerar ordem de serviço</h5></div><div class="card-body">';
        echo '<input type="hidden" name="itemtype" value="' . $e($item->getType()) . '"><input type="hidden" name="items_id" value="' . (int) $item->getID() . '">';

        echo '<div class="ordemdeservico-grade">';
        echo '<div class="ordemdeservico-campo ordemdeservico-campo-largo"><label for="ordemdeservico-novo-titulo">Título</label>'
            . '<input type="text" class="form-control form-control-sm" id="ordemdeservico-novo-titulo" name="name" maxlength="255" value="' . $e($titulo) . '"></div>';

        $opcoesSolic = '';
        foreach ($requerentes as $r) {
            $opcoesSolic .= '<option value="' . $e($r['nome']) . '" data-email="' . $e($r['email']) . '">';
        }
        echo '<div class="ordemdeservico-campo"><label for="ordemdeservico-novo-solicitante">Solicitante</label>'
            . '<input type="text" class="form-control form-control-sm" id="ordemdeservico-novo-solicitante" name="solicitante_nome" maxlength="255" list="ordemdeservico-requerentes" data-ordemdeservico-solicitante value="' . $e($requerentes[0]['nome'] ?? '') . '">'
            . '<datalist id="ordemdeservico-requerentes">' . $opcoesSolic . '</datalist></div>';
        echo '<div class="ordemdeservico-campo"><label for="ordemdeservico-novo-email">E-mail do solicitante</label>'
            . '<input type="email" class="form-control form-control-sm" id="ordemdeservico-novo-email" name="solicitante_email" maxlength="255" data-ordemdeservico-solicitante-email value="' . $e($requerentes[0]['email'] ?? '') . '"></div>';

        $opcoesTec = '';
        foreach ($tecnicos as $t) {
            $opcoesTec .= '<option value="' . $e($t['nome']) . '">';
        }
        echo '<div class="ordemdeservico-campo"><label for="ordemdeservico-novo-tecnico">Técnico responsável</label>'
            . '<input type="text" class="form-control form-control-sm" id="ordemdeservico-novo-tecnico" name="tecnico_nome" maxlength="255" list="ordemdeservico-tecnicos" value="' . $e($tecnicos[0]['nome'] ?? getUserName((int) Session::getLoginUserID())) . '">'
            . '<datalist id="ordemdeservico-tecnicos">' . $opcoesTec . '</datalist></div>';
        echo '</div>';

        echo '<div class="ordemdeservico-campo"><label>Seções do documento</label><div class="ordemdeservico-secoes">'
            . '<label class="ordemdeservico-opcao ordemdeservico-opcao-todos"><input type="checkbox" class="ordemdeservico-check" data-ordemdeservico-secoes-todas> Todas</label>';
        foreach (PluginOrdemdeservicoConfig::SECOES as $chave => $rotulo) {
            if ($chave === 'validacoes' && $semValidacao) {
                continue;
            }
            echo '<label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" name="_secoes[]" value="' . $e($chave) . '"' . (in_array($chave, $padrao, true) ? ' checked' : '') . '> ' . $e($rotulo) . '</label>';
        }
        echo '</div></div>';
        if ($podePrivado) {
            echo '<label class="ordemdeservico-opcao mb-2"><input type="checkbox" class="ordemdeservico-check" name="_privados" value="1"' . ((string) $C::getConfig('privados') === '1' ? ' checked' : '') . '> Incluir acompanhamentos e tarefas privados</label>';
        }

        echo '<div class="ordemdeservico-campo"><label>Observações</label>';
        Html::textarea([
            'name'            => 'observacoes',
            'value'           => '',
            'enable_richtext' => true,
            'cols'            => 100,
            'rows'            => 4,
        ]);
        echo '</div>';

        echo '<div class="ordemdeservico-rodape-form"><span class="ordemdeservico-pequeno"><i class="ti ti-info-circle"></i> O conteúdo do ' . $e(mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem($item->getType())))
            . ' é congelado na geração; enquanto ninguém assinar, dá para atualizá-lo pela OS.</span>'
            . '<button type="submit" name="add" value="1" class="btn btn-sm ordemdeservico-btn-principal"><i class="ti ti-file-plus"></i><span>Gerar ordem de serviço</span></button></div>';
        echo '</div>';
        Html::closeForm();
    }
}
