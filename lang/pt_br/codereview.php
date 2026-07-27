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
$string['aicompleted'] = 'Revisão por IA concluída';
$string['aierror'] = 'Não foi possível concluir a revisão por IA';
$string['aigeneratedby'] = 'Gerado por';
$string['aipending'] = 'Revisão por IA em andamento...';
$string['aireview'] = 'Revisão por IA';
$string['aiskipped'] = 'Sem revisão por IA nesta atividade';
$string['alreadygradednotice'] = 'Esta entrega já foi avaliada. Reabra para o estudante poder enviar de novo.';
$string['authorshipnotice'] = 'Os metadados do repositório são analisados para verificar a autoria desta entrega.';
$string['checkcounted'] = 'Conta para a nota';
$string['checkname'] = 'Checagem';
$string['checkresult'] = 'Resultado';
$string['checkrunnotcounted'] = 'Não conta para a nota (não é uma checagem do GitHub Actions)';
$string['checkruns'] = 'Checagens automáticas';
$string['cichecking'] = 'Checagens automáticas em execução...';
$string['cicompleted'] = 'Checagens automáticas concluídas';
$string['cierror'] = 'Não foi possível ler as checagens automáticas';
$string['citimeout'] = 'Tempo limite da checagem automática (minutos)';
$string['citimeout_help'] = 'Por quanto tempo consultar o GitHub em busca do resultado das checagens automáticas antes de desistir e informar que nenhum CI foi detectado.';
$string['codereview:addinstance'] = 'Adicionar uma nova atividade CodeReview';
$string['codereview:grade'] = 'Revisar entregas e aprovar notas';
$string['codereview:submit'] = 'Enviar um repositório para revisão';
$string['codereview:usepersonaltoken'] = 'Usar um token pessoal do GitHub';
$string['codereview:view'] = 'Ver a atividade CodeReview';
$string['codereview:viewreports'] = 'Ver relatórios de entrega';
$string['commitauthor'] = 'Autor do commit';
$string['commitsha'] = 'SHA do commit';
$string['commitsha_help'] = 'O SHA completo, de 40 caracteres, do commit a ser avaliado. Nomes de branch e tags não são aceitos, porque podem mudar depois do envio.';
$string['cutoffdate'] = 'Data de corte';
$string['cutoffdate_help'] = 'Depois desta data nenhuma entrega ou reenvio é aceito. Deixe desabilitado para permitir entregas por tempo indeterminado.';
$string['duedate'] = 'Prazo de entrega';
$string['duedate_help'] = 'Entregas enviadas depois desta data são sinalizadas como atrasadas para o professor, mas não são bloqueadas.';
$string['enablepersonaltokens'] = 'Permitir tokens pessoais do GitHub';
$string['enablepersonaltokens_desc'] = 'Quando habilitado, professores com a capacidade correspondente podem guardar o próprio token do GitHub e usá-lo nas atividades deles, em vez de depender do token do site.';
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
$string['errornotpublic'] = 'Este repositório não é público. Esta atividade só avalia repositórios públicos.';
$string['errorrecheckpending'] = 'Esta entrega ainda não foi verificada. Aguarde a primeira checagem automática antes de solicitar outra.';
$string['errorrepositorynotfound'] = 'Este repositório não foi encontrado no GitHub. Confira a URL e tente de novo.';
$string['errorrepotoolarge'] = 'Este repositório é grande demais para ser revisado automaticamente.';
$string['errortokeninvalid'] = 'O token do GitHub em uso não é mais válido. Peça ao professor ou ao administrador para atualizá-lo.';
$string['eventgrade_approved'] = 'Nota aprovada';
$string['eventrepo_submitted'] = 'Repositório enviado';
$string['eventsubmission_reopened'] = 'Entrega reaberta';
$string['feedbackcomment'] = 'Devolutiva para o estudante';
$string['finalgrade'] = 'Nota final';
$string['finalgradeof'] = 'Nota final (de {$a})';
$string['flagduplicaterepo'] = 'Outra entrega aponta para este mesmo repositório';
$string['flagforeignauthor'] = 'O commit foi assinado pela conta GitHub {$a}';
$string['flagimportedhistory'] = 'O commit tem data anterior à criação do repositório que o contém';
$string['flagsharedhistory'] = '{$a->shared} de {$a->total} commits desta história também aparecem em outra entrega';
$string['gradeapproved'] = 'A nota foi aprovada e lançada no Gradebook.';
$string['integritychecks'] = 'Verificar autoria';
$string['integritychecks_help'] = 'Compara metadados do repositório e hashes de conteúdo dos arquivos entre as entregas para detectar duplicatas exatas. O resultado é apresentado ao professor apenas como evidência e nunca altera uma nota automaticamente.';
$string['integritydisclaimer'] = 'Estes sinais detectam apenas duplicata exata. Renomear variáveis ou reordenar o código os derrota, portanto a ausência de alertas não é prova de originalidade.';
$string['messagenocidetected'] = 'Nenhuma checagem automática apareceu para o commit {$a->commit} na atividade {$a->activity} antes do tempo limite. Se o repositório tem um workflow do GitHub Actions, confira se ele executou e use "Verificar agora".';
$string['messagenocidetectedsubject'] = 'Nenhuma checagem automática detectada em {$a}';
$string['messageprovider:nocidetected'] = 'Nenhuma checagem automática detectada numa entrega';
$string['modulename'] = 'CodeReview';
$string['modulename_help'] = 'O CodeReview avalia trabalhos de programação hospedados no GitHub. O estudante envia a URL de um repositório e o SHA de um commit; a atividade lê o resultado das checagens automáticas que o GitHub Actions já produziu para aquele commit, opcionalmente acrescenta uma revisão por IA, e apresenta tudo numa tela dedicada onde o professor aprova a nota final.';
$string['modulenameplural'] = 'CodeReviews';
$string['mytoken'] = 'Meu token do GitHub';
$string['nocheckruns'] = 'Nenhuma checagem automática foi registrada para este commit.';
$string['opensinnewtab'] = 'Abre em uma nova aba';
$string['personaltoken'] = 'Token pessoal do GitHub';
$string['personaltoken_help'] = 'Um token de acesso pessoal fine-grained, somente leitura. Ele é armazenado cifrado, nunca é exibido novamente depois de salvo, e serve apenas para ler os repositórios enviados às suas atividades.';
$string['personaltokennotset'] = 'Nenhum token pessoal armazenado.';
$string['personaltokenremove'] = 'Remover meu token';
$string['personaltokenremoved'] = 'Seu token do GitHub foi removido.';
$string['personaltokensaved'] = 'Seu token do GitHub foi salvo.';
$string['personaltokenstored'] = 'Há um token pessoal armazenado. Salvar um novo substitui o anterior.';
$string['pluginadministration'] = 'Administração do CodeReview';
$string['pluginname'] = 'CodeReview';
$string['privacy:metadata:codereview_submissions'] = 'Entregas de repositório feitas pelo estudante.';
$string['privacy:metadata:codereview_submissions:commitsha'] = 'O SHA do commit enviado.';
$string['privacy:metadata:codereview_submissions:repourl'] = 'A URL do repositório enviado.';
$string['privacy:metadata:codereview_submissions:timecreated'] = 'Quando a entrega foi feita.';
$string['privacy:metadata:codereview_submissions:userid'] = 'O estudante que fez a entrega.';
$string['privacy:metadata:github'] = 'Identificadores de repositório e de commit são enviados à API do GitHub para ler o repositório e o resultado das checagens automáticas.';
$string['privacy:metadata:github:commitsha'] = 'O SHA do commit sendo avaliado.';
$string['privacy:metadata:github:repourl'] = 'O repositório sendo avaliado.';
$string['privacy:metadata:preference:githubtoken'] = 'Seu token pessoal de acesso ao GitHub, armazenado cifrado e usado para autenticar as requisições das suas atividades.';
$string['privacy:redacted'] = 'O valor armazenado não é exportado por motivo de segurança.';
$string['publicrepowarning'] = 'O repositório precisa ser público, portanto seu trabalho ficará visível para qualquer pessoa na internet.';
$string['publishedfirstpeer'] = 'O outro repositório foi publicado primeiro.';
$string['publishedfirstthis'] = 'Este repositório foi publicado primeiro.';
$string['repourl'] = 'URL do repositório';
$string['repourl_help'] = 'A URL completa do seu repositório público no GitHub, por exemplo https://github.com/dono/repositorio.';
$string['review'] = 'Revisar';
$string['reviewing'] = 'Revisando a entrega de {$a}';
$string['rubric'] = 'Rubrica de avaliação';
$string['rubric_help'] = 'Critérios usados pela revisão por IA ao sugerir uma nota. Não é exibida aos estudantes.';
$string['severityhigh'] = 'Sinal forte';
$string['severityinfo'] = 'Informativo';
$string['severitywarning'] = 'Vale conferir';
$string['sitetoken'] = 'Token do GitHub do site';
$string['sitetoken_desc'] = 'Um token de acesso pessoal fine-grained, somente leitura de repositórios públicos. Sem ele, a API do GitHub permite apenas 60 requisições por hora para o site inteiro, o que serve para demonstração mas não para uso real.';
$string['submissiondetails'] = 'Entrega';
$string['submissionreopened'] = 'A entrega foi reaberta.';
$string['submitrepo'] = 'Enviar repositório';
$string['submittedon'] = 'Enviado no Moodle em';
$string['suggestionisadvisory'] = 'Isto é uma sugestão. A nota lançada no Gradebook é a que você aprovar abaixo.';
$string['taskpollcheckruns'] = 'Ler o resultado das checagens automáticas do GitHub';
$string['taskreconcilesubmissions'] = 'Encerrar entregas cujas checagens nunca terminaram';
$string['taskrunaireview'] = 'Gerar sugestões de nota por IA';
$string['taskrunintegritycheck'] = 'Verificar autoria das entregas';
$string['templaterepourl'] = 'URL do repositório-molde';
$string['templaterepourl_help'] = 'O repositório que você distribuiu aos estudantes. Os arquivos dele servem de linha de base para que o código comum do molde não seja apontado como trabalho duplicado.';
$string['tokeninuse'] = 'Usando o token do GitHub de {$a}.';
$string['tokenusemine'] = 'Usar meu token pessoal nesta atividade';
$string['weightai'] = 'Peso da revisão por IA (%)';
$string['weightai_help'] = 'O quanto a revisão por IA contribui para a nota sugerida. Defina como zero para desabilitar a revisão por IA por completo, caso em que nenhum código é enviado a provedor externo de IA.';
$string['weightsmustsum'] = 'O peso das checagens automáticas e o peso da revisão por IA precisam somar 100.';
$string['weighttests'] = 'Peso das checagens automáticas (%)';
$string['weighttests_help'] = 'O quanto o resultado do GitHub Actions contribui para a nota sugerida. Este peso e o peso da revisão por IA precisam somar 100.';
