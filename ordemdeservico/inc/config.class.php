<?php

/**
 * Plugin Ordem de Serviço - configurações (chave/valor) e utilitários comuns
 */
class PluginOrdemdeservicoConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_ordemdeservico_configs';
    public const DIREITO = 'plugin_ordemdeservico_ordem';

    /** Itens de onde uma OS pode ser gerada: itemtype => rótulo */
    public const ITENS = [
        'Ticket'  => 'Chamado',
        'Problem' => 'Problema',
        'Change'  => 'Mudança',
    ];

    /** Seções do documento: chave => rótulo */
    public const SECOES = [
        'informacoes'     => 'Informações do atendimento',
        'atribuicao'      => 'Atores',
        'descricao'       => 'Descrição',
        'acompanhamentos' => 'Acompanhamentos',
        'tarefas'         => 'Tarefas',
        'validacoes'      => 'Validações',
        'solucao'         => 'Solução',
        'imagens'         => 'Imagens anexas',
    ];

    /** Variáveis aceitas no título, no assunto e na mensagem do e-mail */
    public const VARIAVEIS = [
        '{numero}'      => 'Número da OS',
        '{titulo}'      => 'Título da OS',
        '{item}'        => 'Tipo do item (chamado, problema, mudança)',
        '{item_id}'     => 'Número do item',
        '{item_titulo}' => 'Título do item',
        '{empresa}'     => 'Nome da empresa',
        '{entidade}'    => 'Entidade',
        '{usuario}'     => 'Quem envia',
        '{link}'        => 'Link de assinatura (quando houver)',
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Ordem de Serviço';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'itens'             => array_keys(self::ITENS),
            'empresa'           => '',
            'cabecalho'         => '',
            'rodape'            => '',
            'logo'              => '',
            'prefixo'           => 'OS',
            'modelo_titulo'     => 'Ordem de serviço - {item} #{item_id}',
            'secoes'            => ['informacoes', 'atribuicao', 'descricao', 'acompanhamentos', 'tarefas', 'solucao'],
            'privados'          => '0',
            'termo'             => '<p>Declaro que o serviço descrito nesta ordem foi realizado e está de acordo com o solicitado.</p>',
            'assinatura_remota' => '1',
            'validade_dias'     => '7',
            'remetente_email'   => '',
            'remetente_nome'    => '',
            'email_assunto'     => '{numero} - {titulo}',
            'email_mensagem'    => '<p>Olá,</p><p>Segue a ordem de serviço <strong>{numero}</strong> referente ao {item} #{item_id} ({item_titulo}).</p><p>{link}</p><p>Atenciosamente,<br>{usuario}</p>',
        ];
    }

    private static ?array $ordemdeservicoConfigs = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$ordemdeservicoConfigs === null) {
            self::$ordemdeservicoConfigs = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$ordemdeservicoConfigs[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$ordemdeservicoConfigs)) {
            return self::$ordemdeservicoConfigs[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$ordemdeservicoConfigs = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    /** Itens (Ticket, Problem, Change) habilitados para gerar OS */
    public static function itensHabilitados(): array
    {
        return array_values(array_intersect(array_keys(self::ITENS), self::getArrayConfig('itens')));
    }

    /** Seções marcadas por padrão ao gerar uma OS */
    public static function secoesPadrao(): array
    {
        return array_values(array_intersect(array_keys(self::SECOES), self::getArrayConfig('secoes')));
    }

    // =====================================================================
    // Arquivos (logotipo e assinaturas ficam em files/_plugins/ordemdeservico)
    // =====================================================================

    public static function pasta(string $sub = ''): string
    {
        $p = GLPI_PLUGIN_DOC_DIR . '/ordemdeservico' . ($sub !== '' ? '/' . trim($sub, '/') : '');
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        return $p;
    }

    /** Logotipo como data URI (fica embutido na tela, no PDF, no e-mail e na página pública) */
    public static function logoDataUri(): string
    {
        $arquivo = basename((string) self::getConfig('logo'));
        $caminho = $arquivo !== '' ? self::pasta() . '/' . $arquivo : '';
        if ($caminho === '' || !is_file($caminho)) {
            return '';
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($caminho));
    }

    /** Recebe o logotipo enviado na configuração (PNG, JPG, GIF ou WEBP até 1 MB). Retorna o erro ou '' */
    public static function salvarLogo(array $arquivo): string
    {
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($arquivo['tmp_name'] ?? ''))) {
            return 'O envio do logotipo falhou.';
        }
        if ((int) $arquivo['size'] > 1024 * 1024) {
            return 'O logotipo deve ter no máximo 1 MB.';
        }
        $tipos = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $arquivo['tmp_name']) ?: '';
        if (!isset($tipos[$mime]) || @getimagesize((string) $arquivo['tmp_name']) === false) {
            return 'Use uma imagem PNG, JPG, GIF ou WEBP.';
        }
        $nome = 'logo_' . bin2hex(random_bytes(6)) . '.' . $tipos[$mime];
        if (!move_uploaded_file((string) $arquivo['tmp_name'], self::pasta() . '/' . $nome)) {
            return 'Não foi possível gravar o logotipo.';
        }
        self::removerLogo();
        self::setConfig('logo', $nome);
        return '';
    }

    public static function removerLogo(): void
    {
        $antigo = basename((string) self::getConfig('logo'));
        if ($antigo !== '' && is_file(self::pasta() . '/' . $antigo)) {
            @unlink(self::pasta() . '/' . $antigo);
        }
        self::setConfig('logo', '');
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/ordemdeservico/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL absoluta (links enviados por e-mail), a partir da URL da aplicação configurada no GLPI */
    public static function urlAbsoluta(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        if ($base === '') {
            return self::url($arquivo, $params);
        }
        return $base . '/plugins/ordemdeservico/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (servido em /plugins/<nome>/ no GLPI 11/12), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/ordemdeservico/' . $caminho . '?v=' . PLUGIN_ORDEMDESERVICO_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    public static function assets(): string
    {
        static $feito = false;
        if ($feito) {
            return '';
        }
        $feito = true;
        return '<link rel="stylesheet" href="' . self::e(self::urlAsset('css/ordemdeservico.css')) . '">'
            . '<script src="' . self::e(self::urlAsset('js/ordemdeservico.js')) . '"></script>';
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Troca as variáveis {x} de um modelo; $html escapa os valores */
    public static function aplicarVariaveis(string $modelo, array $valores, bool $html = false): string
    {
        $trocas = [];
        foreach ($valores as $chave => $valor) {
            $trocas['{' . $chave . '}'] = $html && $chave !== 'link' ? self::e($valor) : (string) $valor;
        }
        return strtr($modelo, $trocas);
    }

    /** Texto rico vazio (o TinyMCE deixa <p>&nbsp;</p>) */
    public static function vazio(?string $html): bool
    {
        return trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags((string) $html, '<img>'), ENT_QUOTES, 'UTF-8'))) === '';
    }
}
