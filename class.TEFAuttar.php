<?php
putenv("CTFCLIENT_HOME=/opt/AUTTAR/CTFClient");
require_once __DIR__ . '/class.TEF.php';

/**
 * Class TEFAuttar
 * @codeCoverageIgnore
 */
class TEFAuttar extends TEF {
    public function __construct() {

        if (!we_process_is_running("startupCTFClient.sh")) {
            //Iniciando o servidor Auttar, se não estiver rodando, o ideal e estar rodando.
            exec("/opt/AUTTAR/CTFClient/bin/startupCTFClient.sh > " . __DIR__ . "/_stdout.startupCTFClient.log &");
            sleep(1);
        }

         $this->regexCalls = "/iniciaClientCTF|iniciaTransacaoCTF|continuarTransacaoCTF|finalizaTransacaoCTF/";
        if (!we_process_is_running("IPYJavaAuttar.jar")) {
            $this->process_cmd = "java -jar " . __DIR__ . "/IPYJavaAuttar.jar";
        }

        $env            = NULL;
        $options        = array('bypass_shell' => true);//(windows only)
        $cwd            = NULL;
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin is a pipe that the child will read from
            1 => array("pipe", "w"), // stdout is a pipe that the child will write to
            2 => array("pipe", "w")  // stderr is a file to write to
        );

        $this->process = proc_open($this->process_cmd, $descriptorspec, $this->pipes, $cwd, $env, $options);
        if (!is_resource($this->process)) {
            sdbg(get_class($this) . " > ERR Não foi possível iniciar a aplicação cliente (jar)");
            throw new Exception("Não foi possível iniciar a aplicação cliente");
        }
        sdbg(get_class($this) . " < Iniciado");

        parent::__construct();
    }
    public function __destruct() {
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $return_value = proc_close($this->process);
    }
    public function __call($amethod, array $arguments) {
        if ($this->regexCalls && preg_match($this->regexCalls, $amethod)) {
            $tefObj         = new stdClass;
            $tefObj->method = $amethod;  // Método a ser executado
            $tefObj->id     = uniqid();     // ID do pacote
            $tefObj->params = $arguments;

            //Enviando parâmetros
            fwrite($this->pipes[0], json_encode($tefObj) . "\n");

            $ret         = fgets($this->pipes[1], 4096);
            $tefResposta = json_decode($ret);
            if ($amethod != "continuarTransacaoCTF")
                teflog("[TEF] __call() " . get_class($this) . " < $amethod('" . implode("','",$arguments) . "'), retorno: " . $ret);
            if (!$tefResposta) {
                if (!we_Memcache::get('atualizandoTabelasTEF')) {
                    sdbg(get_class($this) . " > ERR Sem resposta");
                    throw new Exception("$amethod Sem resposta.");
                } else {
                    sdbg(get_class($this) . " > ERR Sem resposta, mas atualizando tabelas.");
                }
            } else if (we_Memcache::get('atualizandoTabelasTEF') === true) {
                we_Memcache::set('atualizandoTabelasTEF', false);
            }
            if ($tefResposta->error) {
                sdbg(get_class($this) . " > ERR $amethod(" . implode(",",$arguments) . ") {$tefResposta->error[1]}, {$tefResposta->error[0]}");
                throw new Exception($tefResposta->error[1], $tefResposta->error[0]);
            }
            sdbg(get_class($this) . " > OK {$tefResposta->result}");
            return json_decode($tefResposta->result);
        } else parent::__call($amethod, $arguments);
    }
    protected function getFormasPagamento() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);

        $o      = $this->cfgPagamento();
        $return = explode(';',$o->Auttar->formasPagamento);
        if (!count($return)) {
            throw new Exception("Nenhuma forma de pagamento está configurada.");
        }
        return $return;
    }
    protected function checkValor(&$params) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $o = $this->cfgPagamento();
        if (!$params->valor) {
            $this->wsCallback_tefDesenharTela($this->montarTelaValor(),"iniciarTransacao", $this->timeoutDefaultOperacao);
            Abort();
        }
        $params->valor_raw = str_replace(array(".",","), "", $params->valor);
        if (!is_numeric($params->valor_raw)) {
            throw new Exception("O valor não parece ser um número válido. ($params->valor)");
        }
        if ((int)$params->valor_raw < (int)$o->Auttar->valorMinimo) {
            throw new Exception("Valor muito baixo para realizar uma transação.");
        }
        if ((int)$params->valor_raw > (int)$o->Auttar->valorMaximo) {
            throw new Exception("Valor muito alto para realizar uma transação.");
        };
    }
    protected function checkDoc(&$params) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        if (!$params->documento) {//Gerar um documento
            $params->documento = str_pad(rand(1, 9999) . str_replace('.','', microtime(TRUE)), 20, '0', STR_PAD_LEFT);
        }
    }
    protected function checkTrans(&$params) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        if (!$params->transacao) {//Gerar uma transação
            $params->transacao = "1";
        }
    }
    /**
     * Recebe o retorno da UI, a ser colocado na variaval valor. E retomar o loop.
     * @param type $params
     */
    public function avancar($params) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $this->valor = $params['selecao'];
        //Voltar ao loop
        $this->loopTransacao();
    }
    public function cancelar() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $this->comando = "8";
        $this->valor   = "0";
        $this->forcar  = false;
        //Voltar ao loop
        $this->loopTransacao();
    }
    public function cancelarForcado() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $this->comando = "8";
        $this->valor   = "0";
        $this->forcar  = true;
        //Voltar ao loop
        $this->loopTransacao();
    }
    /**
     *
     */
    private function loopTransacao() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $contador_atualizando_tabbelas = 1;
        while (true) {
            $timerInicial = microtime(true);

            $cancelar_operacao = we_Memcache::get("argospagamento_flag_cancelar");
            if ($cancelar_operacao) {
                we_Memcache::delete("argospagamento_flag_cancelar");
                $this->comando = "8";
                $this->valor   = "0";
                $this->forcar  = ($cancelar_operacao === 2);
            }

            //echo "loop, comando: $this->comando valor: $this->valor \n";
            $tefParametros = $this->continuarTransacaoCTF($this->comando, $this->valor);
            if ($tefParametros->resultado != 99) {
                break;
            }
            //Se não for o comando 8 resseta o timeout
            //Se ocorrer o 8, 30 x seguidas ocorre timeout
            if ($tefParametros->comando != 8) {
                $qtde8 = 1;
            }
            switch ($tefParametros->comando) {
                /* Informações da transação num_sc, p_sc */
                case 0:
                    //Manter o log da finalização

                    // Condições para a operação ter ocorrido com sucesso
                    if ($tefParametros->num_sc == "7000") {
                        if (substr($tefParametros->p_sc, 0, 2) == "00") {
                            $this->OperacaoRealizadaSucesso7000 = true;
                        } else {
                            $errcode                            = (int)$tefParametros->p_sc;
                            $this->OperacaoRealizadaSucesso7000 = false;
                            //Anexo I – Códigos de Retorno
                            //                            $this->wsCallback_consolelog("Código de retorno (7000): {$tefParametros->p_sc}");
                            sdbg("Código de retorno (7000): {$tefParametros->p_sc}");
                        }
                    }
                    if ($tefParametros->num_sc == "7300") {
                        if ($tefParametros->p_sc == "0000") {
                            $this->OperacaoRealizadaSucesso7300 = true;
                        } else {
                            $this->OperacaoRealizadaSucesso7300 = false;
                            $errcode7300                        = (int)$tefParametros->p_sc;
                            //Anexo IV - Código de Erro (Subcampo – 7300)
                            $this->wsCallback_consolelog("Código de erro(7300): {$tefParametros->p_sc}");
                            sdbg("Código de erro(7300): {$tefParametros->p_sc}");
                        }
                    }
                    //1ª via do cupom de TEF
                    if ($tefParametros->num_sc == "7302") {
                        $comprocante_tef_1via = str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc);
                        $this->wsCallback_tefComprovante1stVia($comprocante_tef_1via);
                        teflog("1stVia(7302): {$comprocante_tef_1via}");
                    }
                    //2ª via do cupom de TEF
                    if ($tefParametros->num_sc == "7303") {
                        $comprocante_tef_2via = str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc);
                        $this->wsCallback_tefComprovante2ndVia($comprocante_tef_2via);
                        teflog("2ndVia(7303): {$comprocante_tef_2via}");
                    }
                    //Cupom Reduzido
                    if ($tefParametros->num_sc == "7384") {
                        $this->wsCallback_tefComprovanteReduzido(str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc));
                        sdbg("Reduzido(7384)");

                        $this->wsCallback_tefDocumento($this->documento);
                        sdbg("Documento referencia");

                    }
                    //NSU autorizadora
                    if ($tefParametros->num_sc == "7081") {
                        $this->wsCallback_tefNsu(str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc));
                        sdbg("NSU autorizadora (7081)");
                    }
                    //NSU CTF
                    if ($tefParametros->num_sc == "7031") {
                        $this->wsCallback_tefNsuSitef(str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc));
                        sdbg("NSU Sitef (7031)");
                    }
                    // número do cartão de crédito
                    if ($tefParametros->num_sc == "7006") {
                        $this->wsCallback_tefCartaoNumero(str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc));
                        sdbg("tefCartaoNumero (7006)");
                    }
                    // bandeira do cartão de crédito
                    if ($tefParametros->num_sc == "7389") {
                        $this->wsCallback_tefBandeira(mb_strtolower(trim(str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc), 'UTF-8')));
                        sdbg("tefCartaoBandeira (7389)");
                    }
                    //Mensagem de display da transação, cada linha separada com #
                    if ($tefParametros->num_sc == "7385") {
                        $this->wsCallback_tefMensagem7385(str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc));
                        sdbg("Resultado(7385)");
                    }
                    $this->comando = "0";

                    if (is_numeric($tefParametros->num_sc)) {
                        $this->fRetorno[(int)$tefParametros->num_sc] = str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc);
                    }
                    break;

                /* Mensagem Display:
                  p_sc:  virá preenchido com a mensagem a ser exibida no display. O caractere "\" indica uma quebra de linha.
                  aux: virá preenchido com 1(Display Operador),2 (Display Cliente) ou 3(Ambos)
                 */
                case 1:
                    $msg = str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc);

                    // contenção da mensagem p/ autopass
                    if (preg_match("/aguard.*senha/i", $msg) ||
                        preg_match("/Digit.*senha/i", $msg) ||
                        preg_match("/solicit.*senha/i", $msg)) {
                            $msg = "DIGITE SUA SENHA";
                            $this->wsCallback_tefImagem("INSPASS");
                            $this->wsCallback_tefHabilitaCancelar("ajax");
                    }

                    $this->wsCallback_tefMensagemCli($msg);
                    $this->wsCallback_tefMensagemOpe($msg);

                    if (preg_match("/.*INSIRA.*\n*.*CARTAO.*/i", $msg)) {
                        $this->wsCallback_tefImagem("INSCARD");
                        $this->wsCallback_tefHabilitaCancelar("ajax");
                    }
                    if (preg_match("/.*RETIR.*\n*.*CARTAO.*/i", $msg)) {
                        $this->wsCallback_tefImagem("REMCARD");
                        $this->wsCallback_tefDesabilitaCancelar();
                    }
                    if (preg_match("/aguard.*senha/i", $msg)) {
                        $this->wsCallback_tefImagem("INSPASS");
                        $this->wsCallback_tefHabilitaCancelar("ajax");
                    }

                    if ('ATUALIZANDO TABELAS' == $msg) {
                        we_Memcache::set('atualizandoTabelasTEF', true);
                        $this->wsCallback_tefMensagemCli('Atualizando tabelas, aguarde... ( 1/2 )');
                        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", atualizacao de tabelas");
                        $this->wsCallback_tefImagem("INSCARD");

                        Realtime::registraOcorrencia("135", "1", now(), "Atualização de tabelas... 1/2", "00000000");

                    }

                    $this->comando = "1";
                    break;

                /* Título Menu:
                  p_sc  :  virá preenchido com o título de menu/texto. O caractere "\" indica uma quebra de linha.
                  tam_sc:  virá preenchido com o tamanho do título contido em  p_sc, incluindo os caracteres de quebra de linha.
                  aux   : virá preenchido com 1(Display Operador),2 (Display Cliente) ou 3(Ambos)
                 */
                case 2:
                    $msg = str_replace(array("\\","#",";"), "\n", $tefParametros->p_sc);
                    $this->wsCallback_tefMensagemTit($msg);
                    $this->comando = "2";
                    break;

                /* Limpar display:
                  aux   : indica o display a ser limpo: 1(Display Operador),2 (Display Cliente) ou 3(Ambos)
                 */
                case 3:
                    $this->wsCallback_tefLimparCli();
                    $this->wsCallback_tefLimparOpe();

                    $this->comando = "3";
                    break;
                /* Confirmação no estilo SIM/NAO:
                  aux   : indica o display a ser limpo: 1(Display Operador),2 (Display Cliente) ou 3(Ambos)
                 */
                case 4:
                    $this->wsCallback_tefDesenharTela($this->montarTelaOpcoes(array(array("1","SIM"),array("2","NÃO"))),"avancar",$this->timeoutDefaultOperacao);
                    $this->wsCallback_tefDesabilitaCancelar();

                    $this->comando = "4";
                    break 2;//Sair do loop e enviar pra UI
                /* Opções do menu ao usuário:
                  p_sc:  virá preenchido com o menu a ser exibido ao usuário, no formato "1:texto;2:texto;...i:texto;".
                  Quebras de linha podem ocorrer nos itens do menu, e são indicadas pelo caractere "\".
                  tam_sc:  virá preenchido com o tamanho do menu contido em  p_sc, incluindo os caracteres de quebra de linha.
                 */
                case 5:
                    $this->comando = "5";

                    // Se forcar, nao sair do loop, nem printar a pergunta...
                    if ($this->forcar === true) {
                        $this->valor = "1";
                        break;
                    }

                    $opcoes = explode(";", $tefParametros->p_sc);
                    array_walk($opcoes, function(&$el){
                        $el = explode(":",  str_replace("\\", "\n", $el));
                    });

                    //Se a pergunta for SIM NAO, nao apresentar o botao cancelar
                    if ($tefParametros->p_sc == "1:SIM;2:NAO") {
                        $this->wsCallback_tefDesenharTela($this->montarTelaOpcoes($opcoes),"avancar",$this->timeoutDefaultOperacao);
                        $this->wsCallback_tefDesabilitaCancelar();
                    } else {
                        $this->wsCallback_tefDesenharTela($this->montarTelaOpcoes($opcoes),"avancar",$this->timeoutDefaultOperacao);
                        $this->wsCallback_tefHabilitaCancelar();
                    }
                    break 2;//Sair do loop e enviar pra UI
                /* AC deve aguardar até que uma tecla seja pressionada pelo usuário. */
                case 6:
                    $this->wsCallback_tefContinuar6('', $this->timeoutDefaultOperacao);
                    $this->wsCallback_tefDesabilitaCancelar();
                    $this->comando = "6";
                    $this->wsCallback_tefHabilitaCancelar();
                    break 2;//Sair do loop e enviar pra UI

                /* AC deve capturar um dado no teclado:
                  num_sc: virá preenchido com o código do subcampo que deve ser capturado (veja Anexo IV – Subcampos).
                  p_sc  : virá preenchido com o tamanho máximo do subcampo que deve ser capturado
                  tam_sc: virá preenchido com a quantidade de bytes do campo p_sc usadas para representar o tamanho
                  máximo do subcampo a ser capturado.
                  aux   : virá preenchido com "1" para indicar que o subcampo pode ser capturado com zeros à esquerda, e
                  com "0" em caso contrário.
                 */
                case 7:
                    $this->comando = "7";
                    switch ($tefParametros->num_sc) {
                        case "7008":
                            $msg = "Informe o número de parcelas";
                            if ($this->qtd_parcelas > 0) {
                                $this->valor = (string)$this->qtd_parcelas;
                                continue 3;
                            } else {
                                break;
                            }
                        case "7012":
                            $msg = "Informe o NSU CTF";
                            break;
                        case "7324":
                            $msg = "Informe os últimos 4 dígitos";
                            break;
                        case "7039":
                            $msg = "Informe o código de segurança";
                            break;
                        case "7097":
                            $msg = "Data de agendamento da primeira parcela DDMMAA";
                            break;
                        case "7161":
                            $msg = "Informe a data da transação original no formato DDMMAA";
                            break;
                        case "7094":
                            $msg = "Data de agendamento do pré-datado, no formato DDMMAA";
                            if (!empty($this->opcoes->DataCobranca) && Validacao::B($this->opcoes->DataCobranca)->dataFormato('dmy')->exec()) {
                                $this->valor = (string)$this->opcoes->DataCobranca;
                            } else {
                                // caso nao tenha recebido ou o valor seja invalido, usa por padrao 30 dias no pre-datado
                                $this->valor = (new \DateTime(now()))->add(new \DateInterval("P30D"))->format('dmy');
                            }
                            continue 3;
                            break;
                        default:
                            $msg = "Informe o(s) valor(es) de {$tefParametros->num_sc}";
                            //7047(Taxa de Embarque)  7034(Taxa Serviço)   7054(saque) 7038 (Valor saque)
                            //                            if (strpos("7047,7034,7054,7038", $tefParametros->num_sc) !== false) {
                            //                                $this->valor = "";
                            //                            } else {
                            //                                echo "\nInforme campo: " . $tefParametros->num_sc . ", tam. maximo: " . $tefParametros->p_sc;
                            //                                $this->valor = trim(fgets(STDIN));
                            //                                if (strtoupper($this->valor) == 'C') {
                            //                                    $this->comando = 8;
                            //                                    $this->valor = "";
                            //                                }
                            //                            }
                            break;
                    }
                    $this->wsCallback_tefDesenharTela($this->montarTelaInput($msg) , "avancar",$this->timeoutDefaultOperacao);
                    $this->wsCallback_tefHabilitaCancelar();
                    break 2;
                /* Cancelar a operação:
                  comando: para cancelar preencha o comando com 8, caso contrário, preencha com 0
                  Uma operação pode ser cancelada nos comandos 5,6,7
                 */
                case 8:
                    if ($qtde8 >= $this->timeoutDefaultOperacao) {
                        $this->comando = "8";
                        $this->forcar  = true;
                        sdbg("Timeout forçado");
                    } else {
                        $this->comando = "0";
                    }

                    $timerFinal = microtime(true);
                    $aguardar   = ceil(($timerFinal - $timerInicial) * 1000000);
                    if ($aguardar > 0) {
                        usleep($aguardar);
                    }

                    $this->wsCallback_tefMensagemTimeout(($this->timeoutDefaultOperacao - $qtde8));
                    $qtde8++;
                    break;
            }
        }
        $situacao_transacao = 'PENDENTE';
        if ($this->OperacaoRealizadaSucesso7300 === true && $this->OperacaoRealizadaSucesso7000 === true) {
            $this->wsCallback_tefDesabilitaCancelar();
            $o = $this->cfgPagamento();

            $ultima_operacao_iniciada                    = $o;
            $ultima_operacao_iniciada->Auttar->documento = $this->documento;

            $tipoOperacao = (int)$o->Auttar->tipoOperacao;
            if (!$tipoOperacao) {
                $this->wsCallback_tefMensagemErr("TRANSAÇÃO CANCELADA");
                $this->finalizaTransacaoCTF(false,"1");

                Realtime::registraOcorrencia("135", "3", now(), "Falha de configuração parâmetro (tipoOperacao)", "00000000");

                throw new Exception("Falha de configuração parâmetro (tipoOperacao)");
            }
            //1 = Automático (Sem impressão)
            //2 = Automático (Com impressão)
            //3 = Manual (Confirmar ou cancelar posteriormente via Ajax)
            if ($tipoOperacao === 1) {
                // atualiza intenção como aprovar o pagamento
                // 0 = deixar como pendente
                // 1 = confirmar a transacao
                // 2 = cancelar a transacao
                $ultima_operacao_iniciada->Auttar->confirmaOperacao = 1;
                if (!@file_put_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json", json_encode($ultima_operacao_iniciada))) {
                    throw new Exception("Não foi possível armazenar intenção da transação");
                }
                exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($ultima_operacao_iniciada));

                $this->finalizaTransacaoCTF(true,"1");
                $situacao_transacao = 'APROVADA';

                @unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
            } else if ($tipoOperacao === 2) {
                // atualiza intenção como aprovar o pagamento
                // 0 = deixar como pendente
                // 1 = confirmar a transacao
                // 2 = cancelar a transacao
                $ultima_operacao_iniciada->Auttar->confirmaOperacao = 1;
                if (!@file_put_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json", json_encode($ultima_operacao_iniciada))) {
                    throw new Exception("Não foi possível armazenar intenção da transação");
                }
                exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($ultima_operacao_iniciada));

                //Imprimir comprovante
                $this->wsCallback_tefMensagemCli("IMPRIMINDO COMPROVANTE");
                try {
                    $r = $this->imprimirComprovante($this->fRetorno[7302]);
                    if (!$r) {
                        throw new Exception(" ");
                    }
                } catch (Exception $e) {
                    $this->wsCallback_tefMensagemErr("TRANSAÇÃO CANCELADA\nIMPRESSÃO COMPROVANTE FALHOU");

                    $this->finalizaTransacaoCTF(false,"1");
                    @unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
                    teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");

                    $situacao_transacao = 'FALHA';
                    $this->atualizaInfoPagamento($situacao_transacao);

                    $this->wsCallback_tefSituacao($situacao_transacao);

                    throw new Exception("Não foi possível imprimir comprovante: " . $e->getMessage());
                }
                $this->finalizaTransacaoCTF(true,"1");
                $situacao_transacao = 'APROVADA';
                @unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");

            } else if ($tipoOperacao === 3) {
                // modo manual, a confirmação ou cancelamento é feito depois
                // atualiza intenção como pendente o pagamento
                // 0 = deixar como pendente
                // 1 = confirmar a transacao
                // 2 = cancelar a transacao
                $ultima_operacao_iniciada->Auttar->confirmaOperacao = 0;
                if (!@file_put_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json", json_encode($ultima_operacao_iniciada))) {
                    throw new Exception("Não foi possível armazenar intenção da transação");
                }
                exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($ultima_operacao_iniciada));
            }

            $this->onTransacaoFinalizada('A', $this->fRetorno, $situacao_transacao);

            $this->wsCallback_tefMensagemCli("TRANSAÇÃO OK!");
            sleep(5);

            $this->wsCallback_tefRetornos($this->fRetorno);

            $this->foto('APROVADA');

            $this->wsCallback_tefFotos($this->fFotos);
            sdbg("Fotos(arr)");

            $this->wsCallback_tefParametrosIniTEF($this->fParametrosIni);
            sdbg("ParametrosIniTEF (arr)");

            $this->wsCallback_tefConfiguracaoTEF($this->fConfiguracaoTEF);
            sdbg("ConfiguracaoTEF (arr)");

            $this->wsCallback_tefSituacao($situacao_transacao);

            $this->wsCallback_tefAprovada();
            sdbg("Aprovado");

            Realtime::registraOcorrencia("135", "1", now(), "Transação aprovada: documento: {$this->documento}", "00000000");

        } else if ($this->OperacaoRealizadaSucesso7300 === false || $this->OperacaoRealizadaSucesso7000 === false) {
            // atualiza intenção como cancelar o pagamento
            // 0 = deixar como pendente
            // 1 = confirmar a transacao
            // 2 = cancelar a transacao
            $ultima_operacao_iniciada                           = new stdClass();
            $ultima_operacao_iniciada->Auttar                   = new stdClass();
            $ultima_operacao_iniciada->Auttar->confirmaOperacao = 2;
            if (!@file_put_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json", json_encode($ultima_operacao_iniciada))) {
                throw new Exception("Não foi possível armazenar intenção da transação");
            }
            exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

            teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($ultima_operacao_iniciada));

            $this->wsCallback_tefDesabilitaCancelar();

            $situacao_transacao = 'FALHA';

            //$errcode Coletado anteriormente
            if ($errcode == 4 || $errcode == 20) {
                $errmessage = $this->mensagemErro7300($errcode7300);                
            } else {    
                $errmessage = $this->mensagemErro($errcode);
            }    
                $this->wsCallback_tefMensagemErr($errmessage);
                
            @unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
            teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
            $this->wsCallback_tefNegado($errcode, $errmessage);
            sdbg("Negado");
            $this->foto('FALHA');

            $this->wsCallback_tefSituacao($situacao_transacao);

            $this->wsCallback_tefFotos($this->fFotos);
            sdbg("Fotos(arr)");

            $this->wsCallback_tefParametrosIniTEF($this->fParametrosIni);
            sdbg("ParametrosIniTEF (arr)");

            $this->wsCallback_tefConfiguracaoTEF($this->fConfiguracaoTEF);
            sdbg("ConfiguracaoTEF (arr)");

            // Registra ocorrencia
            Realtime::registraOcorrencia("135", "3", now(), "Erro no TEF, documento: {$this->documento}: $errmessage", "00000000");

            $this->onTransacaoFinalizada('N', array("codigo" => $errcode,"mensagem" => $errmessage), $situacao_transacao);
        }

        if (we_Memcache::get('atualizandoTabelasTEF') === true) {
            Realtime::registraOcorrencia("135", "1", now(), "Atualização de tabelas... 2/2", "00000000");
            teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", atualizacao de tabelas");
            $this->wsCallback_tefMensagemCli('Atualizando tabelas, aguarde... ( 2/2 )');
            $this->wsCallback_tefMensagemOpe('ATUALIZANDO TABELAS');
            $this->wsCallback_tefImagem("INSCARD");
        }

    }
    public function mensagemErro($erro) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $erro = (int)$erro;

        switch ($erro) {
            case 0:
                $msg = "Execução bem sucedida.";
                break;
            case 1:
                $msg = "Timeout da transação.";
                break;
            case 2:
                $msg = "Apitef não inicializada.";
                break;
            case 4:
                $msg = "Erro nos parâmetros/erro de integração.";
                break;
            case 5:
                $msg = "Transação não autorizada.";
                break;
            case 6:
                $msg = "Transação cancelada pelo operador/cliente.";
                break;
            case 9:
                $msg = "Autorizadora offline.";
                break;
            case 10:
                $msg = "Erro de comunicação da Apitef.";
                break;
            case 11:
                $msg = "Erro no CTF.";
                break;
            case 12:
                $msg = "Erro na camada de Intertef da Apitef";
                break;
            case 13:
                $msg = "Transação confirmada, mas ainda existem outras transações a confirmar.";
                break;
            case 15:
                $msg = "Erro de formatação comprovante.";
                break;
            case 18:
                $msg = "Transação desfeita.";
                break;
            case 19:
                $msg = "Documento inexistente para cancelar.";
                break;
            case 20:
                $msg = "Dados inválidos da integração.";
                break;
            case 21:
                $msg = "Não há transações para consolidar.";
                break;
            case 22:
                $msg = "Não há comprovantes para imprimir.";
                break;
            case 25:
                $msg = "Erro interno do CTFClient.";
                break;
            case 26:
                $msg = "Erro retornado pelo pinpad.";
                break;
            case 27:
                $msg = "Erro de integração.";
                break;
            case 50:
                $msg = "Biblioteca de Automação Comercial não foi inicializada.";
                break;
            case 51:
                $msg = "Erro de alocação de memória.";
                break;
            case 53:
                $msg = "Erro carregando bibliotecas de criptografia.";
                break;
            case 54:
                $msg = "Erro ao estabelecer conexão.";
                break;
            case 55:
                $msg = "Erro ao enviar dados pela conexão.";
                break;
            case 56:
                $msg = "Erro ao ler dados da conexão.";
                break;
            case 57:
                $msg = "Mensagem com formato inválido recebida do CTFClient.";
                break;
            case 58:
                $msg = "Chamada de rotina inválida.";
                break;
            case 59:
                $msg = "Variável de ambiente CTFCLIENT_HOME não está configurada.";
                break;
            case 60:
                $msg = "Erro lendo a porta de conexão com o CTFClient do arquivo '%CTFLIENT_HOME%/Bin/configCTFClient.xml'.";
                break;
            case 61:
                $msg = "Erro configurando CTFClient através da operação '005' – não foi possível inicializar a biblioteca.";
                break;
            case 98:
                $msg = "Erro interno desconhecido.";
                break;
            case 555:
                $msg = "Operação cancelada.";
                break;
            default:
                $msg = "Erro desconhecido";
                break;
        }

        return $msg;
    }

    // Essas mensagens de erros são apresentadas toda vez que o p_sc do 7000 for 4 || 20
    public function mensagemErro7300($erro) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $erro = (int)$erro;

        switch ($erro) {
            case 5105:
                $msg = "Erro comunicando-se com o CTFClient";
                break;
            case 5106:
                $msg = "Erro no conteúdo do arquivo de configuração CONFIG.INI";
                break;
            case 5300:
                $msg = "Valor não informado";
                break;
            case 5301:
                $msg = "Cartão inválido";
                break;
            case 5302:
                $msg = "Cartão vencido";
                break;
            case 5306:
                $msg = "Operação não permitida";
                break;
            case 5307:
                $msg = "Dados inválidos";
                break;
            case 5308:
                $msg = "Valor mínimo da parcela";
                break;
            case 5309:
                $msg = "Número de parcelas inválido";
                break;
            case 5310:
                $msg = "Número de parcelas excede limite";
                break;
            case 5311:
                $msg = "Valor da entrada maior ou igual ao valor da transação";
                break;
            case 5312:
                $msg = "Valor da parcela inválido";
                break;
            case 5313:
                $msg = "Data inválida";
                break;
            case 5314:
                $msg = "Prazo excede limite";
                break;
            case 5315:
                $msg = "Transação inválida para o tipo de garantia";
                break;
            case 5316:
                $msg = "NSU inválido";
                break;
            case 5317:
                $msg = "Operação cancelada pelo usuário";
                break;
            case 5318:
                $msg = "Documento inválido (CPF ou CNPJ)";
                break;
            case 5319:
                $msg = "Valor do documento inválido";
                break;
            case 5323:
                $msg = "Número da transação inválido";
                break;
            case 5327:
                $msg = "Pinpad desconectado";
                break;
            case 5328:
                $msg = "Erro na captura de dados do Pinpad";
                break;
            case 5329:
                $msg = "Erro na captura de dados do CHIP";
                break;
            case 5330:
                $msg = "Fluxo não encontrado";
                break;
            case 5331:
                $msg = "Erro de processamento do CTFClient";
                break;
            default:
                $msg = "Erro desconhecido($erro)";
                break;
        }
        
        return $msg;
    }
    
    public function iniciarTransacao($params) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        try {
            // verifica se existe o arquivo pendente no sistema
            if (file_exists(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json")) {
                $tmp = json_decode(file_get_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json"));
                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", Leu arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($tmp));
            }

            if ($tmp->Auttar->confirmaOperacao == 1)
                $this->confirmaTransacao();
            else if ($tmp->Auttar->confirmaOperacao == 2)
                $this->cancelaTransacao(!!"ocorrencia");

        } catch (Exception $q) {
        }
        try {
            if (is_array($params)) {
                $params = (object)$params;
            }
            if (is_array($params->opcoes)) {
                $params->opcoes = (object)$params->opcoes;
            }
            $this->opcoes = $params->opcoes;
            parent::iniciarTransacao($params);
            $this->checkValor($params);
            $this->checkDoc($params);
            $this->checkTrans($params);

            $o = $this->cfgPagamento();
            $this->checkPDV($o);

            $this->configurarTEF($o);

            if ($params) {
                $o = array(
                    "valor"           => $params->valor_raw,
                    "documento"       => $params->documento,
                    "operacao"        => $params->operacao,
                    "opcoes"          => $params->opcoes,
                    "finalizado"      => false,
                    "situacao"        => 'PENDENTE',
                    "retorno"         => false,
                    "erro"            => false,
                    "configuracaoTEF" => $this->fConfiguracaoTEF
                );

                @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . "_TransacaoApp.json", json_encode($o));
                exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . __DIR__ . DIRECTORY_SEPARATOR . "_TransacaoApp.json com o conteudo: " . json_encode($o));
            }

            //Reset de variaveis.
            $this->OperacaoRealizadaSucesso7000 = null;
            $this->OperacaoRealizadaSucesso7300 = null;

            $this->valor_raw    = $params->valor_raw;
            $this->documento    = $params->documento;
            $this->operacao     = $params->operacao;
            $this->qtd_parcelas = isset($params->opcoes->Configura_QtdParcelas) ? $params->opcoes->Configura_QtdParcelas : $this->opcoes->QtdParcelas;
            $this->foto('INICIAL');

            //Definição do timeout
            $this->timeoutDefaultOperacao = (int)$this->fConfiguracaoTEF->timeoutDefaultOperacao;
            if (!$this->timeoutDefaultOperacao) {
                $this->timeoutDefaultOperacao = 30;
            }

            if (file_exists(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json")) {
                $tmp = json_decode(file_get_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json"));
                teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", Leu arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($tmp));

                // 0 = deixar como pendente
                // 1 = confirmar a transacao
                // 2 = cancelar a transacao
                if ($tmp->Auttar->confirmaOperacao == 0) {
                    // força transação de inicio do dia
                    $this->iniciaTransacaoCTF(225, $params->valor_raw, substr($params->documento, 0, 20), $params->transacao);
                    $this->comando = "0";
                    $this->valor   = "0";
                    $this->forcar  = false;
                    $this->continuarTransacaoCTF($this->comando, $this->valor);
                    $this->finalizaTransacaoCTF(true,"1");

                    sleep(1);

                    teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", executou transacao inicio dia");

                    unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
                    teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
                }
            }

            $this->iniciaTransacaoCTF($params->operacao, $params->valor_raw, substr($params->documento, 0, 20), $params->transacao);

            $this->comando = "0";
            $this->valor   = "0";
            $this->forcar  = false;
            $this->loopTransacao();
        } catch (EAbort $e) {
            //
        } catch (Exception $e) {
            throw $e;
        }
        return true;
    }
    /**
     * Deve ser chamado quando o $tipoOperacao===3
     * @return boolean
     * @throws Exception
     */
    public function confirmaTransacao() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        parent::confirmaTransacao();
        if (!file_exists(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json")) {
            throw new Exception('Nenhuma transação pendente');
        }

        $o = json_decode(file_get_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json"));
        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", Leu arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($o));

        // atualiza intenção como confirmar o pagamento
        // 0 = deixar como pendente
        // 1 = confirmar a transacao
        // 2 = cancelar a transacao
        $o->Auttar->confirmaOperacao = 1;
        if (!@file_put_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json", json_encode($o))) {
            throw new Exception("Não foi possível armazenar intenção da transação");
        }
        exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($o));

        $this->configurarTEF($o);
        $this->finalizaTransacaoCTF(true,"1");
        $this->atualizaInfoPagamento('APROVADA');

        unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
        return true;
    }
    /**
     * Deve ser chamado quando o $tipoOperacao===3
     * @param boolean $ocorrencia Se salva ocorrencia
     * @return boolean
     * @throws Exception
     */
    public function cancelaTransacao($ocorrencia = false) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        parent::cancelaTransacao();
        if (!file_exists(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json")) {
            throw new Exception('Nenhuma transação pendente');
        }

        $o = json_decode(file_get_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json"));
        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", Leu arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($o));

        // atualiza intenção como cancelar o pagamento
        // 0 = deixar como pendente
        // 1 = confirmar a transacao
        // 2 = cancelar a transacao
        $o->Auttar->confirmaOperacao = 2;
        if (!@file_put_contents(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json", json_encode($o))) {
            throw new Exception("Não foi possível armazenar intenção da transação");
        }
        exec("sudo sync");//Garante que o arquivo esta no disco e não em cache...

        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", gravou arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json com o conteudo: " . json_encode($o));

        if ($ocorrencia) {
            Realtime::registraOcorrencia(
                "135",
                "3",
                now(),
                "Cancelamento pagamento. Doc.: {$o->Auttar->documento}",
                "00000000"
            );
            Realtime::registraOcorrencia(
                "135",
                "1",
                now(),
                "Cancelamento pagamento. Doc.: {$o->Auttar->documento}",
                "00000000"
            );
        }

        $this->configurarTEF($o);
        $this->finalizaTransacaoCTF(false,"1");
        $this->atualizaInfoPagamento('CANCELADA');

        unlink(TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
        teflog("[TEF] metodo " . __METHOD__ . "(), linha: " . __LINE__ . ", apagou o arquivo " . TEF_PATH_ULTIMA_OPERACAO_INICIADA . "auttar.json");
        return true;
    }
    public function configurarTEF($aobj = NULL) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        parent::configurarTEF($aobj);
        if ($aobj) {
            $o = $aobj;
        } else {
            $o = $this->cfgPagamento();
        }
        if ((string)$o->Auttar->pdv === "999") {
            throw new Exception("Código do PDV precisa ser configurado. Atual = 999");
        }

        self::verificaLibs($o);

        $this->fConfiguracaoTEF = $o->Auttar;
        unset($this->fConfiguracaoTEF->formasPagamento);
        $this->iniciaClientCTF(
            (string)$o->Auttar->estabelecimento,            // Código do estabelecimento  (Max 5 numérico)
            (string)$o->Auttar->loja,                        // Código da Loja (Max 4 numérico)
            (string)$o->Auttar->pdv,                        // Código do PDV  (Max 3 numérico)
            "v1.0",                        // Versão da aplicação (MAX 10 Alfa)
            "IPYJavaAuttar",            // Nome Aplicação (MAX 20 Alfa)
            (string)$o->Auttar->servidores,                // Lista de Servidores. Ex: IP:Porta:Protocolo;IP:Porta:Protocolo
            (string)$o->Auttar->usaCriptografia,            // Usa Criptografia. 0 (False) 1 True
            (string)$o->Auttar->CTFGeraLog,                // CTFClient Gera log. 0 (False) 1 True
            (string)$o->Auttar->ParametrosConfAuttar        // Parâmetros de configuração Auttar. Ex sem parâmetros: []
        );
    }
    public function cfgPagamento() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $o = parent::cfgPagamento();

        if (empty($o->Auttar)) {
            $o->Auttar = new \stdClass();
        }

        $o->Auttar->estabelecimento = $this->opcoes->Configura_estabelecimento;
        $o->Auttar->loja            = $this->opcoes->Configura_loja;
        $o->Auttar->pdv             = str_pad($this->opcoes->Configura_pdv, 3, '0', STR_PAD_LEFT);
        $o->Auttar->servidores      = $this->opcoes->Configura_servidores;
        //$o->Auttar->usaCriptografia = $this->opcoes->Configura_usaCriptografia;
        //$o->Auttar->CTFGeraLog = $this->opcoes->Configura_CTFGeraLog;

        // esta flag é usada para decidir a intenção do TEF caso haja uma falha de
        // energia ou queda de link / conectividade
        // 0 = deixar como pendente
        // 1 = confirmar a transacao
        // 2 = cancelar a transacao
        $o->Auttar->confirmaOperacao = 0;

        $o->Auttar->usaCriptografia        = 0;
        $o->Auttar->CTFGeraLog             = 1;
        $o->Auttar->ParametrosConfAuttar   = $this->opcoes->Configura_ParametrosConfAuttar ?: $this->opcoes->Configura_ParametrosAdicionais;
        $o->Auttar->valorMinimo            = $this->opcoes->Configura_valorMinimo;
        $o->Auttar->valorMaximo            = $this->opcoes->Configura_valorMaximo;
        $o->Auttar->formasPagamento        = $this->opcoes->Configura_formasPagamento;
        $o->Auttar->tipoOperacao           = $this->opcoes->Configura_tipoOperacao;
        $o->Auttar->timeoutDefaultOperacao = $this->opcoes->Configura_timeoutDefaultOperacao;

        return $o;
    }

    private function checkPDV() {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $o = $this->cfgPagamento();

        if (empty($o->Auttar->pdv)) {
            $message = "PDV não configurado no TEF.";
            $this->wsCallback_tefNegado(-11001, $message);
            throw new Exception($message);
        }

    }

    static public function verificaLibs($opt) {
        teflog("[TEF] Executou o metodo " . __METHOD__ . "(), linha: " . __LINE__);
        $libs['producao'] = [
            [
                'libctfclient.so' => [
                    'e927bdda4e0ccce8720930673a105ebc',  // lib antiga, <= 2014
                    'b3db72a8249e2e91e801ca3974b01e49',  // CTFClient-3.3.1-1.i386
                    'dd6391609ea5deec037e3bf37d015669'   // ctfclient-4.3.2-5I-linux-portable-x86_64 
                ]
            ]
        ];

        // Auttar usa gateway TEF de homologação
        $addrs['homologacao'] = ['177.43.188.234:1996:TCP', '201.87.167.97:1996:TCP'];
        $addrs['producao']    = ['191.232.32.67:1996:TCP'];

        // so mostra a mensagem caso o gateway usado seja homologacao
        if (in_array($opt->Auttar->servidores, $addrs['homologacao'])) {
            parent::terminalHomologacao();
            return;
        }

        // teste das libs produção
        foreach ($libs['producao'] as $lib) {
            $k = key($lib);
            foreach ($lib[$k] as $checksum) {
                if (md5_file('/lib/' . $k) == $checksum) {
                    // termina execução caso bater o checksum
                    return;
                }
            }
        }
        
        // envia o WS caso a lib nao homologada para uso
        parent::terminalFalhaHomologacao();
    }

    static public function getCamposNecessariosGateway() {
        return [
            'estabelecimento',
            'loja',
            'servidores',
            'criptografia',
            'log',
            'configuracoes',
            'valor_minimo',
            'valor_maximo',
            'meios_pagamento_tef',
            'modo_operacao',
            'timeout_operacao'
        ];
    }

    static public function getCamposNecessariosFormaPagamento() {
        return [
            'tarifa',
            'numero_parcelas',
            'juros',
            'tef_codigo_operacao',
            'tef_codigo_oper_canc',
            'css',
            'tpl_comprovante'
        ];
    }

}
