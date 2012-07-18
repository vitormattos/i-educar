<?php

#error_reporting(E_ALL);
#ini_set("display_errors", 1);

/**
 * i-Educar - Sistema de gestão escolar
 *
 * Copyright (C) 2006  Prefeitura Municipal de Itajaí
 *     <ctima@itajai.sc.gov.br>
 *
 * Este programa é software livre; você pode redistribuí-lo e/ou modificá-lo
 * sob os termos da Licença Pública Geral GNU conforme publicada pela Free
 * Software Foundation; tanto a versão 2 da Licença, como (a seu critério)
 * qualquer versão posterior.
 *
 * Este programa é distribuí­do na expectativa de que seja útil, porém, SEM
 * NENHUMA GARANTIA; nem mesmo a garantia implí­cita de COMERCIABILIDADE OU
 * ADEQUAÇÃO A UMA FINALIDADE ESPECÍFICA. Consulte a Licença Pública Geral
 * do GNU para mais detalhes.
 *
 * Você deve ter recebido uma cópia da Licença Pública Geral do GNU junto
 * com este programa; se não, escreva para a Free Software Foundation, Inc., no
 * endereço 59 Temple Street, Suite 330, Boston, MA 02111-1307 USA.
 *
 * @author    Lucas D'Avila <lucasdavila@portabilis.com.br>
 * @category  i-Educar
 * @license   @@license@@
 * @package   Api
 * @subpackage  Modules
 * @since   Arquivo disponível desde a versão ?
 * @version   $Id$
 */

require_once 'lib/Portabilis/Controller/ApiCoreController.php';
require_once 'include/pmieducar/clsPmieducarMatriculaTurma.inc.php';
require_once 'Avaliacao/Service/Boletim.php';
require_once 'lib/Portabilis/Array/Utils.php';

class V1Controller extends ApiCoreController
{
  protected $_dataMapper  = null;

  #TODO definir este valor com mesmo código cadastro de tipo de exemplar?
  protected $_processoAp  = 0;
  protected $_nivelAcessoOption = App_Model_NivelAcesso::SOMENTE_ESCOLA;
  protected $_saveOption  = FALSE;
  protected $_deleteOption  = FALSE;
  protected $_titulo   = '';


  protected function validatesUserIsLoggedIn() {

    #FIXME validar tokens API
    return true;
  }


  protected function canAcceptRequest() {
    return parent::canAcceptRequest() &&
           $this->validatesPresenceOf(array('aluno_id', 'escola_id'));
  }


  protected function serviceBoletimForMatricula($id) {
    $service = null;

    # FIXME get $this->getSession()->id_pessoa se usuario logado
    # ou pegar id do ini config, se api request
    $userId = 1;

    try {
      $service = new Avaliacao_Service_Boletim(array('matricula' => $id, 'usuario' => $userId));
    }
    catch (Exception $e){
      $this->messenger->append("Erro ao instanciar serviço boletim para matricula {$id}: " . $e->getMessage());
    }

    return $service;
  }

  
  protected function reportBoletimTemplateForMatricula($id) {
    $template = '';

    $templates = array('bimestral'                     => 'portabilis_boletim',
                       'trimestral'                    => 'portabilis_boletim_trimestral',
                       'trimestral_conceitual'         => 'portabilis_boletim_primeiro_ano_trimestral',
                       'semestral'                     => 'portabilis_boletim_semestral',
                       'semestral_conceitual'          => 'portabilis_boletim_conceitual_semestral',
                       'semestral_educacao_infantil'   => 'portabilis_boletim_educ_infantil_semestral',
                       'parecer_descritivo_componente' => 'portabilis_boletim_parecer',
                       'parecer_descritivo_geral'      => 'portabilis_boletim_parecer_geral');
                        
    $service = $this->serviceBoletimForMatricula($id);

    if ($service != null) {
      # FIXME perguntar service se nota é conceitual?
      $notaConceitual                     = false;
      $qtdEtapasModulo                    = $service->getOption('etapas');

      # FIXME veriificar se é educação infantil?
      $educacaoInfantil                   = false;


      // parecer

      $flagParecerGeral          = array(RegraAvaliacao_Model_TipoParecerDescritivo::ETAPA_GERAL,                   
                                     RegraAvaliacao_Model_TipoParecerDescritivo::ANUAL_GERAL);

      $flagParecerComponente = array(RegraAvaliacao_Model_TipoParecerDescritivo::ETAPA_COMPONENTE,
                                     RegraAvaliacao_Model_TipoParecerDescritivo::ANUAL_COMPONENTE);

      $parecerAtual                = $service->getRegra()->get('parecerDescritivo');
      $parecerDescritivoGeral      = in_array($parecerAtual, $flagParecerGeral);
      $parecerDescritivoComponente = in_array($parecerAtual, $flagParecerComponente);


      // decide qual templete usar

      if ($parecerDescritivoGeral)
        $template = 'parecer_descritivo_geral';

      elseif ($parecerDescritivoComponente)
        $template = 'parecer_descritivo_componente';

      elseif ($qtdEtapasModulo > 5 && $educacaoInfantil)
        $template = 'semestral_educacao_infantil';

      elseif ($qtdEtapasModulo > 5 && $notaConceitual)
        $template = 'semestral_conceitual';

      elseif ($qtdEtapasModulo > 5)
        $template = 'semestral';

      elseif ($qtdEtapasModulo > 2 && $notaConceitual)
        $template = 'trimestral_conceitual';

      elseif ($qtdEtapasModulo > 2)
        $template = 'trimestral';

      else
        $template = 'bimestral';

      $template = $templates[$template];
    }

    return $template;
  }

  // load resources

  protected function loadNomeEscola() {
    $sql = "select nome from cadastro.pessoa, pmieducar.escola where idpes = ref_idpes and cod_escola = $1";
    $nome = $this->fetchPreparedQuery($sql, $this->getRequest()->escola_id, false, 'first-field');

    return utf8_encode(strtoupper($nome));
  }


  protected function loadNomeAluno() {
    $sql = "select nome from cadastro.pessoa, pmieducar.aluno where idpes = ref_idpes and cod_aluno = $1";
    $nome = $this->fetchPreparedQuery($sql, $this->getRequest()->aluno_id, false, 'first-field');

    return utf8_encode(ucwords(strtolower($nome)));
  }


  protected function loadNameFor($resourceName, $id){
    $sql = "select nm_{$resourceName} from pmieducar.{$resourceName} where cod_{$resourceName} = $1";
    $nome = $this->fetchPreparedQuery($sql, $id, false, 'first-field');

    return utf8_encode(strtoupper($nome));
  }


  protected function tryLoadMatriculaTurma($matricula) {
    $sql = "select ref_cod_turma as turma_id from pmieducar.matricula_turma where ref_cod_matricula = $1 and matricula_turma.ativo = 1 limit 1";

    $matriculaTurma = $this->fetchPreparedQuery($sql, $matricula['id'], false, 'first-row');

    if (is_array($matriculaTurma) and count($matriculaTurma) > 0) {
      $attrs                                     = array('turma_id', 'nome_turma');
      $matriculaTurma                            = Portabilis_Array_Utils::filter($matriculaTurma, $attrs);
      $matriculaTurma['nome_turma']              = $this->loadNameFor('turma', $matriculaTurma['turma_id']);
      $matriculaTurma['report_boletim_template'] = $this->reportBoletimTemplateForMatricula($matricula['id']);
    }

    return $matriculaTurma;
  }


  protected function loadMatriculas() {
    #TODO mostrar o nome da situação da matricula
    $sql = "select cod_matricula as id, ano, ref_cod_instituicao as instituicao_id, ref_ref_cod_escola as escola_id, ref_cod_curso as curso_id, ref_ref_cod_serie as serie_id from pmieducar.matricula, pmieducar.escola where cod_escola = ref_ref_cod_escola and ref_cod_aluno = $1 and ref_ref_cod_escola = $2 and matricula.ativo = 1 order by ano desc, id";

    $params     = array($this->getRequest()->aluno_id, $this->getRequest()->escola_id);
    $matriculas = $this->fetchPreparedQuery($sql, $params, false);

    if (is_array($matriculas) && count($matriculas) > 0) {
      $attrs      = array('id', 'ano', 'instituicao_id', 'escola_id', 'curso_id', 'serie_id');
      $matriculas = Portabilis_Array_Utils::filterSet($matriculas, $attrs);

      foreach($matriculas as $key => $matricula) {
        $matriculas[$key]['nome_curso']                = $this->loadNameFor('curso', $matricula['curso_id']);
        $matriculas[$key]['nome_escola']               = $this->loadNomeEscola();
        $matriculas[$key]['nome_serie']                = $this->loadNameFor('serie', $matricula['serie_id']);
        $turma                                         = $this->tryLoadMatriculaTurma($matricula);

        if (is_array($turma) and count($turma) > 0) {
          $matriculas[$key]['turma_id']                = $turma['turma_id'];
          $matriculas[$key]['nome_turma']              = $turma['nome_turma'];
          $matriculas[$key]['report_boletim_template'] = $turma['report_boletim_template'];
        }
      }
    }

    return $matriculas;
  }


  protected function loadOcorrenciasDisciplinares() {
    $ocorrenciasAluno              = array();
    $matriculas                    = $this->loadMatriculas();

    $attrsFilter                   = array('data_cadastro' => 'data_hora', 'observacao' => 'descricao');
    $ocorrenciasMatriculaInstance  = new clsPmieducarMatriculaOcorrenciaDisciplinar();

    foreach($matriculas as $matricula) {
      $ocorrenciasMatricula = $ocorrenciasMatriculaInstance->lista($matricula['id'], 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    null, 
                                                                    1);

      if (is_array($ocorrenciasMatricula)) {
        $ocorrenciasMatricula = Portabilis_Array_Utils::filterSet($ocorrenciasMatricula, $attrsFilter);

        foreach($ocorrenciasMatricula as $ocorrenciaMatricula) {
          $ocorrenciaMatricula['data_hora'] = date('d/m/Y H:i:s', strtotime($ocorrenciaMatricula['data_hora']));
          $ocorrenciaMatricula['descricao'] = utf8_encode($ocorrenciaMatricula['descricao']);
          $ocorrenciasAluno[]               = $ocorrenciaMatricula;
        }
      }
    }  

    return $ocorrenciasAluno;
  }


  // api responder

  protected function getAluno() {
    $aluno = array('id'         => $this->getRequest()->aluno_id, 
                   'nome'       => $this->loadNomeAluno(), 
                   'matriculas' => $this->loadMatriculas(true));

    return $aluno;
  }


  protected function getOcorrenciasDisciplinares() {
    return $this->loadOcorrenciasDisciplinares();
  }


  public function Gerar() {
    if ($this->isRequestFor('get', 'aluno'))
      $this->appendResponse('aluno', $this->getAluno());
    elseif ($this->isRequestFor('get', 'ocorrencias_disciplinares'))
      $this->appendResponse('ocorrencias_disciplinares', $this->getOcorrenciasDisciplinares());
    else
      $this->notImplementedOperationError();
  }
}
