# Concursos Check

Monitora a listagem do PCI Concursos e envia alterações pelo Telegram.
A rotina usa PHP 8.4 com cURL e DOM e roda pelo GitHub Actions.

## Secrets do GitHub

Em **Settings → Secrets and variables → Actions → Repository secrets**, cadastre
`TELEGRAM_BOT_TOKEN` e `TELEGRAM_CHAT_ID` como destino geral. Para um destino
específico, adicione o par com sufixo `_BR` ou de uma UF, como `_SP`.
O workflow já prevê todas as UFs. Se uma das duas credenciais específicas
estiver vazia, o aviso usa o par geral inteiro. Cada aviso vai a um destino.
Remoções são avisos administrativos e usam sempre o par geral.

## Disparo pela Cloudflare

Crie um Worker com Cron Trigger a cada 15 minutos. Use um token fine-grained
do GitHub limitado ao repositório `concursos-check`, com **Actions: Read and
write**, e salve-o como `GITHUB_DISPATCH_TOKEN` nos Secrets do Worker.

```js
export default {
  async scheduled(_controller, env) {
    if (!env.GITHUB_DISPATCH_TOKEN) {
      throw new Error("Secret GITHUB_DISPATCH_TOKEN ausente.");
    }
    const response = await fetch(
      "https://api.github.com/repos/maycorolbuche/concursos-check/actions/workflows/concursos.yml/dispatches",
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${env.GITHUB_DISPATCH_TOKEN}`,
          Accept: "application/vnd.github+json",
          "Content-Type": "application/json",
          "User-Agent": "concursos-check-cron",
        },
        body: JSON.stringify({ ref: "main" }),
      },
    );
    if (!response.ok) throw new Error(`GitHub recusou o disparo: HTTP ${response.status}`);
    console.log("Workflow disparado", response.status);
  },
};
```

Expressão sugerida no Worker: `2,17,32,47 * * * *`. Confira os disparos
`workflow_dispatch` na aba **Actions** do GitHub.

## Primeira execução e migração

1. Após o merge, execute **Actions → Verificar concursos e avisar no Telegram
   → Run workflow** na `main` com `dry_run` marcado para validar só a coleta.
2. Rode novamente com `dry_run` desmarcado. A primeira coleta válida grava uma
   referência sem anunciar todos os concursos existentes. A branch `state`
   é criada automaticamente e mantém um único commit com o snapshot e a fila atuais, sem credenciais.
3. Ative o cron da Cloudflare e confirme uma execução completa. Depois,
   desative o cron da hospedagem antiga.

A comparação acompanha a listagem do PCI Concursos. Uma publicação retirada
da listagem aparece como remoção. Uma coleta com erro ou sem registros preserva
a última referência válida. A fila é gravada antes do envio. Envios rejeitados
são retentados em rodadas posteriores e os últimos 100 avisos esgotados ficam
em `failed` no estado para inspeção. Uma interrupção entre o envio e a gravação
da fila pode repetir um aviso na rodada seguinte.

Para testar a coleta localmente, use `php index.php check`. Para os testes de
parser, comparação e roteamento, use `php tests/check.php`.
