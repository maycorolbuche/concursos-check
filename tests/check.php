<?php

declare(strict_types=1);

require __DIR__ . '/../index.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$html = <<<'HTML'
<div id="concursos">
  <div class="ua" id="NACIONAL"></div>
  <div data-url="https://www.pciconcursos.com.br/noticias/nacional">
    <a>Concurso Nacional</a><div class="cc">&nbsp;</div>
    <div class="cd"><span>Analista<br>Superior</span> - 12 vagas - R$ 1.234,56</div>
    <div class="ce">Até <span>12/12/2026</span></div>
  </div>
  <div class="ua" id="SP"></div>
  <div data-url="https://www.pciconcursos.com.br/noticias/municipal">
    <a>Prefeitura &amp; Câmara</a><div class="cc">SP</div>
    <div class="cd"><span>Motorista</span><span>Médio</span></div>
  </div>
</div>
HTML;

$items = parseListing($html);
$nacional = $items['/noticias/nacional'];
$sp = $items['/noticias/municipal'];
expect(count($items) === 2 && route($nacional) === 'NACIONAL' && route($sp) === 'SP', 'Classificação de destino');
expect($nacional['vagas'] === '12' && $nacional['salario'] === 'R$ 1.234,56', 'Extração de vagas e salário');
expect($nacional['cargo'] === 'Analista' && $nacional['nivel'] === 'Superior', 'Extração de cargo e nível');
expect($nacional['data'] === '12/12/2026' && $sp['nivel'] === 'Médio', 'Extração do prazo e nível');

putenv('TELEGRAM_BOT_TOKEN=geral');
putenv('TELEGRAM_CHAT_ID=-1');
putenv('TELEGRAM_BOT_TOKEN_SP=estado');
putenv('TELEGRAM_CHAT_ID_SP');
expect(credentials('SP') === ['geral', '-1'], 'Par regional incompleto deve usar o geral');
putenv('TELEGRAM_CHAT_ID_SP=-2');
expect(credentials('SP') === ['estado', '-2'] && credentials('NACIONAL') === ['geral', '-1'], 'Destinos por tipo');
putenv('TELEGRAM_BOT_TOKEN_BR=nacional');
putenv('TELEGRAM_CHAT_ID_BR');
expect(credentials('NACIONAL') === ['geral', '-1'], 'Par BR incompleto deve usar o geral');
putenv('TELEGRAM_CHAT_ID_BR=-3');
expect(credentials('NACIONAL') === ['nacional', '-3'], 'Rota nacional deve usar os secrets BR');

$updated = parseListing(str_replace('Até <span>', 'Prorrogado até <span>', $html));
$messages = changes($items, $updated);
expect(count($messages) === 1 && str_contains($messages[0]['message'], '⚠️ Prorrogado!'), 'Prorrogação deve gerar aviso');
$updated = $items;
$updated['/noticias/municipal']['cadastro_reserva'] = true;
$messages = changes($items, $updated);
expect(count($messages) === 1 && str_contains($messages[0]['message'], '⚠️ CADASTRO RESERVA ⚠️'), 'Cadastro reserva deve gerar aviso');
$messages = changes($items, ['/noticias/nacional' => $nacional]);
expect(count($messages) === 1 && $messages[0]['route'] === 'ALL' && str_contains($messages[0]['message'], '&amp;'), 'Remoção e escape de HTML');
expect(notification('removido', $nacional)['route'] === 'ALL', 'Remoção nacional deve ir para o geral');
expect(credentials(queuedRoute(array_replace($messages[0], ['route' => 'SP']))) === ['geral', '-1'], 'Remoção já enfileirada deve ir para o geral');
expect(queuedRoute(notification('novo', $sp)) === 'SP', 'Concurso novo mantém o destino regional');
expect(changes($items, $items) === [], 'Repetição não gera avisos');

$expectedNew = <<<'MESSAGE'
═════════════════
<b>🆕 NOVO CONCURSO</b>
═════════════════

📌 Prefeitura &amp; Câmara

💼 Cargo: Motorista
🎓 Nível: Médio
📍 Local: SP
🔗 Link: <a href="https://www.pciconcursos.com.br/noticias/municipal">https://www.pciconcursos.com.br/noticias/municipal</a>
MESSAGE;
expect(notification('novo', $sp)['message'] === $expectedNew, 'Layout legado de concurso novo');
expect(notification('removido', $sp)['message'] === str_replace(
    '🆕 NOVO CONCURSO', '❌ CONCURSO REMOVIDO', $expectedNew
), 'Layout legado de concurso removido');

$relocated = $sp;
$relocated['url'] = 'https://www.pciconcursos.com.br/noticias/municipal-prorrogado';
$relocated['data'] = '05/11/2026';
$relocated['data_prorrogada'] = true;
$relocated['cadastro_reserva'] = true;
$messages = changes($items, ['/noticias/nacional' => $nacional, '/noticias/municipal-prorrogado' => $relocated]);
$expectedUpdated = <<<'MESSAGE'
═════════════════
<b>📝 CONCURSO ATUALIZADO</b>
═════════════════

📌 Prefeitura &amp; Câmara

⚠️ CADASTRO RESERVA ⚠️
💼 Cargo: Motorista
🎓 Nível: Médio
📍 Local: SP
⚠️ Data limite: 05/11/2026
⚠️ Prorrogado!
🔗 Link: <s><a href="https://www.pciconcursos.com.br/noticias/municipal">https://www.pciconcursos.com.br/noticias/municipal</a></s> → <a href="https://www.pciconcursos.com.br/noticias/municipal-prorrogado">https://www.pciconcursos.com.br/noticias/municipal-prorrogado</a>
MESSAGE;
expect(count($messages) === 1 && $messages[0]['message'] === $expectedUpdated, 'Layout legado com troca de link');

$second = $relocated;
$second['url'] = 'https://www.pciconcursos.com.br/noticias/municipal-alternativo';
$messages = changes($items, [
    '/noticias/nacional' => $nacional,
    '/noticias/municipal-prorrogado' => $relocated,
    '/noticias/municipal-alternativo' => $second,
]);
expect(count($messages) === 3, 'Troca ambígua de link mantém os anúncios independentes');

try {
    parseListing('<div id="concursos"></div>');
    throw new LogicException('Listagem vazia deveria falhar.');
} catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'sem concursos'), 'Falha de listagem vazia');
}

echo "Parser, comparação e roteamento validados.\n";
