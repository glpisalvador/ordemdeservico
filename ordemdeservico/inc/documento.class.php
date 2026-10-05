<?php

/**
 * Plugin Ordem de Serviço - montagem do documento.
 * montar(): congela as seções escolhidas do chamado/problema/mudança em HTML (imagens viram base64 para
 * funcionar no PDF, no e-mail e na página pública). renderizar(): documento completo da OS (cabeçalho,
 * seções congeladas, observações, termo, assinaturas e rodapé) com estilos em linha.
 */
class PluginOrdemdeservicoDocumento
{
    /** Tamanho máximo de cada imagem embutida */
    public const LIMITE_IMAGEM = 3 * 1024 * 1024;
    public const MAX_IMAGENS = 12;

    /** Campos de texto extras de problemas e mudanças, exibidos na seção Descrição */
    private const TEXTOS_EXTRAS = [
        'Problem' => ['impactcontent' => 'Impactos', 'causecontent' => 'Causas', 'symptomcontent' => 'Sintomas'],
        'Change'  => [
            'impactcontent'      => 'Análise de impacto',
            'controlistcontent'  => 'Lista de controle',
            'rolloutplancontent' => 'Plano de implantação',
            'backoutplancontent' => 'Plano de retorno',
            'checklistcontent'   => 'Checklist',
        ],
    ];

    private const COR_TITULO = '#2f3f64';
    private const ESTILO_SECAO = 'font-size:13px;font-weight:600;color:#2f3f64;border-bottom:1px solid #dee2e6;padding:0 0 4px;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;';
    private const ESTILO_ROTULO = 'padding:5px 8px;width:24%;vertical-align:top;color:#6c757d;font-weight:600;font-size:12px;border-bottom:1px solid #f0f0f0;';
    private const ESTILO_VALOR = 'padding:5px 8px;width:26%;vertical-align:top;color:#333;font-size:12px;border-bottom:1px solid #f0f0f0;';

    private static function e($t): string
    {
        return PluginOrdemdeservicoConfig::e($t);
    }

    /** Carrega o item de origem (null se não existe ou não é de um tipo suportado) */
    public static function item(string $itemtype, int $id): ?CommonITILObject
    {
        if (!isset(PluginOrdemdeservicoConfig::ITENS[$itemtype]) || $id <= 0) {
            return null;
        }
        $item = new $itemtype();
        return $item->getFromDB($id) ? $item : null;
    }

    public static function rotuloItem(string $itemtype): string
    {
        return PluginOrdemdeservicoConfig::ITENS[$itemtype] ?? $itemtype;
    }

    // =====================================================================
    // Dados do item
    // =====================================================================

    /** Pessoas de um papel (1 requerente, 2 atribuído, 3 observador): [['nome', 'email', 'users_id']] */
    public static function pessoas(CommonITILObject $item, int $papel): array
    {
        $lista = [];
        foreach ($item->getUsers($papel) as $u) {
            $uid = (int) ($u['users_id'] ?? 0);
            if ($uid > 0) {
                $usuario = new User();
                $email = $usuario->getFromDB($uid) ? (string) $usuario->getDefaultEmail() : '';
                $lista[] = ['nome' => (string) getUserName($uid), 'email' => $email ?: (string) ($u['alternative_email'] ?? ''), 'users_id' => $uid];
            } elseif (!empty($u['alternative_email'])) {
                $lista[] = ['nome' => (string) $u['alternative_email'], 'email' => (string) $u['alternative_email'], 'users_id' => 0];
            }
        }
        return $lista;
    }

    public static function grupos(CommonITILObject $item, int $papel): array
    {
        $lista = [];
        foreach ($item->getGroups($papel) as $g) {
            if ((int) ($g['groups_id'] ?? 0) > 0) {
                $lista[] = (string) Dropdown::getDropdownName('glpi_groups', (int) $g['groups_id']);
            }
        }
        return $lista;
    }

    /** Valores das variáveis comuns dos modelos ({item}, {item_id}, {item_titulo}, {entidade}, {empresa}) */
    public static function variaveisItem(CommonITILObject $item): array
    {
        return [
            'item'        => mb_strtolower(self::rotuloItem($item->getType())),
            'item_id'     => (string) $item->getID(),
            'item_titulo' => (string) $item->fields['name'],
            'entidade'    => (string) Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']),
            'empresa'     => (string) PluginOrdemdeservicoConfig::getConfig('empresa'),
        ];
    }

    // =====================================================================
    // Imagens: document.send.php?docid=N vira data URI
    // =====================================================================

    private static function dataUriDocumento(int $docid): string
    {
        static $cache = [];
        if (isset($cache[$docid])) {
            return $cache[$docid];
        }
        $cache[$docid] = '';
        $doc = new Document();
        if (!$doc->getFromDB($docid) || (int) $doc->fields['is_deleted'] === 1) {
            return '';
        }
        $caminho = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if ((string) $doc->fields['filepath'] === '' || !is_file($caminho) || filesize($caminho) > self::LIMITE_IMAGEM) {
            return '';
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'], true)) {
            return '';
        }
        return $cache[$docid] = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($caminho));
    }

    /** HTML seguro (sanitizador do GLPI) com as imagens internas embutidas */
    public static function html(?string $conteudo): string
    {
        $conteudo = (string) $conteudo;
        if (PluginOrdemdeservicoConfig::vazio($conteudo)) {
            return '';
        }
        $seguro = \Glpi\RichText\RichText::getSafeHtml($conteudo);
        $seguro = (string) preg_replace_callback(
            '/(<img\b[^>]*?\bsrc=")([^"]*document\.send\.php\?[^"]*?docid(?:=|&#61;)(\d+)[^"]*)(")/i',
            function (array $m) {
                $uri = self::dataUriDocumento((int) $m[3]);
                return $uri !== '' ? $m[1] . $uri . $m[4] : $m[1] . $m[2] . $m[4];
            },
            $seguro
        );
        // Imagens nunca passam da largura da página
        return (string) preg_replace('/<img\b(?![^>]*data-os-img)/i', '<img data-os-img="1" style="max-width:100%;height:auto;" ', $seguro);
    }

    // =====================================================================
    // Seções congeladas
    // =====================================================================

    /**
     * HTML das seções escolhidas. $privados inclui acompanhamentos/tarefas privados (se a pessoa pode vê-los).
     */
    public static function montar(CommonITILObject $item, array $secoes, bool $privados): string
    {
        $secoes = array_values(array_intersect(array_keys(PluginOrdemdeservicoConfig::SECOES), $secoes));
        $h = '';
        foreach ($secoes as $s) {
            $parte = match ($s) {
                'informacoes'     => self::secaoInformacoes($item),
                'atribuicao'      => self::secaoAtores($item),
                'descricao'       => self::secaoDescricao($item),
                'acompanhamentos' => self::secaoAcompanhamentos($item, $privados),
                'tarefas'         => self::secaoTarefas($item, $privados),
                'validacoes'      => self::secaoValidacoes($item),
                'solucao'         => self::secaoSolucao($item),
                'imagens'         => self::secaoImagens($item),
                default           => '',
            };
            if ($parte !== '') {
                $h .= '<div data-os-secao="' . self::e($s) . '">' . $parte . '</div>';
            }
        }
        return $h;
    }

    private static function titulo(string $texto, int $n = 0): string
    {
        return '<div style="' . self::ESTILO_SECAO . '">' . self::e($texto) . ($n > 0 ? ' <span style="color:#999;font-weight:400;">(' . $n . ')</span>' : '') . '</div>';
    }

    /** Tabela de pares rótulo/valor em 4 colunas (valores já escapados) */
    private static function grade(array $pares): string
    {
        $pares = array_filter($pares, fn($v) => $v !== '' && $v !== null);
        if (!$pares) {
            return '';
        }
        $h = '<table style="width:100%;border-collapse:collapse;">';
        $itens = array_chunk($pares, 2, true);
        foreach ($itens as $linha) {
            $h .= '<tr>';
            foreach ($linha as $rotulo => $valor) {
                $h .= '<td style="' . self::ESTILO_ROTULO . '">' . self::e($rotulo) . '</td><td style="' . self::ESTILO_VALOR . '">' . $valor . '</td>';
            }
            if (count($linha) < 2) {
                $h .= '<td style="' . self::ESTILO_ROTULO . '"></td><td style="' . self::ESTILO_VALOR . '"></td>';
            }
            $h .= '</tr>';
        }
        return $h . '</table>';
    }

    private static function data(?string $data): string
    {
        return $data ? self::e(Html::convDateTime($data)) : '';
    }

    private static function secaoInformacoes(CommonITILObject $item): string
    {
        $f = $item->fields;
        $tipo = $item->getType();
        $pares = [
            self::rotuloItem($tipo) => '#' . (int) $item->getID(),
            'Título'                => self::e($f['name']),
            'Entidade'              => self::e(Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id'])),
            'Status'                => self::e($tipo::getStatus((int) $f['status'])),
        ];
        if ($tipo === 'Ticket') {
            $pares['Tipo'] = self::e(Ticket::getTicketTypeName((int) $f['type']));
        }
        if ((int) ($f['itilcategories_id'] ?? 0) > 0) {
            $pares['Categoria'] = self::e(Dropdown::getDropdownName('glpi_itilcategories', (int) $f['itilcategories_id']));
        }
        $pares['Urgência'] = self::e(CommonITILObject::getUrgencyName((int) $f['urgency']));
        $pares['Prioridade'] = self::e(CommonITILObject::getPriorityName((int) $f['priority']));
        if ((int) ($f['locations_id'] ?? 0) > 0) {
            $pares['Localização'] = self::e(Dropdown::getDropdownName('glpi_locations', (int) $f['locations_id']));
        }
        $pares['Abertura'] = self::data($f['date'] ?? null);
        $pares['Prazo de solução'] = self::data($f['time_to_resolve'] ?? null);
        $pares['Solução'] = self::data($f['solvedate'] ?? null);
        $pares['Fechamento'] = self::data($f['closedate'] ?? null);
        if ((int) ($f['actiontime'] ?? 0) > 0) {
            $pares['Tempo total'] = self::e(Html::timestampToString((int) $f['actiontime'], false));
        }
        return self::titulo(PluginOrdemdeservicoConfig::SECOES['informacoes']) . self::grade($pares);
    }

    private static function secaoAtores(CommonITILObject $item): string
    {
        $juntar = function (array $pessoas, array $grupos): string {
            $nomes = array_map(fn($p) => self::e($p['nome']), $pessoas);
            foreach ($grupos as $g) {
                $nomes[] = self::e($g) . ' <span style="color:#999;">(grupo)</span>';
            }
            return implode('<br>', $nomes);
        };
        $pares = [
            'Requerentes'  => $juntar(self::pessoas($item, CommonITILActor::REQUESTER), self::grupos($item, CommonITILActor::REQUESTER)),
            'Observadores' => $juntar(self::pessoas($item, CommonITILActor::OBSERVER), self::grupos($item, CommonITILActor::OBSERVER)),
            'Atribuído a'  => $juntar(self::pessoas($item, CommonITILActor::ASSIGN), self::grupos($item, CommonITILActor::ASSIGN)),
        ];
        $grade = self::grade($pares);
        return $grade === '' ? '' : self::titulo(PluginOrdemdeservicoConfig::SECOES['atribuicao']) . $grade;
    }

    private static function blocoTexto(string $html): string
    {
        return '<div style="font-size:12px;color:#333;line-height:1.5;padding:2px 8px;">' . $html . '</div>';
    }

    private static function secaoDescricao(CommonITILObject $item): string
    {
        $h = '';
        $principal = self::html($item->fields['content'] ?? '');
        if ($principal !== '') {
            $h .= self::blocoTexto($principal);
        }
        foreach (self::TEXTOS_EXTRAS[$item->getType()] ?? [] as $campo => $rotulo) {
            $texto = self::html($item->fields[$campo] ?? '');
            if ($texto !== '') {
                $h .= '<div style="font-size:12px;font-weight:600;color:#495057;margin:10px 8px 2px;">' . self::e($rotulo) . '</div>' . self::blocoTexto($texto);
            }
        }
        return $h === '' ? '' : self::titulo(PluginOrdemdeservicoConfig::SECOES['descricao']) . $h;
    }

    /** Um registro da linha do tempo: cabeçalho (autor, data, extras) + conteúdo */
    private static function registro(string $cabecalho, string $conteudo, bool $privado = false): string
    {
        return '<div style="border:1px solid #e9ecef;border-radius:4px;margin:0 0 8px;page-break-inside:avoid;">'
            . '<div style="background:#f8f9fa;padding:4px 8px;font-size:11px;color:#6c757d;border-bottom:1px solid #e9ecef;">' . $cabecalho
            . ($privado ? ' <span style="color:#664d03;background:rgba(255,193,7,0.15);border-radius:8px;padding:0 6px;">privado</span>' : '') . '</div>'
            . '<div style="padding:6px 8px;font-size:12px;color:#333;line-height:1.5;">' . ($conteudo !== '' ? $conteudo : '<span style="color:#999;">(sem texto)</span>') . '</div></div>';
    }

    private static function secaoAcompanhamentos(CommonITILObject $item, bool $privados): string
    {
        global $DB;
        $where = ['itemtype' => $item->getType(), 'items_id' => (int) $item->getID()];
        if (!$privados || !Session::haveRight('followup', ITILFollowup::SEEPRIVATE)) {
            $where['is_private'] = 0;
        }
        $h = '';
        $n = 0;
        foreach ($DB->request(['FROM' => ITILFollowup::getTable(), 'WHERE' => $where, 'ORDER' => ['date ASC', 'id ASC']]) as $r) {
            $n++;
            $cab = '<strong style="color:#495057;">' . self::e(getUserName((int) $r['users_id'])) . '</strong> · ' . self::data($r['date']);
            $h .= self::registro($cab, self::html($r['content']), (int) $r['is_private'] === 1);
        }
        return $n === 0 ? '' : self::titulo(PluginOrdemdeservicoConfig::SECOES['acompanhamentos'], $n) . $h;
    }

    private static function secaoTarefas(CommonITILObject $item, bool $privados): string
    {
        global $DB;
        $classe = $item->getType() . 'Task';
        if (!class_exists($classe)) {
            return '';
        }
        $where = [$item->getForeignKeyField() => (int) $item->getID()];
        if (!$privados || !Session::haveRight('task', CommonITILTask::SEEPRIVATE)) {
            $where['is_private'] = 0;
        }
        $estados = [Planning::INFO => 'Informação', Planning::TODO => 'A fazer', Planning::DONE => 'Concluída'];
        $h = '';
        $n = 0;
        $total = 0;
        foreach ($DB->request(['FROM' => $classe::getTable(), 'WHERE' => $where, 'ORDER' => ['date ASC', 'id ASC']]) as $r) {
            $n++;
            $total += (int) $r['actiontime'];
            $extras = [];
            if ((int) $r['users_id_tech'] > 0) {
                $extras[] = 'técnico ' . self::e(getUserName((int) $r['users_id_tech']));
            }
            if ((int) $r['taskcategories_id'] > 0) {
                $extras[] = self::e(Dropdown::getDropdownName('glpi_taskcategories', (int) $r['taskcategories_id']));
            }
            if ((int) $r['actiontime'] > 0) {
                $extras[] = 'duração ' . self::e(Html::timestampToString((int) $r['actiontime'], false));
            }
            if (!empty($r['begin'])) {
                $extras[] = 'planejada ' . self::data($r['begin']) . (!empty($r['end']) ? ' a ' . self::data($r['end']) : '');
            }
            $extras[] = self::e($estados[(int) $r['state']] ?? '');
            $cab = '<strong style="color:#495057;">' . self::e(getUserName((int) $r['users_id'])) . '</strong> · ' . self::data($r['date'])
                . ' · ' . implode(' · ', array_filter($extras));
            $h .= self::registro($cab, self::html($r['content']), (int) $r['is_private'] === 1);
        }
        if ($n === 0) {
            return '';
        }
        $resumo = $total > 0 ? '<div style="font-size:11px;color:#6c757d;text-align:right;margin:-2px 0 6px;">Tempo das tarefas: <strong>' . self::e(Html::timestampToString($total, false)) . '</strong></div>' : '';
        return self::titulo(PluginOrdemdeservicoConfig::SECOES['tarefas'], $n) . $h . $resumo;
    }

    private static function secaoValidacoes(CommonITILObject $item): string
    {
        global $DB;
        $classe = $item->getType() . 'Validation';
        if (!class_exists($classe)) {
            return '';
        }
        $h = '';
        $n = 0;
        foreach ($DB->request(['FROM' => $classe::getTable(), 'WHERE' => [$item->getForeignKeyField() => (int) $item->getID()], 'ORDER' => ['submission_date ASC', 'id ASC']]) as $r) {
            $n++;
            $alvo = '';
            if (($r['itemtype_target'] ?? '') === 'User') {
                $alvo = (string) getUserName((int) $r['items_id_target']);
            } elseif (($r['itemtype_target'] ?? '') === 'Group') {
                $alvo = Dropdown::getDropdownName('glpi_groups', (int) $r['items_id_target']) . ' (grupo)';
            }
            $cab = 'Solicitada por <strong style="color:#495057;">' . self::e(getUserName((int) $r['users_id'])) . '</strong> em ' . self::data($r['submission_date'])
                . ($alvo !== '' ? ' para ' . self::e($alvo) : '')
                . ' · <strong>' . self::e(CommonITILValidation::getStatus((int) $r['status'])) . '</strong>'
                . (!empty($r['validation_date']) ? ' em ' . self::data($r['validation_date']) . ((int) $r['users_id_validate'] > 0 ? ' por ' . self::e(getUserName((int) $r['users_id_validate'])) : '') : '');
            $texto = self::html($r['comment_submission']);
            $resposta = self::html($r['comment_validation']);
            if ($resposta !== '') {
                $texto .= '<div style="margin-top:6px;padding-top:6px;border-top:1px dashed #e9ecef;"><span style="color:#6c757d;">Resposta:</span> ' . $resposta . '</div>';
            }
            $h .= self::registro($cab, $texto);
        }
        return $n === 0 ? '' : self::titulo(PluginOrdemdeservicoConfig::SECOES['validacoes'], $n) . $h;
    }

    private static function secaoSolucao(CommonITILObject $item): string
    {
        global $DB;
        $h = '';
        $n = 0;
        foreach ($DB->request(['FROM' => ITILSolution::getTable(), 'WHERE' => ['itemtype' => $item->getType(), 'items_id' => (int) $item->getID()], 'ORDER' => ['date_creation ASC', 'id ASC']]) as $r) {
            $n++;
            $extras = [];
            if ((int) $r['solutiontypes_id'] > 0) {
                $extras[] = self::e(Dropdown::getDropdownName('glpi_solutiontypes', (int) $r['solutiontypes_id']));
            }
            $situacao = match ((int) $r['status']) {
                CommonITILValidation::ACCEPTED => 'aprovada',
                CommonITILValidation::REFUSED  => 'recusada',
                CommonITILValidation::WAITING  => 'aguardando aprovação',
                default                        => '',
            };
            if ($situacao !== '') {
                $extras[] = '<strong>' . $situacao . '</strong>';
            }
            $autor = (int) $r['users_id'] > 0 ? (string) getUserName((int) $r['users_id']) : (string) $r['user_name'];
            $cab = '<strong style="color:#495057;">' . self::e($autor) . '</strong> · ' . self::data($r['date_creation']) . ($extras ? ' · ' . implode(' · ', $extras) : '');
            $h .= self::registro($cab, self::html($r['content']));
        }
        return $n === 0 ? '' : self::titulo(PluginOrdemdeservicoConfig::SECOES['solucao'], $n) . $h;
    }

    private static function secaoImagens(CommonITILObject $item): string
    {
        global $DB;
        $celulas = [];
        foreach ($DB->request([
            'SELECT'     => ['d.id', 'd.name', 'd.filename', 'd.mime'],
            'FROM'       => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE'      => ['di.itemtype' => $item->getType(), 'di.items_id' => (int) $item->getID(), 'd.is_deleted' => 0, 'd.mime' => ['LIKE', 'image/%']],
            'ORDER'      => 'd.id ASC',
            'LIMIT'      => self::MAX_IMAGENS,
        ]) as $d) {
            $uri = self::dataUriDocumento((int) $d['id']);
            if ($uri !== '') {
                $celulas[] = '<td style="width:33%;padding:4px;vertical-align:top;text-align:center;">'
                    . '<img src="' . $uri . '" alt="" style="max-width:100%;max-height:220px;border:1px solid #e9ecef;border-radius:3px;">'
                    . '<div style="font-size:10px;color:#999;margin-top:2px;">' . self::e($d['name'] ?: $d['filename']) . '</div></td>';
            }
        }
        if (!$celulas) {
            return '';
        }
        $h = '<table style="width:100%;border-collapse:collapse;">';
        foreach (array_chunk($celulas, 3) as $linha) {
            $h .= '<tr>' . implode('', $linha) . str_repeat('<td style="width:33%;"></td>', 3 - count($linha)) . '</tr>';
        }
        return self::titulo(PluginOrdemdeservicoConfig::SECOES['imagens'], count($celulas)) . $h . '</table>';
    }

    // =====================================================================
    // Documento completo da OS
    // =====================================================================

    public static function renderizar(PluginOrdemdeservicoOrdem $os): string
    {
        $C = PluginOrdemdeservicoConfig::class;
        $f = $os->fields;
        $logo = $C::logoDataUri();
        $empresa = trim((string) $C::getConfig('empresa'));
        // Linhas curtas: parágrafos sem margem (vale também nos clientes de e-mail)
        $compacto = fn(string $html) => str_replace('<p>', '<p style="margin:0;">', $html);
        $cabecalho = $compacto(self::html((string) $C::getConfig('cabecalho')));
        $rodape = $compacto(self::html((string) $C::getConfig('rodape')));
        $cancelada = $f['status'] === 'cancelada';

        $h = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#333;line-height:1.45;max-width:780px;margin:0 auto;background:#fff;padding:4px;position:relative;">';

        // Cabeçalho: logotipo e empresa à esquerda, número e data à direita
        $h .= '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #dee2e6;margin-bottom:12px;"><tr>';
        if ($logo !== '') {
            $h .= '<td style="width:1%;padding:0 14px 10px 0;vertical-align:middle;"><img src="' . $logo . '" alt="" style="max-height:56px;max-width:190px;"></td>';
        }
        $h .= '<td style="padding:0 0 10px;vertical-align:middle;">'
            . ($empresa !== '' ? '<div style="font-size:15px;font-weight:600;color:' . self::COR_TITULO . ';">' . self::e($empresa) . '</div>' : '')
            . ($cabecalho !== '' ? '<div style="font-size:11px;color:#6c757d;line-height:1.35;" data-os-compacto>' . $cabecalho . '</div>' : '')
            . '</td><td style="padding:0 0 10px;vertical-align:middle;text-align:right;white-space:nowrap;">'
            . '<div style="font-size:10px;color:#6c757d;text-transform:uppercase;letter-spacing:.6px;">Ordem de serviço</div>'
            . '<div style="font-size:18px;font-weight:700;color:' . self::COR_TITULO . ';">' . self::e($f['numero']) . '</div>'
            . '<div style="font-size:11px;color:#6c757d;">Emitida em ' . self::e(Html::convDateTime((string) $f['date_creation'])) . '</div>'
            . '</td></tr></table>';

        $h .= '<div style="font-size:16px;font-weight:600;color:#333;margin:0 0 4px;">' . self::e($f['name']) . '</div>';
        $origem = self::rotuloItem((string) $f['itemtype']) . ' #' . (int) $f['items_id'];
        $h .= '<div style="font-size:11px;color:#6c757d;margin-bottom:6px;">' . self::e($origem)
            . ' · ' . self::e(Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']))
            . ' · gerada por ' . self::e(getUserName((int) $f['users_id'])) . '</div>';
        if ($cancelada) {
            $h .= '<div style="border:1px solid rgba(220,53,69,0.35);background:rgba(220,53,69,0.06);color:#842029;border-radius:4px;padding:6px 10px;font-weight:600;margin:6px 0;">Ordem de serviço cancelada</div>';
        }

        $h .= (string) $f['conteudo'];

        $obs = self::html((string) $f['observacoes']);
        if ($obs !== '') {
            $h .= '<div style="' . self::ESTILO_SECAO . '">Observações</div>' . self::blocoTexto($obs);
        }

        $h .= self::blocoAssinaturas($os);

        if ($rodape !== '') {
            $h .= '<div style="border-top:1px solid #dee2e6;margin-top:18px;padding-top:6px;font-size:10px;color:#999;text-align:center;line-height:1.35;" data-os-compacto>' . $rodape . '</div>';
        }
        return $h . '</div>';
    }

    private static function blocoAssinaturas(PluginOrdemdeservicoOrdem $os): string
    {
        $f = $os->fields;
        $termo = self::html((string) PluginOrdemdeservicoConfig::getConfig('termo'));
        $h = '<div style="' . self::ESTILO_SECAO . '">Assinaturas</div>';
        if ($termo !== '') {
            $h .= '<div style="font-size:11px;color:#495057;margin:0 8px 10px;">' . $termo . '</div>';
        }
        $h .= '<table style="width:100%;border-collapse:collapse;page-break-inside:avoid;"><tr>';
        foreach (['tecnico' => 'Técnico responsável', 'solicitante' => 'Solicitante'] as $papel => $rotulo) {
            $imagem = PluginOrdemdeservicoAssinatura::dataUri($os, $papel);
            $nome = trim((string) $f['assinatura_' . $papel . '_nome']) ?: trim((string) $f[$papel . '_nome']);
            $data = (string) ($f['assinatura_' . $papel . '_data'] ?? '');
            $h .= '<td style="width:50%;padding:0 12px;vertical-align:top;text-align:center;">'
                . '<div style="height:90px;display:flex;align-items:flex-end;justify-content:center;">'
                . ($imagem !== '' ? '<img src="' . $imagem . '" alt="Assinatura" style="max-height:88px;max-width:100%;">' : '')
                . '</div>'
                . '<div style="border-top:1px solid #495057;margin-top:2px;padding-top:4px;font-size:12px;font-weight:600;color:#333;">' . self::e($nome !== '' ? $nome : ' ') . '</div>'
                . '<div style="font-size:10px;color:#6c757d;">' . self::e($rotulo)
                . ($data !== '' ? ' · assinado em ' . self::e(Html::convDateTime($data)) . ($papel === 'solicitante' && $f['assinatura_solicitante_origem'] === 'link' ? ' pelo link' : '') : '')
                . '</div></td>';
        }
        return $h . '</tr></table>';
    }
}
