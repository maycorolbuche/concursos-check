<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";

class WebScraper
{
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    public function getContent($url, $timeout = 30)
    {
        // Valida URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception("URL inválida: " . $url);
        }

        $ch = curl_init();

        $config = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false, // Para HTTPS
            CURLOPT_ENCODING => '', // Aceita compressão
            CURLOPT_MAXREDIRS => 10 // Máximo de redirecionamentos
        ];

        curl_setopt_array($ch, $config);

        $content = curl_exec($ch);
        $info = curl_getinfo($ch);
        $erro = curl_error($ch);

        curl_close($ch);

        if ($content === false) {
            throw new Exception("Erro cURL: " . $erro);
        }

        if ($info['http_code'] != 200) {
            throw new Exception("HTTP Error: " . $info['http_code']);
        }

        return $content;
    }

    public function text($html)
    {
        // Remove tags HTML
        $texto = strip_tags($html);
        // Remove espaços extras
        $texto = preg_replace('/\s+/', ' ', $texto);
        // Remove espaços no início e fim
        $texto = trim($texto);

        return $texto;
    }

    // Novo método para extrair conteúdo de uma div específica
    public function extractDivContent($html, $divId)
    {
        // Cria objeto DOM
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Ignora erros de HTML mal formatado
        $dom->loadHTML($html);
        libxml_clear_errors();

        // Cria objeto XPath
        $xpath = new DOMXPath($dom);

        // Busca a div pelo ID
        $query = "//div[@id='$divId']";
        $elements = $xpath->query($query);

        if ($elements->length > 0) {
            // Retorna o HTML interno da div encontrada
            $divContent = '';
            foreach ($elements as $element) {
                $divContent .= $dom->saveHTML($element);
            }
            return $divContent;
        }

        return null; // Retorna null se não encontrar a div
    }

    // Método para extrair apenas o texto da div específica
    public function extractDivText($html, $divId)
    {
        $divContent = $this->extractDivContent($html, $divId);
        if ($divContent) {
            return $this->text($divContent);
        }
        return null;
    }
}

function salvarErro($mensagemErro, $detalhes = [])
{
    $erro = [
        'timestamp' => date('Y-m-d H:i:s'),
        'mensagem' => $mensagemErro,
        'detalhes' => $detalhes,
        'ultimo_sucesso' => file_exists('concursos.json') ? date('Y-m-d H:i:s', filemtime('concursos.json')) : null
    ];

    // Sempre sobrescreve o arquivo de erro
    file_put_contents('erro.json', json_encode($erro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Também salva em log simples para debug rápido (opcional)
    $logSimples = date('Y-m-d H:i:s') . " - ERRO: " . $mensagemErro . PHP_EOL;
    file_put_contents('ultimo_erro.log', $logSimples);
}


function toSlug($title)
{
    // Converte para minúsculas
    $slug = strtolower($title);

    // Remove acentos (importante para URLs)
    $slug = preg_replace('/[áàâãä]/u', 'a', $slug);
    $slug = preg_replace('/[éèêë]/u', 'e', $slug);
    $slug = preg_replace('/[íìîï]/u', 'i', $slug);
    $slug = preg_replace('/[óòôõö]/u', 'o', $slug);
    $slug = preg_replace('/[úùûü]/u', 'u', $slug);
    $slug = preg_replace('/[ç]/u', 'c', $slug);
    $slug = preg_replace('/[ñ]/u', 'n', $slug);

    // Remove caracteres especiais e mantém apenas letras, números e espaços
    $slug = preg_replace('/[^a-z0-9-\s]/', '', $slug);

    // Substitui espaços por hífen
    $slug = preg_replace('/\s+/', '-', $slug);

    // Remove hífens duplicados
    $slug = preg_replace('/-+/', '-', $slug);

    // Remove hífens no início e no final
    $slug = trim($slug, '-');

    return $slug;
}



// Função para carregar dados anteriores
function carregarDadosAnteriores($arquivo = 'concursos.json')
{
    if (file_exists($arquivo)) {
        $conteudo = file_get_contents($arquivo);
        return json_decode($conteudo, true);
    }
    return null;
}

// Função para comparar dois itens e verificar se houve modificação
function itemModificado($itemAntigo, $itemNovo)
{
    // Remove o campo 'html' da comparação (opcional, se não quiser comparar o HTML)
    unset($itemAntigo['html']);
    unset($itemNovo['html']);

    // Remove o campo 'status' se existir
    unset($itemAntigo['status']);
    unset($itemNovo['status']);

    return $itemAntigo != $itemNovo;
}

// Função para gerar o arquivo de modificações
function gerarModificacoes($dadosNovos, $dadosAntigos)
{
    $modificacoes = [];

    if (!$dadosAntigos) {
        // Primeira execução: todos os itens são novos
        foreach ($dadosNovos as $regiao => $concursos) {
            foreach ($concursos as $slug => $concurso) {
                $concurso['status'] = 'novo';
                $modificacoes[$regiao][$slug] = $concurso;
            }
        }
        return $modificacoes;
    }

    // Verifica itens novos e modificados
    foreach ($dadosNovos as $regiao => $concursos) {
        foreach ($concursos as $slug => $concurso) {
            if (!isset($dadosAntigos[$regiao][$slug])) {
                // Item novo
                $concurso['status'] = 'novo';
                $modificacoes[$regiao][$slug] = $concurso;
            } elseif (itemModificado($dadosAntigos[$regiao][$slug], $concurso)) {
                // Item modificado
                $concurso['status'] = 'modificado';
                $concurso['anterior'] = $dadosAntigos[$regiao][$slug]; // Opcional: guarda versão anterior
                $modificacoes[$regiao][$slug] = $concurso;
            }
        }
    }

    // Verifica itens removidos
    foreach ($dadosAntigos as $regiao => $concursos) {
        foreach ($concursos as $slug => $concurso) {
            if (!isset($dadosNovos[$regiao][$slug])) {
                // Item removido
                $concurso['status'] = 'removido';
                $modificacoes[$regiao][$slug] = $concurso;
            }
        }
    }

    return $modificacoes;
}

function t($old, $new)
{
    return $old !== null && $old != $new ? "<s>{$old}</s> → {$new}" : $new;
}

function processaModificacoes($modificacoes)
{

    $env = [];
    if (file_exists("env.json")) {
        $conteudo = file_get_contents("env.json");
        $env = json_decode($conteudo, true);
    }
    $chatIDAll = $env['telegram']['all']['chat_id'] ?? null;
    $botTokenAll = $env['telegram']['all']['bot_token'] ?? null;

    foreach ($modificacoes as $uf => $data) {

        $chatID = $env['telegram'][strtolower($uf)]['chat_id'] ?? null;
        $botToken = $env['telegram'][strtolower($uf)]['bot_token'] ?? null;

        foreach ($data as $item) {
            if (
                $item["status"] == "modificado"
                && $item['anterior']['titulo'] == $item['titulo']
                && $item['anterior']['cargo'] == $item['cargo']
                && $item['anterior']['vagas'] == $item['vagas']
                && $item['anterior']['salario'] == $item['salario']
                && $item['anterior']['nivel'] == $item['nivel']
                && $item['anterior']['local'] == $item['local']
                && $item['anterior']['data'] == $item['data']
                && $item['anterior']['url'] == $item['url']
            ) {
                continue;
            }

            $mensagem = "";
            $mensagem .= "═════════════════" . PHP_EOL;
            $mensagem .= "<b>"
                . (
                    $item["status"] == "novo"
                    ? "🆕 NOVO CONCURSO"
                    : (
                        $item["status"] == "modificado"
                        ? "📝 CONCURSO ATUALIZADO"
                        : "❌ CONCURSO REMOVIDO"
                    )
                )
                . "</b>" . PHP_EOL;
            $mensagem .= "═════════════════" . PHP_EOL;
            $mensagem .= PHP_EOL;
            $mensagem .= "📌 " . t($item['anterior']['titulo'] ?? null, $item['titulo']) . PHP_EOL . PHP_EOL;

            if ($item['cadastro_reserva']) {
                $mensagem .= "⚠️ CADASTRO RESERVA ⚠️" . PHP_EOL;
            }

            if (!empty($item['cargo'])) {
                $mensagem .= "💼 Cargo: " . t($item['anterior']['cargo'] ?? null, $item['cargo']) . PHP_EOL;
            }

            if (!empty($item['vagas'])) {
                $mensagem .= "📊 Vagas: " . t($item['anterior']['vagas'] ?? null, $item['vagas']) . PHP_EOL;
            }

            if (!empty($item['salario'])) {
                $mensagem .= "💰 Salário: " . t($item['anterior']['salario'] ?? null, $item['salario']) . PHP_EOL;
            }

            if (!empty($item['nivel'])) {
                $mensagem .= "🎓 Nível: " . t($item['anterior']['nivel'] ?? null, $item['nivel']) . PHP_EOL;
            }

            if (!empty($uf) && trim($uf) != '' && $uf != ' ') {
                $mensagem .= "📍 Local: " . t($item['anterior']['local'] ?? null, $uf) . PHP_EOL;
            }

            $dataIcon = $item['data_prorrogada'] ? "⚠️" : "📅";
            if (!empty($item['data'])) {
                $mensagem .= "{$dataIcon} Data limite: " . t($item['anterior']['data'] ?? null, $item['data']) . PHP_EOL;
                if ($item['data_prorrogada']) {
                    $mensagem .= "⚠️ Prorrogado!" . PHP_EOL;
                }
            }

            $mensagem .= "🔗 Link: " . t($item['anterior']['url'] ?? null, $item['url']) . PHP_EOL;


            if ($chatID && $botToken && ($item["status"] == "novo" || $item["status"] == "modificado")) {
                sendQueueTelegramMessage($mensagem, $chatID, $botToken);
            } elseif ($chatIDAll && $botTokenAll) {
                sendQueueTelegramMessage($mensagem, $chatIDAll, $botTokenAll);
            }
        }
    }
}

function sendQueueTelegramMessage($mensagem, $chatID,  $botToken)
{
    $arquivoFila = 'telegram_queue.json';

    // Carrega fila existente
    $fila = [];
    if (file_exists($arquivoFila)) {
        $conteudo = file_get_contents($arquivoFila);
        $fila = json_decode($conteudo, true) ?? [];
    }

    // Adiciona nova mensagem à fila
    $fila[] = [
        'id' => uniqid('msg_', true),
        'timestamp' => date('Y-m-d H:i:s'),
        'chat_id' => $chatID,
        'bot_token' => $botToken,
        'mensagem' => $mensagem,
        'status' => 'pendente',
        'tentativas' => 0
    ];

    // Salva fila atualizada
    file_put_contents($arquivoFila, json_encode($fila, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "📝 Mensagem adicionada à fila. Total na fila: " . count($fila) . "\n";
}

function processQueueTelegramMessage()
{
    $arquivoFila = 'telegram_queue.json';
    $timeoutSegundos = 300;

    $inicio = time();
    $mensagensProcessadas = 0;
    $mensagensSucesso = 0;
    $mensagensFalha = 0;

    echo "🚀 Iniciando processamento da fila com timeout de {$timeoutSegundos} segundos...\n";
    echo str_repeat("=", 60) . "\n";

    while (true) {
        // Verifica timeout
        $tempoDecorrido = time() - $inicio;
        if ($tempoDecorrido > $timeoutSegundos) {
            echo "\n⏰ Timeout de {$timeoutSegundos} segundos atingido. Encerrando...\n";
            echo "Tempo decorrido: {$tempoDecorrido} segundos\n";
            break;
        }

        // Carrega a fila
        if (!file_exists($arquivoFila)) {
            echo "📭 Arquivo de fila não encontrado. Nenhuma mensagem para processar.\n";
            break;
        }

        $conteudo = file_get_contents($arquivoFila);
        $fila = json_decode($conteudo, true) ?? [];

        // Se não há mensagens na fila, encerra
        if (empty($fila)) {
            echo "📭 Fila vazia. Nenhuma mensagem pendente.\n";
            break;
        }

        // Filtra apenas mensagens pendentes
        $pendentes = array_filter($fila, fn($item) => $item['status'] === 'pendente');

        if (empty($pendentes)) {
            echo "📭 Nenhuma mensagem pendente na fila.\n";
            break;
        }

        // Pega a primeira mensagem pendente
        $primeiraMsg = reset($pendentes);
        $indice = array_search($primeiraMsg, $fila);

        $mensagensProcessadas++;
        $tempoRestante = $timeoutSegundos - $tempoDecorrido;

        echo "\n📤 [" . $mensagensProcessadas . "] Processando mensagem:\n";
        echo "   ID: {$primeiraMsg['id']}\n";
        echo "   Chat: {$primeiraMsg['chat_id']}\n";
        echo "   Tentativas: {$primeiraMsg['tentativas']}/3\n";
        echo "   Tempo restante: " . gmdate("i:s", $tempoRestante) . "\n";

        // Tenta enviar
        $erro = sendTelegramMessage(
            $primeiraMsg['mensagem'],
            $primeiraMsg['chat_id'],
            $primeiraMsg['bot_token']
        );

        // Atualiza ou remove baseado no resultado
        if (empty($erro)) {
            // Enviado com sucesso - REMOVE da fila
            unset($fila[$indice]);
            $mensagensSucesso++;
            echo "   ✅ Mensagem enviada com sucesso! Removida da fila.\n";
        } else {
            // Falha no envio
            $fila[$indice]['tentativas']++;
            $fila[$indice]['ultimo_erro'] = $erro;
            $fila[$indice]['ultima_tentativa'] = date('Y-m-d H:i:s');

            if ($fila[$indice]['tentativas'] >= 3) {
                // Falha definitiva - REMOVE da fila também
                unset($fila[$indice]);
                $mensagensFalha++;
                echo "   ❌ Falha definitiva após 3 tentativas. Removida da fila.\n";
                echo "   Erro: " . substr($erro, 0, 100) . "...\n";
            } else {
                // Mantém na fila para nova tentativa
                echo "   ⚠️ Falha (tentativa {$fila[$indice]['tentativas']}/3). Mantida na fila.\n";
                echo "   Erro: " . substr($erro, 0, 100) . "...\n";
            }
        }

        // Reindexa o array (opcional, para manter índices limpos)
        $fila = array_values($fila);

        // Salva fila atualizada
        file_put_contents($arquivoFila, json_encode($fila, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Pequeno delay entre mensagens para não sobrecarregar a API
        if (count($fila) > 0) {
            usleep(500000); // 0.5 segundos
        }
    }
}



function sendTelegramMessage($mensagem, $chatID,  $botToken)
{
    // URL da API do Telegram para enviar mensagens
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

    // Dados que serão enviados via POST
    $dados = [
        'chat_id' => $chatID,
        'text' => $mensagem,
        'parse_mode' => 'HTML',
    ];

    echo "<p><pre>$mensagem</pre><p>";

    // Inicializa o cURL
    $ch = curl_init();

    // Configura as opções do cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $dados);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Executa a requisição e obtém a resposta
    $resposta = curl_exec($ch);

    // Verifica se houve erro
    $ret = "";
    if ($resposta === false) {
        $ret = curl_error($ch);
    }

    // Fecha a conexão cURL
    curl_close($ch);

    return $ret;
}




$data = [];

try {
    $scraper = new WebScraper();
    $html = $scraper->getContent('https://www.pciconcursos.com.br/concursos/');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $divContent = $xpath->query("//div[@id='concursos']");

    if ($divContent->length > 0) {
        $content = $divContent->item(0);

        $items = $xpath->query(".//div", $content);
        $lid = null;
        foreach ($items as $index => $div) {

            $id = $div->getAttribute('id');
            if ($id) {
                $lid = $id;
            };

            if ($lid) {
                if (!isset($data[$lid])) {
                    $data[$lid] = [];
                }

                $url = $div->getAttribute('data-url');
                if ($url) {
                    $title =  $xpath->query(".//a", $div)->item(0)->textContent ?? null;
                    $slug = toSlug($title);

                    $image =  $xpath->query(".//img", $div)->item(0)->getAttribute('data-src') ?? null;


                    $detail = [
                        'vagas' => null,
                        'salario' => null,
                        'cadastro_reserva' => false,
                        'cargo' => null,
                        'nivel' => null,
                        'uf' => null,
                        'data' => null,
                        'data_prorrogada' => false,
                        'data_raw' => null
                    ];

                    // Extrai CD (vagas, salário, cargo, nível)
                    $cdDiv = $xpath->query(".//div[@class='cd']", $div);
                    if ($cdDiv->length > 0) {
                        $textoCD = $cdDiv->item(0)->textContent;

                        // Tenta extrair número de vagas
                        if (preg_match('/(\d+(?:\.\d+)?)\s*vagas?/i', $textoCD, $match)) {
                            $detail['vagas'] = $match[1];
                        }

                        // Tenta extrair salário
                        if (preg_match('/R\$[\s]*([\d\.]+,\d{2})/', $textoCD, $match)) {
                            $detail['salario'] = 'R$ ' . $match[1];
                        }

                        // Tenta extrair informação de cadastro reserva
                        if (preg_match('/cadastro\s+(?:de\s+)?reserva/i', $textoCD)) {
                            $detail['cadastro_reserva'] = true;
                        }

                        // Extrai spans para cargo e nível
                        $spans = $xpath->query(".//span", $cdDiv->item(0));
                        if ($spans->length > 0) {
                            $primeiroSpan = $spans->item(0);
                            $htmlSpan = $dom->saveHTML($primeiroSpan);

                            // Verifica se tem <br> no span
                            if (strpos($htmlSpan, '<br') !== false) {
                                // Separa pelo <br>
                                $partes = preg_split('/<br\s*\/?>/i', $htmlSpan);
                                $detail['cargo'] = trim(strip_tags($partes[0]));
                                if (isset($partes[1])) {
                                    $detail['nivel'] = trim(strip_tags($partes[1]));
                                }
                            } else {
                                $detail['cargo'] = trim($primeiroSpan->textContent);
                            }

                            // Se ainda não tem nível e existe segundo span
                            if (!isset($detail['nivel']) && $spans->length > 1) {
                                $detail['nivel'] = trim($spans->item(1)->textContent);
                            }
                        }
                    }

                    // Extrai CE (data)
                    $ceDiv = $xpath->query(".//div[@class='ce']", $div);
                    if ($ceDiv->length > 0) {
                        $textoCE = trim($ceDiv->item(0)->textContent);
                        $detail['data_raw'] = $textoCE;

                        if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $textoCE, $match)) {
                            $detail['data'] = $match[1];
                        }

                        if (strpos($textoCE, 'Prorrogado') !== false) {
                            $detail['data_prorrogada'] = true;
                        }
                    }

                    // Extrai CC (UF)
                    $ccDiv = $xpath->query(".//div[@class='cc']", $div);
                    if ($ccDiv->length > 0) {
                        $uf = trim($ccDiv->item(0)->textContent);
                        if ($uf && $uf != '') {
                            $detail['uf'] = $uf;
                        }
                    }

                    $title =  $xpath->query(".//a", $div)->item(0)->textContent ?? null;
                    $slug = toSlug($uf . '-' . $title . '-' . $detail['cargo'] . '-' . $detail['nivel']);

                    $data[$lid][$slug] = [
                        'titulo' => $title,
                        'url' => $url,
                        'imagem' => $image,
                        ...$detail,
                        'html' => $dom->saveHTML($div)
                    ];
                }
            }
        }

        // Carrega dados anteriores
        $dadosAntigos = carregarDadosAnteriores('concursos.json');

        // Gera modificações
        $modificacoes = gerarModificacoes($data, $dadosAntigos);
        processaModificacoes($modificacoes);

        // Salva arquivo completo
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents('concursos.json', $json);


        // Se chegou aqui sem erros, remove o arquivo de erro se existir
        if (file_exists('erro.json')) {
            unlink('erro.json');
        }
        if (file_exists('ultimo_erro.log')) {
            unlink('ultimo_erro.log');
        }

        echo "✅ Fim do processamento";
    } else {
        throw new Exception("Div com id 'concursos' não encontrada na página.");
    }
} catch (Exception $e) {
    $mensagemErro = $e->getMessage();
    $detalhesErro = [
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'codigo' => $e->getCode(),
        'trace' => $e->getTraceAsString()
    ];

    // Salva o erro
    salvarErro($mensagemErro, $detalhesErro);

    // Exibe no console
    echo "\n❌ ERRO: " . $mensagemErro . "\n";
    echo "📁 Erro salvo em erro.json\n";
}


processQueueTelegramMessage();

echo "</pre>";
