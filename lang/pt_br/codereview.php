<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese strings for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
// phpcs:disable moodle.Files.LineLength

$string['actions'] = 'Ações';
$string['aicierror'] = 'A revisão por IA não foi iniciada porque as checagens automáticas não puderam ser lidas';
$string['aicompleted'] = 'Revisão por IA concluída';
$string['aidisabled'] = 'A revisão por IA está desativada nesta atividade';
$string['aierror'] = 'Não foi possível concluir a revisão por IA';
$string['aigeneratedby'] = 'Gerado por';
$string['ainoprovider'] = 'Nenhuma chave de IA disponível para esta atividade. Peça ao administrador uma chave do site, ou guarde a sua em Preferências > Minhas chaves de IA e marque a caixa de credenciais pessoais nas configurações da atividade.';
$string['aipending'] = 'Revisão por IA em andamento...';
$string['aireview'] = 'Revisão por IA';
$string['aiskipped'] = 'Sem revisão por IA nesta atividade';
$string['aiwaitingci'] = 'Aguardando as checagens automáticas para iniciar a revisão por IA';
$string['alreadygradednotice'] = 'Esta entrega já foi avaliada. Reabra para o estudante poder enviar de novo.';
$string['approvegrade'] = 'Aprovar e lançar no Gradebook';
$string['authorshipnotice'] = 'Os metadados do repositório são analisados para verificar a autoria desta entrega.';
$string['checkcounted'] = 'Conta para a nota';
$string['checkname'] = 'Checagem';
$string['checkresult'] = 'Resultado';
$string['checkrunnotcounted'] = 'Não conta para a nota (não é uma checagem do GitHub Actions)';
$string['checkruns'] = 'Checagens automáticas';
$string['cichecking'] = 'Checagens automáticas em execução...';
$string['cicompleted'] = 'Checagens automáticas concluídas';
$string['cierror'] = 'Não foi possível ler as checagens automáticas';
$string['cinocidetected'] = 'Nenhuma checagem automática foi detectada neste commit';
$string['cipending'] = 'Aguardando as checagens automáticas...';
$string['citimeout'] = 'Tempo limite da checagem automática (minutos)';
$string['citimeout_help'] = 'Por quanto tempo consultar o GitHub em busca do resultado das checagens automáticas antes de desistir e informar que nenhum CI foi detectado.';
$string['codereview:addinstance'] = 'Adicionar uma nova atividade CodeReview';
$string['codereview:grade'] = 'Revisar entregas e aprovar notas';
$string['codereview:submit'] = 'Enviar um repositório para revisão';
$string['codereview:usepersonaltoken'] = 'Usar um token pessoal do GitHub';
$string['codereview:view'] = 'Ver a atividade CodeReview';
$string['codereview:viewreports'] = 'Ver relatórios de entrega';
$string['commitauthor'] = 'Autor do commit';
$string['commitdatedeclared'] = 'Data do commit (declarada pelo autor)';
$string['commitsha'] = 'SHA do commit';
$string['commitsha_help'] = 'O SHA completo, de 40 caracteres, do commit a ser avaliado. Nomes de branch e tags não são aceitos, porque podem mudar depois do envio.';
$string['completiondetail:submit'] = 'Enviar um repositório e ter a entrega avaliada';
$string['completionsubmit'] = 'O estudante precisa enviar um repositório e ter a entrega avaliada';
$string['cutoffdate'] = 'Data de corte';
$string['cutoffdate_help'] = 'Depois desta data nenhuma entrega ou reenvio é aceito. Deixe desabilitado para permitir entregas por tempo indeterminado.';
$string['duedate'] = 'Prazo de entrega';
$string['duedate_help'] = 'Entregas enviadas depois desta data são sinalizadas como atrasadas para o professor, mas não são bloqueadas.';
$string['enablepersonaltokens'] = 'Permitir tokens pessoais do GitHub';
$string['enablepersonaltokens_desc'] = 'Quando habilitado, professores com a capacidade Usar um token pessoal do GitHub podem guardar o próprio token do GitHub e usá-lo nas atividades deles, em vez de depender do token do site. Eles o encontram em Preferências > Meu token do GitHub. Enquanto isto estiver desabilitado, nem esse link nem a opção nas configurações da atividade aparecem.';
$string['erroralreadygraded'] = 'Esta entrega já foi avaliada. Peça ao professor para reabri-la antes de enviar novamente.';
$string['errorcommitnotfound'] = 'Este commit não foi encontrado no repositório informado. Confira o SHA e tente de novo.';
$string['errorcutoffpassed'] = 'A data de corte desta atividade já passou.';
$string['errorgithubapi'] = 'Não foi possível acessar a API do GitHub. Tente novamente em alguns minutos.';
$string['errorgithubratelimit'] = 'O limite de requisições da API do GitHub foi atingido. Tente novamente mais tarde.';
$string['errorgradeoutofrange'] = 'Informe uma nota entre 0 e {$a}.';
$string['errorinvalidcommitsha'] = 'Informe o SHA completo do commit, com 40 caracteres hexadecimais.';
$string['errorinvalidrepourl'] = 'Informe uma URL válida de repositório público do GitHub, por exemplo https://github.com/dono/repositorio.';
$string['errormalformedairesponse'] = 'O provedor de IA devolveu uma resposta que não pôde ser usada.';
$string['errornoreviewablecode'] = 'Nenhum arquivo de código revisável foi encontrado neste commit.';
$string['errornosubmission'] = 'Ainda não há envio sobre o qual agir.';
$string['errornotpublic'] = 'Este repositório não é público. Esta atividade só avalia repositórios públicos.';
$string['errorrepositorynotfound'] = 'Este repositório não foi encontrado no GitHub. Confira a URL e tente de novo.';
$string['errorrepotoolarge'] = 'Este repositório é grande demais para ser revisado automaticamente.';
$string['errorrunninginprogress'] = 'Já existe uma execução em andamento para esta entrega. Aguarde ela terminar.';
$string['errortokeninvalid'] = 'O token do GitHub em uso não é mais válido. Peça ao professor ou ao administrador para atualizá-lo.';
$string['eventgrade_approved'] = 'Nota aprovada';
$string['eventrepo_submitted'] = 'Repositório enviado';
$string['eventsubmission_reopened'] = 'Entrega reaberta';
$string['feedbackcomment'] = 'Devolutiva para o estudante';
$string['finalgrade'] = 'Nota final';
$string['finalgradeof'] = 'Nota final (de {$a})';
$string['flagcontentoverlap'] = '{$a->shared} de {$a->total} arquivos são byte-idênticos aos de outra entrega';
$string['flagduplicaterepo'] = 'Outra entrega aponta para este mesmo repositório';
$string['flagforeignauthor'] = 'O commit foi assinado pela conta GitHub {$a}';
$string['flagforkofpeer'] = 'Este repositório é um fork do repositório de outro estudante';
$string['flagidenticalcommit'] = 'Este mesmo commit também foi enviado por outro estudante';
$string['flagimportedhistory'] = 'O commit é anterior à criação do repositório (diferença: {$a}). É comum quando o trabalho começou localmente e o repositório foi criado depois';
$string['flagsharedhistory'] = '{$a->shared} de {$a->total} commits desta história também aparecem em outra entrega';
$string['gradeapproved'] = 'A nota foi aprovada e lançada no Gradebook.';
$string['integritychecks'] = 'Verificar autoria';
$string['integritychecks_help'] = 'Compara metadados do repositório e hashes de conteúdo dos arquivos entre as entregas para detectar duplicatas exatas. O resultado é apresentado ao professor apenas como evidência e nunca altera uma nota automaticamente.';
$string['integritydisclaimer'] = 'Estes sinais detectam apenas duplicata exata. Renomear variáveis ou reordenar o código os derrota, portanto a ausência de alertas não é prova de originalidade.';
$string['integritynoflags'] = 'Nenhum alerta de autoria foi levantado. Isto não confirma originalidade.';
$string['integritypanel'] = 'Verificação de autoria';
$string['islate'] = 'Entregue após o prazo';
$string['lasterror'] = 'Último erro registrado';
$string['membersungrouped'] = '{$a} estudante(s) desta página não estão em nenhum grupo e não conseguem enviar. Coloque quem trabalha sozinho em um grupo só dele.';
$string['messagenocidetected'] = 'Nenhuma checagem automática apareceu para o commit {$a->commit} na atividade {$a->activity} antes do tempo limite. Se o repositório tem um workflow do GitHub Actions, confira se ele executou e use "Verificar agora".';
$string['messagenocidetectedsubject'] = 'Nenhuma checagem automática detectada em {$a}';
$string['messageprovider:nocidetected'] = 'Nenhuma checagem automática detectada numa entrega';
$string['modulename'] = 'CodeReview';
$string['modulename_help'] = 'O CodeReview avalia trabalhos de programação hospedados no GitHub. O estudante envia a URL de um repositório e o SHA de um commit; a atividade lê o resultado das checagens automáticas que o GitHub Actions já produziu para aquele commit, opcionalmente acrescenta uma revisão por IA, e apresenta tudo numa tela dedicada onde o professor aprova a nota final.';
$string['modulenameplural'] = 'CodeReviews';
$string['multiplegroupswarning'] = 'Você pertence a mais de um dos grupos que esta atividade usa, então não fica claro de quem é o trabalho do repositório. Peça ao professor para deixar você em apenas um deles.';
$string['mytoken'] = 'Meu token do GitHub';
$string['nocheckruns'] = 'Nenhuma checagem automática foi registrada para este commit.';
$string['nogroupwarning'] = 'Você não está em nenhum grupo, e esta atividade é enviada por grupos. Peça ao professor para incluir você em um — quem trabalha sozinho fica em um grupo só dele.';
$string['opensinnewtab'] = 'Abre em uma nova aba';
$string['personaltoken'] = 'Token pessoal do GitHub';
$string['personaltoken_help'] = 'Um token de acesso pessoal fine-grained, somente leitura, armazenado cifrado, nunca exibido novamente depois de salvo, e usado apenas para ler os repositórios enviados às suas atividades. No GitHub abra Settings > Developer settings > Personal access tokens > Fine-grained tokens e escolha Generate new token. Em Repository access selecione Public repositories (read-only), não conceda nenhuma permissão extra e escolha uma data de validade. O GitHub mostra o token uma única vez, então copie-o na hora. Depois cole-o aqui e salve. Para usá-lo, marque Usar meu token pessoal nesta atividade nas configurações da atividade.';
$string['personaltokennotset'] = 'Nenhum token pessoal armazenado.';
$string['personaltokenremove'] = 'Remover meu token';
$string['personaltokenremoved'] = 'Seu token do GitHub foi removido.';
$string['personaltokensaved'] = 'Seu token do GitHub foi salvo.';
$string['personaltokenstored'] = 'Há um token pessoal armazenado. Salvar um novo substitui o anterior.';
$string['pluginadministration'] = 'Administração do CodeReview';
$string['pluginname'] = 'CodeReview';
$string['privacy:metadata:aiprovider'] = 'O código-fonte filtrado do commit enviado é transmitido ao provedor de IA configurado para gerar uma sugestão de nota.';
$string['privacy:metadata:aiprovider:sourcecode'] = 'O código-fonte sendo revisado.';
$string['privacy:metadata:codereview_airesults'] = 'Sugestões de nota geradas por IA para uma entrega.';
$string['privacy:metadata:codereview_airesults:feedback'] = 'O texto de devolutiva gerado para a entrega.';
$string['privacy:metadata:codereview_airesults:suggestedgrade'] = 'A nota sugerida para a entrega.';
$string['privacy:metadata:codereview_blobs'] = 'Hashes de conteúdo dos arquivos do commit enviado, usados para verificar autoria.';
$string['privacy:metadata:codereview_blobs:blobsha'] = 'O hash de conteúdo do arquivo.';
$string['privacy:metadata:codereview_blobs:path'] = 'O caminho do arquivo dentro do repositório.';
$string['privacy:metadata:codereview_checkruns'] = 'Resultados das checagens automáticas registradas para um commit enviado.';
$string['privacy:metadata:codereview_checkruns:checkname'] = 'O nome da checagem automática.';
$string['privacy:metadata:codereview_checkruns:conclusion'] = 'O resultado alcançado pela checagem.';
$string['privacy:metadata:codereview_flags'] = 'Sinais de autoria levantados sobre uma entrega.';
$string['privacy:metadata:codereview_flags:flagtype'] = 'Qual sinal foi levantado.';
$string['privacy:metadata:codereview_flags:severity'] = 'Quão forte é o sinal.';
$string['privacy:metadata:codereview_grades'] = 'Notas aprovadas por um professor.';
$string['privacy:metadata:codereview_grades:feedbackcomment'] = 'O comentário escrito para o estudante.';
$string['privacy:metadata:codereview_grades:finalgrade'] = 'A nota lançada no Gradebook.';
$string['privacy:metadata:codereview_grades:graderid'] = 'O professor que aprovou a nota.';
$string['privacy:metadata:codereview_submissions'] = 'Entregas de repositório feitas pelo estudante.';
$string['privacy:metadata:codereview_submissions:authorlogin'] = 'A conta GitHub que assinou o commit enviado.';
$string['privacy:metadata:codereview_submissions:commitsha'] = 'O SHA do commit enviado.';
$string['privacy:metadata:codereview_submissions:repourl'] = 'A URL do repositório enviado.';
$string['privacy:metadata:codereview_submissions:timecreated'] = 'Quando o estudante fez a primeira entrega.';
$string['privacy:metadata:codereview_submissions:timesubmitted'] = 'Quando o estudante fez a última entrega ou reenvio.';
$string['privacy:metadata:codereview_submissions:userid'] = 'O estudante que fez a entrega.';
$string['privacy:metadata:github'] = 'Identificadores de repositório e de commit são enviados à API do GitHub para ler o repositório e o resultado das checagens automáticas.';
$string['privacy:metadata:github:commitsha'] = 'O SHA do commit sendo avaliado.';
$string['privacy:metadata:github:repourl'] = 'O repositório sendo avaliado.';
$string['privacy:metadata:preference:githubtoken'] = 'Seu token pessoal de acesso ao GitHub, armazenado cifrado e usado para autenticar as requisições das suas atividades.';
$string['privacy:redacted'] = 'O valor armazenado não é exportado por motivo de segurança.';
$string['publicrepowarning'] = 'O repositório precisa ser público, portanto seu trabalho ficará visível para qualquer pessoa na internet.';
$string['publishedfirstpeer'] = 'O outro repositório foi publicado primeiro.';
$string['publishedfirstthis'] = 'Este repositório foi publicado primeiro.';
$string['recheckci'] = 'Verificar agora';
$string['reopensubmission'] = 'Reabrir para nova entrega';
$string['repourl'] = 'URL do repositório';
$string['repourl_help'] = 'A URL completa do seu repositório público no GitHub, por exemplo https://github.com/dono/repositorio.';
$string['rerunaireview'] = 'Reexecutar a revisão por IA';
$string['resetsubmissions'] = 'Apagar todas as entregas, resultados de checagem, sugestões de IA, sinais de autoria e notas';
$string['resubmissionnotice'] = 'Enviar de novo substitui a entrega atual.';
$string['review'] = 'Revisar';
$string['reviewing'] = 'Revisando a entrega de {$a}';
$string['rubric'] = 'Rubrica de avaliação';
$string['rubric_help'] = 'Critérios usados pela revisão por IA ao sugerir uma nota. Não é exibida aos estudantes.';
$string['runningnow'] = 'Executando...';
$string['severityhigh'] = 'Sinal forte';
$string['severityinfo'] = 'Informativo';
$string['severitywarning'] = 'Vale conferir';
$string['sitetoken'] = 'Token do GitHub do site';
$string['sitetoken_desc'] = 'Um token de acesso pessoal fine-grained, somente leitura de repositórios públicos. Sem ele, a API do GitHub permite apenas 60 requisições por hora para o site inteiro, o que serve para demonstração mas não para uso real.';
$string['studentgradenotice'] = 'Sua nota é lançada depois que o professor revisar e aprovar.';
$string['submissiondetails'] = 'Entrega';
$string['submissionreceived'] = 'Sua entrega foi recebida. As checagens automáticas estão sendo lidas.';
$string['submissionreopened'] = 'A entrega foi reaberta.';
$string['submitrepo'] = 'Enviar repositório';
$string['submittedon'] = 'Enviado no Moodle em';
$string['suggestedgrade'] = 'Nota sugerida';
$string['suggestionisadvisory'] = 'Isto é uma sugestão. A nota lançada no Gradebook é a que você aprovar abaixo.';
$string['taskpollcheckruns'] = 'Ler o resultado das checagens automáticas do GitHub';
$string['taskreconcilesubmissions'] = 'Encerrar entregas cujas checagens nunca terminaram';
$string['taskrunaireview'] = 'Gerar sugestões de nota por IA';
$string['taskrunintegritycheck'] = 'Verificar autoria das entregas';
$string['teamheader'] = 'Envio em grupo';
$string['teamsubmission'] = 'Estudantes enviam como grupo';
$string['teamsubmission_help'] = 'Um repositório por grupo: qualquer integrante envia e o resultado vale para todos, que recebem a nota aprovada. Quem não está em nenhum grupo, ou está em mais de um dos grupos usados, não consegue enviar — coloque quem trabalha sozinho em um grupo só dele, em vez de deixá-lo sem grupo.';
$string['teamsubmissiongroupingid'] = 'Agrupamento dos grupos de estudantes';
$string['teamsubmissiongroupingid_help'] = 'Somente os grupos deste agrupamento formam equipes. Deixe em Nenhum para usar todos os grupos do curso.';
$string['templaterepourl'] = 'URL do repositório-molde';
$string['templaterepourl_help'] = 'O repositório que você distribuiu aos estudantes. Os arquivos dele servem de linha de base para que o código comum do molde não seja apontado como trabalho duplicado.';
$string['tokeninuse'] = 'Usando as credenciais pessoais de {$a}.';
$string['tokenmissing'] = 'Nenhum token pessoal armazenado ainda, então será usado o token do site. Cadastre o seu em {$a}.';
$string['tokenunavailable'] = 'Tokens pessoais do GitHub e chaves de IA não estão disponíveis para você. Para usar as suas, peça ao administrador do site que os habilite e permita que o seu papel os use.';
$string['tokenusemine'] = 'Usar meu token do GitHub e minhas chaves de IA nesta atividade';
$string['tokenusemine_help'] = 'Faz esta atividade usar as suas credenciais em vez das do site. IA: a revisão de cada entrega, inclusive as que rodam em segundo plano sem ninguém logado, é feita com as suas chaves de IA pessoais (Preferências > Minhas chaves de IA), então o custo dessas revisões é seu; sem chaves próprias, valem as chaves do site. GitHub: os repositórios são lidos com o seu token, o que eleva o limite de 60 para 5000 requisições por hora. Para configurar o token: 1) gere-o. No GitHub abra Settings > Developer settings > Personal access tokens > Fine-grained tokens e escolha Generate new token. Em Repository access selecione Public repositories (read-only), não conceda nenhuma permissão extra e escolha uma data de validade. O GitHub mostra o token uma única vez, então copie-o na hora. 2) No Moodle abra Preferências > Meu token do GitHub, cole o token e salve. 3) Volte aqui e marque esta caixa. Se você não vê esses links, peça ao administrador do site que habilite os tokens pessoais do GitHub e as chaves de IA pessoais.';
$string['truncatedcode'] = 'O código enviado para revisão por IA foi truncado pelo limite de tamanho.';
$string['weightai'] = 'Peso da revisão por IA (%)';
$string['weightai_help'] = 'O quanto a revisão por IA contribui para a nota sugerida. Defina como zero para desabilitar a revisão por IA por completo, caso em que nenhum código é enviado a provedor externo de IA.';
$string['weightsmustsum'] = 'O peso das checagens automáticas e o peso da revisão por IA precisam somar 100.';
$string['weighttests'] = 'Peso das checagens automáticas (%)';
$string['weighttests_help'] = 'O quanto o resultado do GitHub Actions contribui para a nota sugerida. Este peso e o peso da revisão por IA precisam somar 100.';
