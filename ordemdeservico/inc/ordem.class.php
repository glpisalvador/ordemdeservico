<?php

/**
 * Plugin Ordem de Serviço - a ordem de serviço (item nativo do GLPI).
 * Lista pela busca nativa (filtros, colunas, exportação, ações em massa, lixeira). Abas: Documento
 * (visualização, assinaturas, PDF, impressão, e-mail, anexo ao item), dados da OS, Envios, Documentos
 * e Histórico. O conteúdo do chamado/problema/mudança é congelado na geração e pode ser atualizado
 * enquanto ninguém assinou.
 */
class PluginOrdemdeservicoOrdem extends CommonDBTM
{
    // $rightname e $dohistory não são redeclaradas: são tipadas no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_ordemdeservico_documentos';

    public const STATUS = [
        'emitida'   => 'Emitida',
        'parcial'   => 'Assinada em parte',
        'assinada'  => 'Assinada',
        'cancelada' => 'Cancelada',
    ];

    /** Campos que a pessoa pode alterar depois da geração */
    private const EDITAVEIS = ['name', 'observacoes', 'solicitante_nome', 'solicitante_email', 'tecnico_nome'];

    public function __construct()
    {
        parent::__construct();
        $this->dohistory = true;
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Ordens de serviço' : 'Ordem de serviço';
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-certificate';
    }

    public static function getNameField(): string
    {
        return 'numero';
    }

    private static function pode(int $direito): bool
    {
        return (bool) Session::haveRight(PluginOrdemdeservicoConfig::DIREITO, $direito);
    }

    public static function canView(): bool
    {
        return self::pode(READ);
    }

    public static function canCreate(): bool
    {
        return self::pode(CREATE);
    }

    public static function canUpdate(): bool
    {
        return self::pode(UPDATE);
    }

    public static function canDelete(): bool
    {
        return self::pode(DELETE);
    }

    public static function canPurge(): bool
    {
        return self::pode(PURGE);
    }

    public function getRights($interface = 'central')
    {
        return [
            READ   => __('Read'),
            CREATE => 'Gerar',
            UPDATE => 'Editar e assinar',
            DELETE => __('Delete'),
            PURGE  => __('Delete permanently'),
        ];
    }

    public static function seloStatus(string $status): string
    {
        return '<span class="ordemdeservico-selo ordemdeservico-selo-' . PluginOrdemdeservicoConfig::e($status) . '">'
            . PluginOrdemdeservicoConfig::e(self::STATUS[$status] ?? $status) . '</span>';
    }

    /** Link para o item de origem: "Chamado #12 - Título" */
    public static function linkOrigem(string $itemtype, int $id, bool $comTitulo = true): string
    {
        $C = PluginOrdemdeservicoConfig::class;
        $rotulo = PluginOrdemdeservicoDocumento::rotuloItem($itemtype) . ' #' . $id;
        $item = PluginOrdemdeservicoDocumento::item($itemtype, $id);
        if (!$item) {
            return $C::e($rotulo) . ' <small class="text-muted">(excluído)</small>';
        }
        $titulo = $comTitulo ? ' - ' . $item->fields['name'] : '';
        return '<a href="' . $C::e($item->getLinkURL()) . '">' . $C::e($rotulo . $titulo) . '</a>';
    }

    // =====================================================================
    // Abas
    // =====================================================================

    public function defineTabs($options = [])
    {
        $abas = [];
        // "Documento" primeiro: é o que se abre ao entrar na OS
        $this->addStandardTab(self::class, $abas, $options);
        $this->addDefaultFormTab($abas);
        $this->addStandardTab('Document_Item', $abas, $options);
        $this->addStandardTab('Log', $abas, $options);
        return $abas;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem()) {
            return '';
        }
        $envios = $_SESSION['glpishow_count_on_tabs'] ?? true ? PluginOrdemdeservicoEnvio::contar((int) $item->getID()) : 0;
        return [
            1 => self::createTabEntry('Documento', 0, null, 'ti ti-file-text'),
            2 => self::createTabEntry('Envios', $envios, null, 'ti ti-mail'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            if ((int) $tabnum === 1) {
                $item->mostrarDocumento();
            } else {
                PluginOrdemdeservicoEnvio::mostrarAba($item);
            }
        }
        return true;
    }

    // =====================================================================
    // Gravação
    // =====================================================================

    public function prepareInputForAdd($input)
    {
        $erro = function (string $m) {
            Session::addMessageAfterRedirect($m, false, ERROR);
            return false;
        };
        $itemtype = (string) ($input['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? 0);
        if (!in_array($itemtype, PluginOrdemdeservicoConfig::itensHabilitados(), true)) {
            return $erro('Ordens de serviço não estão habilitadas para este tipo de item.');
        }
        $item = PluginOrdemdeservicoDocumento::item($itemtype, $items_id);
        if (!$item || !$item->canViewItem()) {
            return $erro('Item de origem não encontrado ou sem permissão de leitura.');
        }
        $secoes = isset($input['_secoes'])
            ? array_values(array_intersect(array_keys(PluginOrdemdeservicoConfig::SECOES), array_map('strval', (array) $input['_secoes'])))
            : PluginOrdemdeservicoConfig::secoesPadrao();
        if (!$secoes) {
            return $erro('Escolha pelo menos uma seção para a ordem de serviço.');
        }
        $privados = !empty($input['_privados']);

        $nome = trim(strip_tags((string) ($input['name'] ?? '')));
        if ($nome === '') {
            $nome = PluginOrdemdeservicoConfig::aplicarVariaveis((string) PluginOrdemdeservicoConfig::getConfig('modelo_titulo'), PluginOrdemdeservicoDocumento::variaveisItem($item) + ['numero' => '', 'titulo' => '']);
        }
        $requerentes = PluginOrdemdeservicoDocumento::pessoas($item, CommonITILActor::REQUESTER);
        $tecnicos = PluginOrdemdeservicoDocumento::pessoas($item, CommonITILActor::ASSIGN);

        $novo = [
            'name'              => mb_substr($nome, 0, 255),
            'entities_id'       => (int) $item->fields['entities_id'],
            'is_recursive'      => 0,
            'itemtype'          => $itemtype,
            'items_id'          => $items_id,
            'status'            => 'emitida',
            'secoes'            => json_encode($secoes),
            'privados'          => (int) $privados,
            'conteudo'          => PluginOrdemdeservicoDocumento::montar($item, $secoes, $privados),
            'observacoes'       => (string) ($input['observacoes'] ?? ''),
            'solicitante_nome'  => mb_substr(trim(strip_tags((string) ($input['solicitante_nome'] ?? ($requerentes[0]['nome'] ?? '')))), 0, 255),
            'solicitante_email' => mb_substr(trim((string) ($input['solicitante_email'] ?? ($requerentes[0]['email'] ?? ''))), 0, 255),
            'tecnico_nome'      => mb_substr(trim(strip_tags((string) ($input['tecnico_nome'] ?? ($tecnicos[0]['nome'] ?? getUserName((int) Session::getLoginUserID()))))), 0, 255),
            'users_id'          => (int) Session::getLoginUserID(),
        ];
        if ($novo['solicitante_email'] !== '' && !filter_var($novo['solicitante_email'], FILTER_VALIDATE_EMAIL)) {
            return $erro('E-mail do solicitante inválido.');
        }
        // Arquivos colados no editor de observações (o GLPI envia em _observacoes)
        foreach (['_observacoes', '_prefix_observacoes', '_tag_observacoes'] as $k) {
            if (isset($input[$k])) {
                $novo[$k] = $input[$k];
            }
        }
        return $novo;
    }

    public function post_addItem()
    {
        global $DB;
        $prefixo = preg_replace('/[^A-Za-z0-9]+/', '', (string) PluginOrdemdeservicoConfig::getConfig('prefixo')) ?: 'OS';
        $numero = $prefixo . '-' . date('Y') . '-' . str_pad((string) $this->getID(), 5, '0', STR_PAD_LEFT);
        $DB->update(self::TABELA, ['numero' => $numero], ['id' => (int) $this->getID()]);
        $this->fields['numero'] = $numero;
        // Título com {numero}: preenchido agora que o número existe
        if (str_contains((string) $this->fields['name'], '{numero}')) {
            $this->fields['name'] = str_replace('{numero}', $numero, (string) $this->fields['name']);
            $DB->update(self::TABELA, ['name' => $this->fields['name']], ['id' => (int) $this->getID()]);
        }
        $this->anexarArquivosObservacoes();
        Log::history((int) $this->fields['items_id'], (string) $this->fields['itemtype'], [0, '', 'Ordem de serviço ' . $numero . ' gerada'], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    public function prepareInputForUpdate($input)
    {
        if (($this->fields['status'] ?? '') === 'cancelada') {
            Session::addMessageAfterRedirect('Ordem de serviço cancelada: reative-a para editar.', false, ERROR);
            return false;
        }
        $permitidos = array_merge(['id', '_observacoes', '_prefix_observacoes', '_tag_observacoes'], self::EDITAVEIS);
        $input = array_intersect_key($input, array_flip($permitidos));
        foreach (['name', 'solicitante_nome', 'tecnico_nome'] as $c) {
            if (isset($input[$c])) {
                $input[$c] = mb_substr(trim(strip_tags((string) $input[$c])), 0, 255);
            }
        }
        if (isset($input['name']) && $input['name'] === '') {
            Session::addMessageAfterRedirect('Informe o título.', false, ERROR);
            return false;
        }
        if (isset($input['solicitante_email'])) {
            $input['solicitante_email'] = mb_substr(trim((string) $input['solicitante_email']), 0, 255);
            if ($input['solicitante_email'] !== '' && !filter_var($input['solicitante_email'], FILTER_VALIDATE_EMAIL)) {
                Session::addMessageAfterRedirect('E-mail do solicitante inválido.', false, ERROR);
                return false;
            }
        }
        return $input;
    }

    public function post_updateItem($history = true)
    {
        $this->anexarArquivosObservacoes();
    }

    /** Imagens coladas nas observações viram documentos da OS (e são embutidas na renderização) */
    private function anexarArquivosObservacoes(): void
    {
        if (!empty($this->input['_observacoes'])) {
            $this->input = $this->addFiles($this->input, ['force_update' => true, 'content_field' => 'observacoes', 'name' => 'observacoes']);
        }
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $DB->delete(PluginOrdemdeservicoEnvio::TABELA, ['plugin_ordemdeservico_documentos_id' => (int) $this->getID()]);
        PluginOrdemdeservicoAssinatura::removerArquivos((int) $this->getID());
        parent::cleanDBonPurge();
    }

    /** Refaz o conteúdo congelado a partir do item (só sem assinaturas) */
    public function regenerar(): string
    {
        global $DB;
        if ($this->fields['status'] === 'cancelada') {
            return 'Ordem de serviço cancelada.';
        }
        if (!empty($this->fields['assinatura_tecnico_data']) || !empty($this->fields['assinatura_solicitante_data'])) {
            return 'A ordem de serviço já tem assinatura: remova as assinaturas antes de atualizar o conteúdo.';
        }
        $item = PluginOrdemdeservicoDocumento::item((string) $this->fields['itemtype'], (int) $this->fields['items_id']);
        if (!$item || !$item->canViewItem()) {
            return 'O item de origem não existe mais ou você não pode vê-lo.';
        }
        $secoes = json_decode((string) $this->fields['secoes'], true) ?: PluginOrdemdeservicoConfig::secoesPadrao();
        $DB->update(self::TABELA, ['conteudo' => PluginOrdemdeservicoDocumento::montar($item, $secoes, (bool) $this->fields['privados'])], ['id' => (int) $this->getID()]);
        $this->getFromDB((int) $this->getID());
        Log::history((int) $this->getID(), self::class, [0, '', 'Conteúdo atualizado a partir do ' . mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem($item->getType()))], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return '';
    }

    public function cancelar(bool $cancelar): void
    {
        global $DB;
        $status = $cancelar ? 'cancelada' : PluginOrdemdeservicoAssinatura::statusPorAssinaturas(['status' => ''] + $this->fields);
        $DB->update(self::TABELA, ['status' => $status], ['id' => (int) $this->getID()]);
        $this->getFromDB((int) $this->getID());
        Log::history((int) $this->getID(), self::class, [0, '', $cancelar ? 'Ordem de serviço cancelada' : 'Ordem de serviço reativada'], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    /** OS (não excluídas) de um item */
    public static function doItem(string $itemtype, int $items_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id, 'is_deleted' => 0],
            'ORDER' => 'id DESC',
        ]), false);
    }

    // =====================================================================
    // Busca nativa
    // =====================================================================

    public function rawSearchOptions()
    {
        $t = self::TABELA;
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'numero', 'name' => 'Número', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'name', 'name' => 'Título', 'datatype' => 'string'],
            ['id' => 4, 'table' => $t, 'field' => 'status', 'name' => 'Status', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'itemtype', 'name' => 'Tipo de origem', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 6, 'table' => $t, 'field' => 'items_id', 'name' => 'Item de origem', 'datatype' => 'specific', 'additionalfields' => ['itemtype'], 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'solicitante_nome', 'name' => 'Solicitante', 'datatype' => 'string'],
            ['id' => 8, 'table' => $t, 'field' => 'solicitante_email', 'name' => 'E-mail do solicitante', 'datatype' => 'email'],
            ['id' => 9, 'table' => $t, 'field' => 'tecnico_nome', 'name' => 'Técnico responsável', 'datatype' => 'string'],
            ['id' => 10, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Gerada por', 'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false],
            ['id' => 11, 'table' => $t, 'field' => 'assinatura_tecnico_data', 'name' => 'Assinatura do técnico', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 12, 'table' => $t, 'field' => 'assinatura_solicitante_data', 'name' => 'Assinatura do solicitante', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 13, 'table' => $t, 'field' => 'assinatura_solicitante_nome', 'name' => 'Assinado pelo solicitante (nome)', 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 14, 'table' => $t, 'field' => 'observacoes', 'name' => 'Observações', 'datatype' => 'text', 'htmltext' => true, 'massiveaction' => false],
            [
                'id'            => 15,
                'table'         => PluginOrdemdeservicoEnvio::TABELA,
                'field'         => 'id',
                'name'          => 'Envios por e-mail',
                'datatype'      => 'count',
                'forcegroupby'  => true,
                'usehaving'     => true,
                'massiveaction' => false,
                'joinparams'    => ['jointype' => 'child', 'linkfield' => 'plugin_ordemdeservico_documentos_id'],
            ],
            ['id' => 16, 'table' => $t, 'field' => 'token_validade', 'name' => 'Link de assinatura válido até', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 121, 'table' => $t, 'field' => 'date_creation', 'name' => 'Data de emissão', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $valores = is_array($values) ? $values : [$field => $values];
        switch ($field) {
            case 'status':
                return self::seloStatus((string) ($valores[$field] ?? ''));
            case 'itemtype':
                return PluginOrdemdeservicoConfig::e(PluginOrdemdeservicoDocumento::rotuloItem((string) ($valores[$field] ?? '')));
            case 'items_id':
                if (!empty($valores['itemtype'])) {
                    return self::linkOrigem((string) $valores['itemtype'], (int) $valores[$field]);
                }
                return (string) (int) ($valores[$field] ?? 0);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $valores = is_array($values) ? $values : [$field => $values];
        $options['display'] = false;
        $options['value'] = $valores[$field] ?? '';
        switch ($field) {
            case 'status':
                return Dropdown::showFromArray($name, self::STATUS, $options);
            case 'itemtype':
                return Dropdown::showFromArray($name, PluginOrdemdeservicoConfig::ITENS, $options);
            case 'items_id':
                return '<input type="number" min="1" class="form-control" name="' . PluginOrdemdeservicoConfig::e($name) . '" value="' . PluginOrdemdeservicoConfig::e($options['value']) . '">';
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // =====================================================================
    // Formulário (dados editáveis da OS)
    // =====================================================================

    public function showForm($ID, array $options = [])
    {
        $C = PluginOrdemdeservicoConfig::class;
        $e = [$C, 'e'];
        if (!$this->getFromDB((int) $ID)) {
            echo '<div class="ordemdeservico-vazio"><i class="ti ti-file-off"></i><span>Ordens de serviço são geradas na aba <strong>Ordens de serviço</strong> de um chamado, problema ou mudança.</span></div>';
            return false;
        }
        $this->initForm($ID, $options);
        $cancelada = $this->fields['status'] === 'cancelada';
        if ($cancelada) {
            $options['canedit'] = false;
        }
        echo $C::assets();
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>Número</td><td><strong>' . $e($this->fields['numero']) . '</strong></td>'
            . '<td>Status</td><td>' . self::seloStatus((string) $this->fields['status']) . '</td></tr>';
        echo '<tr class="tab_bg_1"><td>Origem</td><td>' . self::linkOrigem((string) $this->fields['itemtype'], (int) $this->fields['items_id']) . '</td>'
            . '<td>Gerada por</td><td>' . $e(getUserName((int) $this->fields['users_id'])) . ' em ' . $e(Html::convDateTime((string) $this->fields['date_creation'])) . '</td></tr>';

        echo '<tr class="tab_bg_1"><td><label for="ordemdeservico-titulo">Título <span class="required">*</span></label></td><td colspan="3">'
            . '<input type="text" id="ordemdeservico-titulo" class="form-control" name="name" maxlength="255" required value="' . $e($this->fields['name']) . '"></td></tr>';

        echo '<tr class="tab_bg_1"><td><label for="ordemdeservico-solicitante">Solicitante</label></td><td>'
            . '<input type="text" id="ordemdeservico-solicitante" class="form-control" name="solicitante_nome" maxlength="255" value="' . $e($this->fields['solicitante_nome']) . '"></td>'
            . '<td><label for="ordemdeservico-solicitante-email">E-mail do solicitante</label></td><td>'
            . '<input type="email" id="ordemdeservico-solicitante-email" class="form-control" name="solicitante_email" maxlength="255" value="' . $e($this->fields['solicitante_email']) . '"></td></tr>';

        $secoes = array_map(fn($s) => PluginOrdemdeservicoConfig::SECOES[$s] ?? $s, json_decode((string) $this->fields['secoes'], true) ?: []);
        echo '<tr class="tab_bg_1"><td><label for="ordemdeservico-tecnico">Técnico responsável</label></td><td>'
            . '<input type="text" id="ordemdeservico-tecnico" class="form-control" name="tecnico_nome" maxlength="255" value="' . $e($this->fields['tecnico_nome']) . '"></td>'
            . '<td>Seções</td><td class="ordemdeservico-pequeno">' . $e(implode(', ', $secoes)) . ((int) $this->fields['privados'] ? ' · com itens privados' : '') . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>Observações</td><td colspan="3">';
        if ($cancelada) {
            echo '<div style="font-size:12px;color:#333;padding:4px 0;">' . PluginOrdemdeservicoDocumento::html((string) $this->fields['observacoes']) . '</div>';
        } else {
            Html::textarea([
                'name'            => 'observacoes',
                'value'           => $this->fields['observacoes'] ?? '',
                'enable_richtext' => true,
                'cols'            => 100,
                'rows'            => 6,
            ]);
        }
        echo '</td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    // =====================================================================
    // Aba "Documento"
    // =====================================================================

    public function mostrarDocumento(): void
    {
        $C = PluginOrdemdeservicoConfig::class;
        $e = [$C, 'e'];
        $id = (int) $this->getID();
        $f = $this->fields;
        $cancelada = $f['status'] === 'cancelada';
        $podeEditar = $this->can($id, UPDATE) && !$cancelada;
        $item = PluginOrdemdeservicoDocumento::item((string) $f['itemtype'], (int) $f['items_id']);
        $podeAnexar = $item && Document::canCreate() && $item->canAddItem('Document');
        $temAssinatura = !empty($f['assinatura_tecnico_data']) || !empty($f['assinatura_solicitante_data']);

        echo $C::assets();
        echo '<div class="ordemdeservico-pagina" data-ordemdeservico-os data-id="' . $id . '" data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '"'
            . ' data-nome="' . $e(PluginOrdemdeservicoEnvio::nomeArquivo($this)) . '" data-usuario="' . $e(getUserName((int) Session::getLoginUserID())) . '">';

        // Barra superior: número, status e ações
        echo '<div class="ordemdeservico-barra">'
            . '<div class="ordemdeservico-barra-titulo"><span class="ordemdeservico-numero">' . $e($f['numero']) . '</span>' . self::seloStatus((string) $f['status'])
            . '<span class="ordemdeservico-pequeno">' . self::linkOrigem((string) $f['itemtype'], (int) $f['items_id']) . '</span></div>'
            . '<div class="ordemdeservico-acoes">'
            . '<button type="button" class="btn btn-sm ordemdeservico-btn-principal" data-ordemdeservico-pdf><i class="ti ti-file-type-pdf"></i><span>Baixar PDF</span></button>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-imprimir><i class="ti ti-printer"></i><span>Imprimir</span></button>'
            . (!$cancelada ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-email-abrir><i class="ti ti-mail"></i><span>Enviar por e-mail</span></button>' : '')
            . ($podeAnexar ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-anexar><i class="ti ti-paperclip"></i><span>Anexar PDF ao ' . $e(mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem((string) $f['itemtype']))) . '</span></button>' : '')
            . ($podeEditar && !$temAssinatura ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-acao="regenerar" title="Refaz o documento com os dados atuais do item"><i class="ti ti-refresh"></i><span>Atualizar conteúdo</span></button>' : '')
            . ($this->can($id, UPDATE) ? ($cancelada
                ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-acao="reativar"><i class="ti ti-arrow-back-up"></i><span>Reativar</span></button>'
                : '<button type="button" class="btn btn-sm btn-ghost-danger" data-ordemdeservico-acao="cancelar" data-ordemdeservico-confirmar="Cancelar esta ordem de serviço?"><i class="ti ti-ban"></i><span>Cancelar OS</span></button>') : '')
            . '</div></div>';

        echo '<div class="ordemdeservico-layout">';
        echo '<div class="ordemdeservico-papel-area"><div class="ordemdeservico-papel" data-ordemdeservico-doc>' . PluginOrdemdeservicoDocumento::renderizar($this) . '</div></div>';

        // Lateral: assinaturas e link
        echo '<aside class="ordemdeservico-lateral">';
        echo '<div class="card ordemdeservico-card"><div class="card-header"><h5><i class="ti ti-signature"></i> Assinaturas</h5></div><div class="card-body">';
        foreach (PluginOrdemdeservicoAssinatura::PAPEIS as $papel => $rotulo) {
            $assinada = !empty($f['assinatura_' . $papel . '_data']);
            $nomePadrao = $papel === 'tecnico' ? ((string) $f['tecnico_nome'] ?: getUserName((int) Session::getLoginUserID())) : (string) $f['solicitante_nome'];
            echo '<div class="ordemdeservico-assinatura' . ($assinada ? ' ordemdeservico-assinatura-ok' : '') . '">'
                . '<div class="ordemdeservico-assinatura-topo"><span class="ordemdeservico-assinatura-papel">' . $e($rotulo) . '</span>'
                . ($assinada ? '<i class="ti ti-circle-check text-success"></i>' : '<span class="ordemdeservico-pequeno">pendente</span>') . '</div>';
            if ($assinada) {
                echo '<div class="ordemdeservico-assinatura-nome">' . $e($f['assinatura_' . $papel . '_nome']) . '</div>'
                    . '<div class="ordemdeservico-pequeno">' . $e(Html::convDateTime((string) $f['assinatura_' . $papel . '_data']))
                    . ($papel === 'solicitante' && $f['assinatura_solicitante_origem'] === 'link' ? ' · pelo link' : '') . '</div>';
                if ($podeEditar) {
                    echo '<button type="button" class="btn btn-sm btn-ghost-danger ordemdeservico-assinatura-remover" data-ordemdeservico-acao="remover_assinatura" data-papel="' . $e($papel) . '" data-ordemdeservico-confirmar="Remover esta assinatura?"><i class="ti ti-trash"></i><span>Remover</span></button>';
                }
            } elseif ($podeEditar) {
                echo '<button type="button" class="btn btn-sm btn-ghost-secondary w-100" data-ordemdeservico-assinar="' . $e($papel) . '" data-nome="' . $e($nomePadrao) . '" data-rotulo="' . $e($rotulo) . '"><i class="ti ti-writing-sign"></i><span>Assinar na tela</span></button>';
            }
            echo '</div>';
        }
        echo '</div></div>';

        if (PluginOrdemdeservicoAssinatura::linkAtivo() && !$cancelada && empty($f['assinatura_solicitante_data']) && $this->can($id, UPDATE)) {
            $link = PluginOrdemdeservicoAssinatura::link($this, false);
            echo '<div class="card ordemdeservico-card"><div class="card-header"><h5><i class="ti ti-link"></i> Assinatura pelo link</h5></div><div class="card-body" data-ordemdeservico-link-bloco>'
                . '<p class="ordemdeservico-ajuda"><i class="ti ti-info-circle"></i> O solicitante confere a OS e assina pelo celular ou computador, sem login.</p>'
                . ((int) $f['privados'] ? '<div class="ordemdeservico-alerta ordemdeservico-alerta-aviso"><i class="ti ti-lock"></i><span>Esta OS inclui acompanhamentos/tarefas privados: quem abrir o link verá esse conteúdo.</span></div>' : '')
                . '<div class="input-group input-group-sm"><input type="text" class="form-control" readonly data-ordemdeservico-link value="' . $e($link) . '" placeholder="Nenhum link gerado">'
                . '<button type="button" class="btn btn-ghost-secondary" data-ordemdeservico-copiar title="Copiar"><i class="ti ti-copy"></i></button></div>'
                . '<div class="ordemdeservico-pequeno mt-1" data-ordemdeservico-link-validade>' . ($link !== '' ? 'Válido até ' . $e(Html::convDateTime((string) $f['token_validade'])) : '') . '</div>'
                . '<button type="button" class="btn btn-sm btn-ghost-secondary mt-2" data-ordemdeservico-acao="link"><i class="ti ti-refresh"></i><span>' . ($link !== '' ? 'Gerar novo link' : 'Gerar link') . '</span></button>'
                . '</div></div>';
        }
        echo '</aside></div>';

        echo self::modais($this);
        echo '</div>';
    }

    /** Modais de assinatura e de e-mail (preenchidos pelo JS) */
    private static function modais(PluginOrdemdeservicoOrdem $os): string
    {
        $C = PluginOrdemdeservicoConfig::class;
        $e = [$C, 'e'];
        $f = $os->fields;
        $h = '<div class="modal fade" tabindex="-1" data-ordemdeservico-modal-assinatura><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
            . '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-writing-sign me-1"></i> <span data-ordemdeservico-assinatura-titulo>Assinatura</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>'
            . '<div class="modal-body">'
            . '<label class="form-label" for="ordemdeservico-assinatura-nome">Nome de quem assina</label>'
            . '<input type="text" class="form-control form-control-sm mb-2" id="ordemdeservico-assinatura-nome" maxlength="255" data-ordemdeservico-assinatura-nome>'
            . '<div class="ordemdeservico-canvas-caixa"><canvas width="400" height="150" data-ordemdeservico-canvas></canvas><span class="ordemdeservico-canvas-dica">Assine aqui</span></div>'
            . '<div class="ordemdeservico-pequeno mt-1"><i class="ti ti-info-circle"></i> Use o mouse, a caneta ou o dedo.</div>'
            . '<div class="ordemdeservico-erro" data-ordemdeservico-assinatura-erro hidden></div>'
            . '</div><div class="modal-footer">'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-canvas-limpar><i class="ti ti-eraser"></i><span>Limpar</span></button>'
            . '<button type="button" class="btn btn-sm ordemdeservico-btn-principal" data-ordemdeservico-assinatura-salvar><i class="ti ti-check"></i><span>Confirmar assinatura</span></button>'
            . '</div></div></div></div>';

        if ($f['status'] === 'cancelada') {
            return $h;
        }
        $sugeridos = PluginOrdemdeservicoEnvio::destinatariosSugeridos($os);
        $comLink = PluginOrdemdeservicoAssinatura::linkAtivo() && empty($f['assinatura_solicitante_data']) && $os->can((int) $os->getID(), UPDATE);
        $semRemetente = PluginOrdemdeservicoEnvio::remetente() === null;
        $h .= '<div class="modal fade" tabindex="-1" data-ordemdeservico-modal-email><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">'
            . '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-mail me-1"></i> Enviar ordem de serviço ' . $e($f['numero']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>'
            . '<div class="modal-body">';
        if ($semRemetente) {
            $h .= '<div class="ordemdeservico-alerta ordemdeservico-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O GLPI não tem remetente de e-mail configurado: o envio vai falhar até que um seja informado.</span></div>';
        }
        $h .= '<div class="ordemdeservico-campo"><label for="ordemdeservico-email-para">Para</label>';
        if ($sugeridos) {
            $h .= '<div class="ordemdeservico-sugestoes">';
            foreach ($sugeridos as $s) {
                $h .= '<button type="button" class="ordemdeservico-chip" data-ordemdeservico-sugestao="' . $e($s['email']) . '" title="' . $e($s['email']) . '"><i class="ti ti-plus"></i>' . $e($s['nome']) . '</button>';
            }
            $h .= '</div>';
        }
        $h .= '<input type="text" class="form-control form-control-sm" id="ordemdeservico-email-para" data-ordemdeservico-email-para value="' . $e($sugeridos[0]['email'] ?? '') . '" placeholder="E-mails separados por vírgula"></div>'
            . '<div class="ordemdeservico-campo"><label for="ordemdeservico-email-cc">Cópia</label><input type="text" class="form-control form-control-sm" id="ordemdeservico-email-cc" data-ordemdeservico-email-cc placeholder="Opcional"></div>'
            . '<div class="ordemdeservico-campo"><label for="ordemdeservico-email-assunto">Assunto</label><input type="text" class="form-control form-control-sm" id="ordemdeservico-email-assunto" maxlength="255" data-ordemdeservico-email-assunto></div>'
            . '<div class="ordemdeservico-campo"><label for="ordemdeservico-email-mensagem">Mensagem</label><textarea id="ordemdeservico-email-mensagem" class="form-control" rows="8" data-ordemdeservico-email-mensagem></textarea></div>'
            . '<div class="ordemdeservico-opcoes">'
            . '<label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" data-ordemdeservico-email-pdf checked> Anexar o PDF <small>(sem PDF, o documento vai no corpo do e-mail)</small></label>'
            . ($comLink ? '<label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" data-ordemdeservico-email-link checked> Incluir o link para o solicitante assinar</label>' : '')
            . '</div><div class="ordemdeservico-erro" data-ordemdeservico-email-erro hidden></div>'
            . '</div><div class="modal-footer">'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button>'
            . '<button type="button" class="btn btn-sm ordemdeservico-btn-principal" data-ordemdeservico-email-enviar><i class="ti ti-send"></i><span>Enviar</span></button>'
            . '</div></div></div></div>';
        return $h;
    }
}
