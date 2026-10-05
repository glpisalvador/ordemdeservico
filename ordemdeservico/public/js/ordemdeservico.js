/* Plugin Ordem de Serviço - documento (PDF, impressão), assinaturas em canvas, envio por e-mail,
 * anexo ao item, ações da OS, geração na aba do item, configuração e página pública de assinatura. */
(function () {
    'use strict';

    if (window.ordemdeservicoCarregado) {
        return;
    }
    window.ordemdeservicoCarregado = true;

    var LIB_CANVAS = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    var LIB_PDF = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    var ID_EDITOR = 'ordemdeservico-email-mensagem';

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    var ocupado = function (btn, sim, texto) {
        if (!btn) {
            return;
        }
        var span = btn.querySelector('span');
        if (sim) {
            btn.disabled = true;
            if (span) {
                btn.dataset.textoOriginal = span.textContent;
                span.textContent = texto || 'Aguarde...';
            }
        } else {
            btn.disabled = false;
            if (span && btn.dataset.textoOriginal) {
                span.textContent = btn.dataset.textoOriginal;
            }
        }
    };

    /** POST para o ajax.php da OS (token CSRF só existe no GLPI 11) */
    var postar = function (raiz, dados) {
        var fd = new FormData();
        fd.append('id', raiz.dataset.id);
        Object.keys(dados).forEach(function (k) {
            fd.append(k, dados[k]);
        });
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        if (raiz.dataset.token) {
            fd.append('_glpi_csrf_token', raiz.dataset.token);
            cab['X-Glpi-Csrf-Token'] = raiz.dataset.token;
        }
        return fetch(raiz.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var r = lerJson(t);
                if (r.new_token) {
                    raiz.dataset.token = r.new_token;
                }
                return r;
            })
            .catch(function () {
                return { success: false, mensagem: 'Falha de comunicação com o servidor.' };
            });
    };

    var carregarScript = function (url) {
        return new Promise(function (ok, falha) {
            var existente = document.querySelector('script[src="' + url + '"]');
            if (existente) {
                if (existente.dataset.carregado === '1') {
                    ok();
                } else {
                    existente.addEventListener('load', function () { ok(); });
                    existente.addEventListener('error', falha);
                }
                return;
            }
            var s = document.createElement('script');
            s.src = url;
            s.onload = function () {
                s.dataset.carregado = '1';
                ok();
            };
            s.onerror = falha;
            document.head.appendChild(s);
        });
    };

    var modal = function (el) {
        if (el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
        return window.bootstrap.Modal.getOrCreateInstance(el);
    };

    // ------------------------------------------------------------------ confirmação dentro do botão

    /** Primeiro clique arma o botão; o segundo (em até 4 s) confirma */
    var confirmado = function (btn) {
        if (!btn.dataset.ordemdeservicoConfirmar) {
            return true;
        }
        if (btn.dataset.armado === '1') {
            btn.dataset.armado = '';
            btn.classList.remove('ordemdeservico-confirmando');
            return true;
        }
        btn.dataset.armado = '1';
        btn.classList.add('ordemdeservico-confirmando');
        btn.title = btn.dataset.ordemdeservicoConfirmar;
        var span = btn.querySelector('span');
        var original = span ? span.textContent : '';
        if (span) {
            span.textContent = 'Confirmar?';
        }
        setTimeout(function () {
            btn.dataset.armado = '';
            btn.classList.remove('ordemdeservico-confirmando');
            if (span) {
                span.textContent = original;
            }
        }, 4000);
        return false;
    };

    // ------------------------------------------------------------------ canvas de assinatura

    var iniciarCanvas = function (canvas) {
        if (canvas.ordemdeservicoPad) {
            return canvas.ordemdeservicoPad;
        }
        var ctx = canvas.getContext('2d');
        var caixa = canvas.closest('.ordemdeservico-canvas-caixa');
        var desenhando = false;
        var tracou = false;
        var ultimo = null;

        var pintarFundo = function () {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = '#1f2937';
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
        };
        pintarFundo();

        /** Posição real no canvas, considerando a escala entre o tamanho CSS e o interno */
        var getMousePos = function (e) {
            var r = canvas.getBoundingClientRect();
            var p = e.touches && e.touches.length ? e.touches[0] : e;
            return {
                x: (p.clientX - r.left) * (canvas.width / r.width),
                y: (p.clientY - r.top) * (canvas.height / r.height)
            };
        };
        var startDraw = function (e) {
            e.preventDefault();
            desenhando = true;
            ultimo = getMousePos(e);
            ctx.beginPath();
            ctx.arc(ultimo.x, ultimo.y, 1, 0, Math.PI * 2);
            ctx.fillStyle = '#1f2937';
            ctx.fill();
            tracou = true;
            if (caixa) {
                caixa.classList.add('ordemdeservico-com-traco');
            }
        };
        var draw = function (e) {
            if (!desenhando) {
                return;
            }
            e.preventDefault();
            var p = getMousePos(e);
            ctx.beginPath();
            ctx.moveTo(ultimo.x, ultimo.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            ultimo = p;
        };
        var stopDraw = function () {
            desenhando = false;
        };
        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDraw);
        canvas.addEventListener('mouseout', stopDraw);
        canvas.addEventListener('touchstart', startDraw, { passive: false });
        canvas.addEventListener('touchmove', draw, { passive: false });
        canvas.addEventListener('touchend', stopDraw);
        canvas.addEventListener('touchcancel', stopDraw);

        canvas.ordemdeservicoPad = {
            limpar: function () {
                pintarFundo();
                tracou = false;
                if (caixa) {
                    caixa.classList.remove('ordemdeservico-com-traco');
                }
            },
            /** Em branco se todos os pixels forem brancos (4294967295) */
            vazio: function () {
                if (!tracou) {
                    return true;
                }
                var px = new Uint32Array(ctx.getImageData(0, 0, canvas.width, canvas.height).data.buffer);
                for (var i = 0; i < px.length; i++) {
                    if (px[i] !== 4294967295) {
                        return false;
                    }
                }
                return true;
            },
            imagem: function () {
                return canvas.toDataURL('image/png');
            }
        };
        return canvas.ordemdeservicoPad;
    };

    document.addEventListener('click', function (e) {
        var limpar = e.target.closest('[data-ordemdeservico-canvas-limpar]');
        if (limpar) {
            var escopo = limpar.closest('.modal-content, form, .card-body') || document;
            var c = escopo.querySelector('[data-ordemdeservico-canvas]');
            if (c) {
                iniciarCanvas(c).limpar();
            }
        }
    });

    // ------------------------------------------------------------------ PDF e impressão

    /** Pontos bons para quebrar página (fim de linhas de tabela, parágrafos e blocos), em px do canvas */
    var pontosDeQuebra = function (doc, escala) {
        var topo = doc.getBoundingClientRect().top;
        var pontos = [];
        doc.querySelectorAll('tr, p, li, img, table, [data-os-secao] > div').forEach(function (el) {
            var r = el.getBoundingClientRect();
            if (r.height > 0) {
                pontos.push(Math.round((r.bottom - topo) * escala));
            }
        });
        return pontos.sort(function (a, b) { return a - b; });
    };

    var gerarPdf = function (doc) {
        return Promise.all([carregarScript(LIB_CANVAS), carregarScript(LIB_PDF)]).then(function () {
            var escala = 2;
            var pontos = pontosDeQuebra(doc, escala);
            return window.html2canvas(doc, { scale: escala, useCORS: true, allowTaint: true, backgroundColor: '#ffffff' }).then(function (canvas) {
                var JsPDF = window.jspdf.jsPDF;
                var arquivo = new JsPDF('p', 'mm', 'a4');
                var margem = 10;
                var largura = 210 - margem * 2;
                var alturaPagina = 297 - margem * 2;
                var pxPorMm = canvas.width / largura;
                var fatia = Math.floor(alturaPagina * pxPorMm);
                var y = 0;
                var n = 0;
                while (y < canvas.height - 2) {
                    var fim = Math.min(y + fatia, canvas.height);
                    if (fim < canvas.height) {
                        var melhor = 0;
                        pontos.forEach(function (p) {
                            if (p > y + fatia * 0.6 && p <= y + fatia) {
                                melhor = p;
                            }
                        });
                        if (melhor > 0) {
                            fim = melhor;
                        }
                    }
                    var parte = document.createElement('canvas');
                    parte.width = canvas.width;
                    parte.height = fim - y;
                    var c2 = parte.getContext('2d');
                    c2.fillStyle = '#ffffff';
                    c2.fillRect(0, 0, parte.width, parte.height);
                    c2.drawImage(canvas, 0, y, canvas.width, parte.height, 0, 0, canvas.width, parte.height);
                    if (n > 0) {
                        arquivo.addPage();
                    }
                    arquivo.addImage(parte.toDataURL('image/jpeg', 0.85), 'JPEG', margem, margem, largura, parte.height / pxPorMm);
                    y = fim;
                    n++;
                }
                var total = arquivo.getNumberOfPages();
                for (var i = 1; i <= total; i++) {
                    arquivo.setPage(i);
                    arquivo.setFontSize(8);
                    arquivo.setTextColor(150);
                    arquivo.text(i + ' / ' + total, 200, 292, { align: 'right' });
                }
                return arquivo;
            });
        });
    };

    var pdfBase64 = function (doc) {
        return gerarPdf(doc).then(function (arquivo) {
            return arquivo.output('datauristring').replace(/^data:application\/pdf;[^,]*base64,/, '');
        });
    };

    var imprimir = function (doc, titulo) {
        var janela = window.open('', '_blank');
        if (!janela) {
            aviso('O navegador bloqueou a janela de impressão.', true);
            return;
        }
        janela.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(titulo) + '</title>'
            + '<style>body{margin:0;background:#fff}@page{size:A4;margin:12mm}[data-os-compacto] p{margin:0}</style></head><body>' + doc.innerHTML + '</body></html>');
        janela.document.close();
        janela.focus();
        setTimeout(function () { janela.print(); }, 400);
    };

    // ------------------------------------------------------------------ OS: ações

    document.addEventListener('click', function (e) {
        var raiz = e.target.closest('[data-ordemdeservico-os]');
        if (!raiz) {
            return;
        }
        var doc = raiz.querySelector('[data-ordemdeservico-doc]');

        var btnPdf = e.target.closest('[data-ordemdeservico-pdf]');
        if (btnPdf) {
            ocupado(btnPdf, true, 'Gerando...');
            gerarPdf(doc).then(function (arquivo) {
                arquivo.save(raiz.dataset.nome || 'ordem-de-servico.pdf');
            }).catch(function () {
                aviso('Não foi possível gerar o PDF (as bibliotecas de PDF não carregaram).', true);
            }).then(function () {
                ocupado(btnPdf, false);
            });
            return;
        }

        if (e.target.closest('[data-ordemdeservico-imprimir]')) {
            imprimir(doc, raiz.dataset.nome || 'Ordem de serviço');
            return;
        }

        var btnAnexar = e.target.closest('[data-ordemdeservico-anexar]');
        if (btnAnexar) {
            ocupado(btnAnexar, true, 'Anexando...');
            pdfBase64(doc).then(function (b64) {
                return postar(raiz, { action: 'anexar', pdf: b64 });
            }).then(function (r) {
                aviso(r.mensagem || (r.success ? 'PDF anexado.' : 'Não foi possível anexar.'), !r.success);
            }).catch(function () {
                aviso('Não foi possível gerar o PDF.', true);
            }).then(function () {
                ocupado(btnAnexar, false);
            });
            return;
        }

        var btnCopiar = e.target.closest('[data-ordemdeservico-copiar]');
        if (btnCopiar) {
            var campo = raiz.querySelector('[data-ordemdeservico-link]');
            if (!campo || !campo.value) {
                aviso('Gere o link primeiro.', true);
                return;
            }
            var copiou = function () { aviso('Link copiado.'); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(campo.value).then(copiou, function () {
                    campo.select();
                    document.execCommand('copy');
                    copiou();
                });
            } else {
                campo.select();
                document.execCommand('copy');
                copiou();
            }
            return;
        }

        var btnAcao = e.target.closest('[data-ordemdeservico-acao]');
        if (btnAcao) {
            if (!confirmado(btnAcao)) {
                return;
            }
            var acao = btnAcao.dataset.ordemdeservicoAcao;
            var dados = { action: acao };
            if (btnAcao.dataset.papel) {
                dados.papel = btnAcao.dataset.papel;
            }
            ocupado(btnAcao, true);
            postar(raiz, dados).then(function (r) {
                ocupado(btnAcao, false);
                if (!r.success) {
                    aviso(r.mensagem || 'Não foi possível concluir.', true);
                    return;
                }
                if (r.recarregar) {
                    window.location.reload();
                    return;
                }
                if (acao === 'link') {
                    raiz.querySelector('[data-ordemdeservico-link]').value = r.link || '';
                    raiz.querySelector('[data-ordemdeservico-link-validade]').textContent = r.validade || '';
                    btnAcao.querySelector('span').textContent = 'Gerar novo link';
                    aviso(r.mensagem || 'Link gerado.');
                }
            });
            return;
        }

        var btnAssinar = e.target.closest('[data-ordemdeservico-assinar]');
        if (btnAssinar) {
            abrirAssinatura(raiz, btnAssinar);
            return;
        }

        if (e.target.closest('[data-ordemdeservico-email-abrir]')) {
            abrirEmail(raiz);
        }
    });

    // ------------------------------------------------------------------ OS: modal de assinatura

    var abrirAssinatura = function (raiz, btn) {
        var el = raiz.querySelector('[data-ordemdeservico-modal-assinatura]') || document.querySelector('body > [data-ordemdeservico-modal-assinatura]');
        if (!el) {
            return;
        }
        el.ordemdeservicoRaiz = raiz;
        el.dataset.papel = btn.dataset.ordemdeservicoAssinar;
        el.querySelector('[data-ordemdeservico-assinatura-titulo]').textContent = 'Assinatura - ' + (btn.dataset.rotulo || '');
        el.querySelector('[data-ordemdeservico-assinatura-nome]').value = btn.dataset.nome || '';
        el.querySelector('[data-ordemdeservico-assinatura-erro]').hidden = true;
        var m = modal(el);
        el.addEventListener('shown.bs.modal', function () {
            iniciarCanvas(el.querySelector('[data-ordemdeservico-canvas]')).limpar();
        }, { once: true });
        m.show();
    };

    document.addEventListener('click', function (e) {
        var salvar = e.target.closest('[data-ordemdeservico-assinatura-salvar]');
        if (!salvar) {
            return;
        }
        var el = salvar.closest('[data-ordemdeservico-modal-assinatura]');
        var erro = el.querySelector('[data-ordemdeservico-assinatura-erro]');
        var pad = iniciarCanvas(el.querySelector('[data-ordemdeservico-canvas]'));
        var nome = el.querySelector('[data-ordemdeservico-assinatura-nome]').value.trim();
        var falha = function (t) {
            erro.textContent = t;
            erro.hidden = false;
        };
        if (nome.length < 3) {
            falha('Informe o nome de quem assina.');
            return;
        }
        if (pad.vazio()) {
            falha('Faça a assinatura no quadro.');
            return;
        }
        ocupado(salvar, true, 'Gravando...');
        postar(el.ordemdeservicoRaiz, { action: 'assinar', papel: el.dataset.papel, nome: nome, imagem: pad.imagem() }).then(function (r) {
            if (r.success) {
                window.location.reload();
                return;
            }
            ocupado(salvar, false);
            falha(r.mensagem || 'Não foi possível gravar a assinatura.');
        });
    });

    // ------------------------------------------------------------------ OS: modal de e-mail

    var editor = function () {
        return window.tinymce ? window.tinymce.get(ID_EDITOR) : null;
    };

    var iniciarEditor = function (conteudo) {
        var textarea = document.getElementById(ID_EDITOR);
        textarea.value = conteudo;
        if (!window.tinymce) {
            return;
        }
        var existente = editor();
        if (existente) {
            existente.setContent(conteudo);
            return;
        }
        var configs = window.tinymce_editor_configs || {};
        var base = configs[Object.keys(configs)[0]] || {};
        var cfg = Object.assign({}, base, {
            selector: '#' + ID_EDITOR, target: undefined, height: 260, min_height: 200,
            license_key: 'gpl', menubar: false, statusbar: false, branding: false, toolbar_location: 'top',
            quickbars_insert_toolbar: false, quickbars_selection_toolbar: false,
            toolbar: 'bold italic underline | forecolor backcolor | bullist numlist | link table | removeformat',
            // Links do e-mail precisam continuar absolutos (o padrão do TinyMCE os deixa relativos)
            convert_urls: false, relative_urls: false, remove_script_host: false,
            setup: undefined, init_instance_callback: undefined
        });
        if (!base.skin_url) {
            cfg.skin = false;
            cfg.content_css = false;
            cfg.plugins = 'lists link table autoresize';
        }
        cfg.content_style = (cfg.content_style || '') + ' body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; color: #333; }';
        window.tinymce.init(cfg);
    };

    var conteudoEditor = function () {
        var ed = editor();
        return ed ? ed.getContent() : document.getElementById(ID_EDITOR).value;
    };

    var sugerir = function (raiz, el) {
        var link = el.querySelector('[data-ordemdeservico-email-link]');
        return postar(raiz, { action: 'sugestao_email', com_link: link && link.checked ? 1 : 0 }).then(function (r) {
            if (!r.success) {
                aviso(r.mensagem || 'Não foi possível montar a mensagem.', true);
                return;
            }
            el.querySelector('[data-ordemdeservico-email-assunto]').value = r.assunto || '';
            el.dataset.sugestao = r.mensagem || '';
            iniciarEditor(r.mensagem || '');
        });
    };

    var abrirEmail = function (raiz) {
        var el = raiz.querySelector('[data-ordemdeservico-modal-email]') || document.querySelector('body > [data-ordemdeservico-modal-email]');
        if (!el) {
            return;
        }
        el.ordemdeservicoRaiz = raiz;
        el.querySelector('[data-ordemdeservico-email-erro]').hidden = true;
        if (!el.dataset.ligado) {
            el.dataset.ligado = '1';
            el.addEventListener('hidden.bs.modal', function () {
                var ed = editor();
                if (ed) {
                    ed.save();
                    ed.remove();
                }
            });
            var link = el.querySelector('[data-ordemdeservico-email-link]');
            if (link) {
                link.addEventListener('change', function () {
                    // Só refaz a mensagem se a pessoa não mexeu no texto sugerido
                    var ed = editor();
                    if (!ed || !ed.isDirty()) {
                        sugerir(el.ordemdeservicoRaiz, el);
                    }
                });
            }
        }
        var m = modal(el);
        el.addEventListener('shown.bs.modal', function () { sugerir(raiz, el); }, { once: true });
        m.show();
    };

    document.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-ordemdeservico-sugestao]');
        if (chip) {
            var campo = chip.closest('.modal-body').querySelector('[data-ordemdeservico-email-para]');
            var lista = campo.value.split(/[\s,;]+/).filter(function (x) { return x !== ''; });
            if (lista.indexOf(chip.dataset.ordemdeservicoSugestao) < 0) {
                lista.push(chip.dataset.ordemdeservicoSugestao);
            }
            campo.value = lista.join(', ');
            return;
        }
        var enviar = e.target.closest('[data-ordemdeservico-email-enviar]');
        if (!enviar) {
            return;
        }
        var el = enviar.closest('[data-ordemdeservico-modal-email]');
        var raiz = el.ordemdeservicoRaiz;
        var erro = el.querySelector('[data-ordemdeservico-email-erro]');
        var falha = function (t) {
            erro.textContent = t;
            erro.hidden = false;
        };
        var para = el.querySelector('[data-ordemdeservico-email-para]').value.trim();
        var cc = el.querySelector('[data-ordemdeservico-email-cc]').value.trim();
        var invalidos = (para + ',' + cc).split(/[\s,;]+/).filter(function (x) { return x !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x); });
        if (!para) {
            falha('Informe pelo menos um destinatário.');
            return;
        }
        if (invalidos.length) {
            falha('E-mail inválido: ' + invalidos.join(', '));
            return;
        }
        erro.hidden = true;
        var comPdf = el.querySelector('[data-ordemdeservico-email-pdf]').checked;
        var link = el.querySelector('[data-ordemdeservico-email-link]');
        var dados = {
            action: 'enviar',
            para: para,
            cc: cc,
            assunto: el.querySelector('[data-ordemdeservico-email-assunto]').value,
            mensagem: conteudoEditor(),
            com_link: link && link.checked ? 1 : 0
        };
        ocupado(enviar, true, comPdf ? 'Gerando PDF...' : 'Enviando...');
        var preparo = comPdf ? pdfBase64(raiz.querySelector('[data-ordemdeservico-doc]')) : Promise.resolve('');
        preparo.then(function (b64) {
            dados.pdf = b64;
            enviar.querySelector('span').textContent = 'Enviando...';
            return postar(raiz, dados);
        }).then(function (r) {
            if (r.success) {
                window.location.reload();
                return;
            }
            ocupado(enviar, false);
            falha(r.mensagem || 'Não foi possível enviar.');
        }).catch(function () {
            ocupado(enviar, false);
            falha('Não foi possível gerar o PDF. Desmarque "Anexar o PDF" para enviar o documento no corpo do e-mail.');
        });
    });

    // ------------------------------------------------------------------ aba do item: gerar OS

    document.addEventListener('change', function (e) {
        var form = e.target.closest('[data-ordemdeservico-gerar]');
        if (!form) {
            return;
        }
        var secoes = Array.from(form.querySelectorAll('input[name="_secoes[]"]'));
        var todas = form.querySelector('[data-ordemdeservico-secoes-todas]');
        if (e.target === todas) {
            secoes.forEach(function (c) { c.checked = todas.checked; });
        }
        var n = secoes.filter(function (c) { return c.checked; }).length;
        todas.checked = n === secoes.length;
        todas.indeterminate = n > 0 && n < secoes.length;
        if (e.target.matches('[data-ordemdeservico-solicitante]')) {
            var opcao = Array.from(form.querySelectorAll('#ordemdeservico-requerentes option')).filter(function (o) { return o.value === e.target.value; })[0];
            if (opcao && opcao.dataset.email) {
                form.querySelector('[data-ordemdeservico-solicitante-email]').value = opcao.dataset.email;
            }
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-ordemdeservico-gerar]');
        if (form && !form.querySelector('input[name="_secoes[]"]:checked')) {
            e.preventDefault();
            aviso('Escolha pelo menos uma seção.', true);
        }
    });

    var iniciarGerar = function (raiz) {
        (raiz || document).querySelectorAll('[data-ordemdeservico-gerar]').forEach(function (form) {
            var secoes = Array.from(form.querySelectorAll('input[name="_secoes[]"]'));
            var todas = form.querySelector('[data-ordemdeservico-secoes-todas]');
            var n = secoes.filter(function (c) { return c.checked; }).length;
            todas.checked = n === secoes.length;
            todas.indeterminate = n > 0 && n < secoes.length;
        });
    };

    // ------------------------------------------------------------------ configuração

    var iniciarConfig = function () {
        var raiz = document.querySelector('[data-ordemdeservico-config]');
        if (!raiz || raiz.dataset.iniciado) {
            return;
        }
        raiz.dataset.iniciado = '1';
        raiz.querySelectorAll('[data-aba]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                raiz.querySelectorAll('[data-aba]').forEach(function (x) { x.classList.toggle('active', x === a); });
                raiz.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== a.dataset.aba; });
                raiz.querySelectorAll('[data-ordemdeservico-aba-atual]').forEach(function (h) { h.value = a.dataset.aba; });
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('aba', a.dataset.aba);
                    window.history.replaceState(null, '', url.toString());
                } catch (err) { /* navegador antigo */ }
            });
        });
        raiz.querySelectorAll('[data-ordemdeservico-busca-tabela]').forEach(function (inp) {
            var tabela = inp.closest('.card-body').querySelector('table');
            var tempo = null;
            inp.addEventListener('input', function () {
                clearTimeout(tempo);
                tempo = setTimeout(function () {
                    var termo = inp.value.trim().toLowerCase();
                    tabela.querySelectorAll('tbody tr[data-linha]').forEach(function (tr) {
                        tr.hidden = termo !== '' && tr.dataset.search.indexOf(termo) < 0;
                    });
                }, 300);
            });
        });
    };

    // ------------------------------------------------------------------ página pública

    var iniciarPublico = function () {
        var form = document.querySelector('[data-ordemdeservico-publico-form]');
        if (form && !form.dataset.iniciado) {
            form.dataset.iniciado = '1';
            var pad = iniciarCanvas(form.querySelector('[data-ordemdeservico-canvas]'));
            form.addEventListener('submit', function (e) {
                var erro = form.querySelector('[data-ordemdeservico-publico-erro]');
                var nome = form.querySelector('input[name="nome"]').value.trim();
                var msg = '';
                if (nome.length < 3) {
                    msg = 'Informe seu nome completo.';
                } else if (pad.vazio()) {
                    msg = 'Faça sua assinatura no quadro.';
                }
                if (msg) {
                    e.preventDefault();
                    erro.textContent = msg;
                    erro.hidden = false;
                    return;
                }
                form.querySelector('[data-ordemdeservico-publico-imagem]').value = pad.imagem();
                var btn = form.querySelector('button[type="submit"]');
                setTimeout(function () { ocupado(btn, true, 'Enviando...'); }, 0);
            });
        }
        var imprimirPublico = document.querySelector('[data-ordemdeservico-imprimir-publico]');
        if (imprimirPublico && !imprimirPublico.dataset.iniciado) {
            imprimirPublico.dataset.iniciado = '1';
            imprimirPublico.addEventListener('click', function () { window.print(); });
        }
    };

    // ------------------------------------------------------------------ início (também após carregar abas via AJAX)

    var iniciar = function () {
        iniciarGerar();
        iniciarConfig();
        iniciarPublico();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
    if (window.jQuery) {
        window.jQuery(document).on('glpi.tab.loaded', function () { iniciarGerar(); });
        window.jQuery(document).ajaxComplete(function (ev, xhr, opcoes) {
            if (opcoes && /common\.tabs\.php/.test(opcoes.url || '')) {
                setTimeout(iniciarGerar, 50);
            }
        });
    }
})();
