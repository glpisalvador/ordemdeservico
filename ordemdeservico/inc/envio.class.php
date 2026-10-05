<?php

/**
 * Plugin Ordem de Serviço - envio da OS por e-mail (GLPIMailer do GLPI 11/12), registro dos envios
 * e anexo do PDF ao chamado/problema/mudança como documento nativo.
 */
class PluginOrdemdeservicoEnvio
{
    public const TABELA = 'glpi_plugin_ordemdeservico_envios';
    /** PDF gerado no navegador (base64 no POST; o post_max_size do PHP é o limite real) */
    public const LIMITE_PDF = 6 * 1024 * 1024;

    public static function contar(int $os_id): int
    {
        return countElementsInTable(self::TABELA, ['plugin_ordemdeservico_documentos_id' => $os_id]);
    }

    /** [email, nome] do remetente: o da configuração do plugin ou o das notificações do GLPI */
    public static function remetente(): ?array
    {
        global $CFG_GLPI;
        $C = PluginOrdemdeservicoConfig::class;
        foreach ([$C::getConfig('remetente_email'), $CFG_GLPI['from_email'] ?? '', $CFG_GLPI['admin_email'] ?? ''] as $email) {
            $email = trim((string) $email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $nome = trim((string) $C::getConfig('remetente_nome')) ?: trim((string) ($CFG_GLPI['from_email_name'] ?? '')) ?: trim((string) ($CFG_GLPI['admin_email_name'] ?? ''));
                return [$email, $nome];
            }
        }
        return null;
    }

    /** Variáveis dos modelos de e-mail para uma OS */
    public static function variaveis(PluginOrdemdeservicoOrdem $os, string $link = ''): array
    {
        $item = PluginOrdemdeservicoDocumento::item((string) $os->fields['itemtype'], (int) $os->fields['items_id']);
        $base = $item ? PluginOrdemdeservicoDocumento::variaveisItem($item) : [
            'item'        => mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem((string) $os->fields['itemtype'])),
            'item_id'     => (string) $os->fields['items_id'],
            'item_titulo' => '',
            'entidade'    => (string) Dropdown::getDropdownName('glpi_entities', (int) $os->fields['entities_id']),
            'empresa'     => (string) PluginOrdemdeservicoConfig::getConfig('empresa'),
        ];
        return $base + [
            'numero'  => (string) $os->fields['numero'],
            'titulo'  => (string) $os->fields['name'],
            'usuario' => (string) getUserName((int) Session::getLoginUserID()),
            'link'    => $link,
        ];
    }

    /** Assunto e mensagem sugeridos (modelos da configuração) */
    public static function sugestao(PluginOrdemdeservicoOrdem $os, bool $comLink): array
    {
        $C = PluginOrdemdeservicoConfig::class;
        $link = $comLink ? PluginOrdemdeservicoAssinatura::link($os) : '';
        $v = self::variaveis($os, $link !== '' ? '<a href="' . $C::e($link) . '">Clique aqui para conferir e assinar a ordem de serviço</a>' : '');
        $mensagem = $C::aplicarVariaveis((string) $C::getConfig('email_mensagem'), $v, true);
        // Parágrafo que só tinha o link fica vazio quando não há link
        $mensagem = (string) preg_replace('#<p>\s*</p>#', '', $mensagem);
        $v['link'] = $link;
        return [
            'assunto'  => trim((string) preg_replace('/[\r\n]+/', ' ', $C::aplicarVariaveis((string) $C::getConfig('email_assunto'), $v))),
            'mensagem' => $mensagem,
        ];
    }

    /** Destinatários sugeridos: e-mail do solicitante da OS e requerentes do item */
    public static function destinatariosSugeridos(PluginOrdemdeservicoOrdem $os): array
    {
        $lista = [];
        if (filter_var((string) $os->fields['solicitante_email'], FILTER_VALIDATE_EMAIL)) {
            $lista[mb_strtolower((string) $os->fields['solicitante_email'])] = ['email' => (string) $os->fields['solicitante_email'], 'nome' => (string) $os->fields['solicitante_nome']];
        }
        $item = PluginOrdemdeservicoDocumento::item((string) $os->fields['itemtype'], (int) $os->fields['items_id']);
        if ($item) {
            foreach (PluginOrdemdeservicoDocumento::pessoas($item, CommonITILActor::REQUESTER) as $p) {
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL) && !isset($lista[mb_strtolower($p['email'])])) {
                    $lista[mb_strtolower($p['email'])] = ['email' => $p['email'], 'nome' => $p['nome']];
                }
            }
        }
        return array_values($lista);
    }

    /** Lista de e-mails de um texto (vírgula, ponto e vírgula, espaço ou linha) */
    public static function emails($valor): array
    {
        $partes = is_array($valor) ? $valor : preg_split('/[\s,;]+/', (string) $valor);
        $lista = [];
        foreach ($partes as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $lista[mb_strtolower($p)] = $p;
            }
        }
        return array_values($lista);
    }

    /** PDF vindo do navegador (base64, com ou sem prefixo data:) ou '' */
    public static function decodificarPdf(string $base64): string
    {
        $base64 = (string) preg_replace('#^data:application/pdf;[^,]*base64,#', '', trim($base64));
        if ($base64 === '') {
            return '';
        }
        $binario = base64_decode($base64, true);
        if ($binario === false || strlen($binario) > self::LIMITE_PDF || strncmp($binario, '%PDF', 4) !== 0) {
            return '';
        }
        return $binario;
    }

    /**
     * Envia a OS. Retorna ['ok' => bool, 'mensagem' => string].
     */
    public static function enviar(PluginOrdemdeservicoOrdem $os, array $para, array $cc, string $assunto, string $mensagem, string $pdf, bool $comLink): array
    {
        $invalidos = array_filter(array_merge($para, $cc), fn($x) => !filter_var($x, FILTER_VALIDATE_EMAIL));
        if ($invalidos) {
            return ['ok' => false, 'mensagem' => 'E-mail inválido: ' . implode(', ', $invalidos)];
        }
        if (!$para) {
            return ['ok' => false, 'mensagem' => 'Informe pelo menos um destinatário.'];
        }
        $assunto = trim((string) preg_replace('/[\r\n]+/', ' ', strip_tags($assunto)));
        if ($assunto === '') {
            return ['ok' => false, 'mensagem' => 'Informe o assunto.'];
        }
        $remetente = self::remetente();
        if ($remetente === null) {
            $erro = 'Não há remetente de e-mail: informe um na configuração do plugin ou em Configurar > Notificações do GLPI.';
            self::registrar($os, array_merge($para, $cc), $assunto, $pdf !== '', $comLink, false, $erro);
            return ['ok' => false, 'mensagem' => $erro];
        }

        $link = $comLink ? PluginOrdemdeservicoAssinatura::link($os) : '';
        $corpo = self::corpo($os, $mensagem, $link);
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333;line-height:1.5;">' . $corpo . '</div>';
        if ($pdf === '') {
            $html .= '<hr style="border:0;border-top:1px solid #dee2e6;margin:16px 0;">' . PluginOrdemdeservicoDocumento::renderizar($os);
        }
        $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>', '</li>'], "\n", $corpo)), ENT_QUOTES, 'UTF-8'));
        if ($link !== '') {
            $texto .= "\n\n" . $link;
        }

        try {
            $mail = new GLPIMailer();
            $msg = $mail->getEmail();
            $msg->from(new \Symfony\Component\Mime\Address($remetente[0], $remetente[1]));
            foreach ($para as $email) {
                $msg->addTo(new \Symfony\Component\Mime\Address($email));
            }
            foreach ($cc as $email) {
                $msg->addCc(new \Symfony\Component\Mime\Address($email));
            }
            $msg->subject($assunto);
            $msg->html($html, 'utf-8');
            $msg->text($texto, 'utf-8');
            $msg->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $msg->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            if ($pdf !== '') {
                $msg->attach($pdf, self::nomeArquivo($os), 'application/pdf');
            }
            $ok = (bool) $mail->send();
            $erro = '';
            if (!$ok) {
                $erro = (method_exists($mail, 'getError') ? (string) $mail->getError() : '') ?: 'o servidor de e-mail recusou o envio';
            }
        } catch (\Throwable $e) {
            $ok = false;
            $erro = $e->getMessage();
        }
        self::registrar($os, array_merge($para, $cc), $assunto, $pdf !== '', $link !== '', $ok, $erro);
        if (!$ok) {
            return ['ok' => false, 'mensagem' => 'O e-mail não foi enviado: ' . $erro];
        }
        return ['ok' => true, 'mensagem' => 'Ordem de serviço enviada para ' . implode(', ', array_merge($para, $cc)) . '.'];
    }

    /** Mensagem segura; com $link, garante o link de assinatura absoluto (mesmo editado ou removido no editor) */
    public static function corpo(PluginOrdemdeservicoOrdem $os, string $mensagem, string $link): string
    {
        $corpo = \Glpi\RichText\RichText::getSafeHtml($mensagem);
        if ($link === '') {
            return $corpo;
        }
        $token = (string) $os->fields['token'];
        // O sanitizador do GLPI escreve "=" como &#61; dentro dos atributos
        $corpo = (string) preg_replace('#href="[^"]*assinar\.php\?t(?:=|&\#61;)' . preg_quote($token, '#') . '"#','href="' . PluginOrdemdeservicoConfig::e($link) . '"', $corpo);
        if (!str_contains($corpo, $token)) {
            $corpo .= '<p><a href="' . PluginOrdemdeservicoConfig::e($link) . '">Conferir e assinar a ordem de serviço</a></p>';
        }
        return $corpo;
    }

    public static function nomeArquivo(PluginOrdemdeservicoOrdem $os): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $os->fields['numero']) . '.pdf';
    }

    private static function registrar(PluginOrdemdeservicoOrdem $os, array $emails, string $assunto, bool $comPdf, bool $comLink, bool $ok, string $erro): void
    {
        global $DB;
        $DB->insert(self::TABELA, [
            'plugin_ordemdeservico_documentos_id' => (int) $os->getID(),
            'users_id'      => (int) Session::getLoginUserID(),
            'destinatarios' => json_encode(array_values($emails)),
            'assunto'       => mb_substr($assunto, 0, 255),
            'com_pdf'       => (int) $comPdf,
            'com_link'      => (int) $comLink,
            'sucesso'       => (int) $ok,
            'erro'          => mb_substr($erro, 0, 2000),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        Log::history((int) $os->getID(), PluginOrdemdeservicoOrdem::class, [0, '', ($ok ? 'Enviada por e-mail para ' : 'Falha no envio por e-mail para ') . implode(', ', $emails)], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    /**
     * Anexa o PDF ao item de origem como documento nativo. Retorna ['ok', 'mensagem'].
     */
    public static function anexarAoItem(PluginOrdemdeservicoOrdem $os, string $pdf): array
    {
        $item = PluginOrdemdeservicoDocumento::item((string) $os->fields['itemtype'], (int) $os->fields['items_id']);
        if (!$item || !Document::canCreate() || !$item->canAddItem('Document')) {
            return ['ok' => false, 'mensagem' => 'Você não pode anexar documentos a este item.'];
        }
        if ($pdf === '') {
            return ['ok' => false, 'mensagem' => 'O PDF recebido é inválido.'];
        }
        $prefixo = uniqid('', true) . '_';
        $nome = self::nomeArquivo($os);
        if (@file_put_contents(GLPI_TMP_DIR . '/' . $prefixo . $nome, $pdf) === false) {
            return ['ok' => false, 'mensagem' => 'Não foi possível gravar o arquivo temporário.'];
        }
        $doc = new Document();
        $id = $doc->add([
            'name'              => 'Ordem de serviço ' . $os->fields['numero'],
            'entities_id'       => (int) $item->fields['entities_id'],
            'is_recursive'      => 0,
            'itemtype'          => $item->getType(),
            'items_id'          => (int) $item->getID(),
            '_filename'         => [$prefixo . $nome],
            '_prefix_filename'  => [$prefixo],
        ]);
        if (!$id) {
            @unlink(GLPI_TMP_DIR . '/' . $prefixo . $nome);
            return ['ok' => false, 'mensagem' => 'O GLPI não aceitou o documento (verifique se a extensão PDF é permitida).'];
        }
        Log::history((int) $os->getID(), PluginOrdemdeservicoOrdem::class, [0, '', 'PDF anexado ao ' . mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem($item->getType())) . ' #' . $item->getID()], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return ['ok' => true, 'mensagem' => 'PDF anexado ao ' . mb_strtolower(PluginOrdemdeservicoDocumento::rotuloItem($item->getType())) . ' #' . $item->getID() . '.', 'documents_id' => (int) $id];
    }

    // =====================================================================
    // Aba "Envios"
    // =====================================================================

    public static function mostrarAba(PluginOrdemdeservicoOrdem $os): void
    {
        global $DB;
        $C = PluginOrdemdeservicoConfig::class;
        $e = [$C, 'e'];
        echo $C::assets();
        echo '<div class="ordemdeservico-pagina">';
        if (self::remetente() === null) {
            echo '<div class="ordemdeservico-alerta ordemdeservico-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O GLPI não tem remetente de e-mail configurado. '
                . ($C::ehAdmin() ? 'Informe um na <a href="' . $e($C::url('config.form.php', ['aba' => 'email'])) . '">configuração do plugin</a> ou em Configurar > Notificações.' : 'Peça a um administrador para configurar.') . '</span></div>';
        }
        $linhas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['plugin_ordemdeservico_documentos_id' => (int) $os->getID()], 'ORDER' => 'id DESC', 'LIMIT' => 200]), false);
        echo '<div class="card ordemdeservico-card"><div class="card-header"><h5><i class="ti ti-mail"></i> Envios por e-mail</h5></div><div class="card-body p-0">';
        if (!$linhas) {
            echo '<div class="ordemdeservico-vazio"><i class="ti ti-mail-off"></i><span>Esta ordem de serviço ainda não foi enviada. Use <strong>Enviar por e-mail</strong> na aba principal.</span></div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm table-hover ordemdeservico-tabela mb-0"><thead><tr><th>Data</th><th>Enviado por</th><th>Destinatários</th><th>Assunto</th><th>Conteúdo</th><th>Resultado</th></tr></thead><tbody>';
            foreach ($linhas as $l) {
                $lista = json_decode((string) $l['destinatarios'], true) ?: [];
                $conteudo = array_filter([(int) $l['com_pdf'] ? 'PDF' : 'documento no corpo', (int) $l['com_link'] ? 'link de assinatura' : '']);
                echo '<tr><td class="text-nowrap">' . $e(Html::convDateTime((string) $l['date_creation'])) . '</td>'
                    . '<td>' . $e(getUserName((int) $l['users_id'])) . '</td>'
                    . '<td>' . $e(implode(', ', $lista)) . '</td>'
                    . '<td>' . $e($l['assunto']) . '</td>'
                    . '<td class="text-nowrap">' . $e(implode(' + ', $conteudo)) . '</td>'
                    . '<td>' . ((int) $l['sucesso'] ? '<span class="ordemdeservico-selo ordemdeservico-selo-assinada">Enviado</span>'
                        : '<span class="ordemdeservico-selo ordemdeservico-selo-cancelada">Falhou</span><div class="ordemdeservico-pequeno" title="' . $e($l['erro']) . '">' . $e(mb_strimwidth((string) $l['erro'], 0, 90, '…')) . '</div>') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';
    }
}
