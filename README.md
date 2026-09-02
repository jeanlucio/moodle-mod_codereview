# CodeReview (mod_codereview)

Assess programming work hosted on GitHub, from inside Moodle.

> **Status: alpha, under active development.** The whole flow works end to end: individual and
> group submission, check results, AI review, authorship verification, teacher review and grade
> approval. The activity has not yet been through a published release. Not ready for production
> use.

[Português abaixo](#codereview-mod_codereview-1)

## What it does

Students submit a **public GitHub repository URL** and a **commit SHA**. The activity then:

1. Confirms against the GitHub API that the commit exists and the repository is public.
2. Reads the automated check results that **GitHub Actions already produced** for that commit.
3. Optionally asks an AI provider for a qualitative review of the source code.
4. Compares repository metadata across submissions to verify authorship.
5. Presents all of it on a review screen where **the teacher approves the final grade**.

## Design boundaries

* **The plugin never runs student code.** It never clones and never executes anything. Every
  deterministic check comes from reading results GitHub Actions produced on its own
  infrastructure. This is a permanent architectural boundary, not an MVP limitation.
* **Nothing writes a grade on its own.** Neither the automated checks, nor the AI review, nor the
  authorship signals ever change a grade or block a submission. They produce a suggestion and
  evidence; a human decides.
* **Authorship verification detects exact duplicates only.** It compares Git metadata and content
  hashes, so it catches clones, forks of a peer's repository and byte-identical files. Renaming
  variables or reordering functions defeats it. **The absence of an alert is not proof of
  originality.**

## Trying it out

A ready-made template repository is available:
**[moodle-mod_codereview-template](https://github.com/jeanlucio/moodle-mod_codereview-template)**

It is a small Python exercise with one GitHub Actions job per assessed criterion, which is the
layout this activity expects. One function ships unimplemented on purpose, so a fresh copy has
three of its four checks passing — what a student's first push actually looks like.

Use it as the activity's **template repository**, and press *Use this template* to stand in for a
student. Do not fork it: GitHub disables workflows on forks until someone enables them by hand,
which would leave the activity reporting that no automated check was found.

## Requirements

* Moodle 4.5 or later (tested against 4.5, 5.0, 5.1 and 5.2)
* PHP 8.1 or later
* A GitHub repository per student, **public**, with a GitHub Actions workflow

## What students need to know

The repository must be **public**, which means their work is visible to anyone on the internet.
Repository metadata is analysed to verify authorship. Both facts are stated on the submission
screen before anything is sent.

## GitHub API token

The API is read-only here, and a token is optional but strongly recommended:

| Level | Where | Rate limit |
|---|---|---|
| Personal token of the activity owner | The teacher's own preferences page, encrypted | 5000/hour |
| Site token | Site administration, set by the admin | 5000/hour, shared |
| No token | — | 60/hour **per IP, shared by the whole site** |

At roughly ten requests per submission, the unauthenticated tier allows about six submissions per
hour for the entire site: it is a demonstration mode, not a production one.

An activity stores only a **pointer** to whose personal token it uses, never the token itself, so
no secret is ever reachable through a course-scoped form. Use a fine-grained personal access token
with read-only access and no write scopes.

## Licence

GPL v3 or later.

---

# CodeReview (mod_codereview)

Avaliação de trabalhos de programação hospedados no GitHub, de dentro do Moodle.

> **Estado: alpha, em desenvolvimento ativo.** O fluxo funciona de ponta a ponta: entrega
> individual e em grupo, leitura das checagens, revisão por IA, verificação de autoria, revisão
> docente e aprovação da nota. O plugin ainda não passou por nenhuma release publicada. Não está
> pronto para uso em produção.

## O que faz

O estudante envia a **URL de um repositório público do GitHub** e o **SHA de um commit**. A
atividade então:

1. Confirma contra a API do GitHub que o commit existe e que o repositório é público.
2. Lê o resultado das checagens automáticas que **o GitHub Actions já produziu** para aquele commit.
3. Opcionalmente pede a um provedor de IA uma revisão qualitativa do código-fonte.
4. Compara metadados dos repositórios entre as entregas para verificar autoria.
5. Apresenta tudo numa tela de revisão onde **o professor aprova a nota final**.

## Fronteiras de projeto

* **O plugin nunca executa o código do estudante.** Nunca clona e nunca roda nada. Toda checagem
  determinística vem da leitura de resultados que o GitHub Actions produziu na infraestrutura dele.
  É uma fronteira arquitetural permanente, não uma limitação de MVP.
* **Nada grava nota sozinho.** Nem as checagens automáticas, nem a revisão por IA, nem os sinais de
  autoria alteram nota ou bloqueiam entrega. Eles produzem uma sugestão e evidências; quem decide é
  uma pessoa.
* **A verificação de autoria detecta apenas duplicata exata.** Compara metadados do Git e hashes de
  conteúdo, então pega clone, fork do repositório de um colega e arquivos byte-idênticos. Renomear
  variáveis ou reordenar funções a derrota. **Ausência de alerta não é prova de originalidade.**

## Para experimentar

Há um repositório-molde pronto:
**[moodle-mod_codereview-template](https://github.com/jeanlucio/moodle-mod_codereview-template)**

É um exercício pequeno em Python com um job do GitHub Actions por critério avaliado, que é o
formato que esta atividade espera. Uma das funções vem sem implementação de propósito, então uma
cópia nova tem três das quatro checagens passando — o que a primeira entrega de um estudante
realmente produz.

Use-o como **repositório-molde** da atividade, e clique em *Use this template* para simular um
estudante. Não faça fork: o GitHub desabilita os workflows em forks até alguém habilitar à mão, e a
atividade acabaria informando que nenhuma checagem automática foi encontrada.

## Requisitos

* Moodle 4.5 ou superior (testado em 4.5, 5.0, 5.1 e 5.2)
* PHP 8.1 ou superior
* Um repositório GitHub por estudante, **público**, com um workflow do GitHub Actions

## O que o estudante precisa saber

O repositório precisa ser **público**, ou seja, o trabalho dele fica visível para qualquer pessoa na
internet. Os metadados do repositório são analisados para verificar autoria. As duas coisas são
declaradas na tela de submissão, antes de qualquer envio.

## Token da API do GitHub

O acesso à API aqui é somente leitura, e o token é opcional, mas fortemente recomendado:

| Nível | Onde | Limite de requisições |
|---|---|---|
| Token pessoal do dono da atividade | Página de preferências do próprio professor, cifrado | 5000/hora |
| Token de site | Administração do site, definido pelo admin | 5000/hora, compartilhado |
| Sem token | — | 60/hora **por IP, compartilhado por todo o site** |

A cerca de dez requisições por entrega, o nível não autenticado permite algo como seis entregas por
hora no site inteiro: é modo de demonstração, não de produção.

A atividade guarda apenas um **ponteiro** para de quem é o token pessoal usado, nunca o token em si,
de modo que nenhum segredo fica alcançável por um formulário de curso. Use um token de acesso
pessoal fine-grained, somente leitura, sem nenhum escopo de escrita.

## Licença

GPL v3 ou superior.
