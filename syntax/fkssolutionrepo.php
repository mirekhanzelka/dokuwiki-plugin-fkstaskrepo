<?php

if (!defined('DOKU_INC')) {
    die();
}
if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once(DOKU_PLUGIN . 'syntax.php');

class syntax_plugin_fksproblems_fkssolutionrepo extends DokuWiki_Syntax_Plugin {

    public function getType() {
        return 'substition';
    }

    public function getPType() {
        return 'normal';
    }

    public function getAllowedTypes() {
        return array('formatting', 'substition', 'disabled');
    }

    public function getSort() {
        return 226;
    }

    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{fkssolutionrepo>.+?\}\}', $mode, 'plugin_fksproblems_fkssolutionrepo');
    }

    /**
     * Handle the match
     */
    public function handle($match, $state, $pos, Doku_Handler &$handler) {
        $probsno = array();
        $probsno = preg_split('/-/', substr($match, 18, -2));
        $solutionfileurl = str_replace('@Y@', $probsno[0], $this->getConf('solutionrepo'));
        $solutionfileurl = str_replace('@S@', $probsno[1], $solutionfileurl);
        $probssolution = preg_split('/===/', io_readFile("$solutionfileurl.txt", FALSE));
        //$to_page.='<div>';
        $to_page.= '<h3 onclick="viewsolution(' . $probsno[2] . ')"> zobraz riešenie </h3>';
        $to_page.='<div class="fksprobsol" id="fksprobsol' . $probsno[2] . '" style="display:none">';
        if (!$probssolution[2*$probsno[2]-1] == NUll) {
            $to_page.=p_render("xhtml", p_get_instructions('==== ' . $probssolution[2 * $probsno[2] - 1] . ' ==== '), $info);
            $to_page.=p_render("xhtml", p_get_instructions($probssolution[2]), $info);
            
        } else {
            $to_page.= 'Rešení ešte nebolo nahrané';
        }
        $to_page.='</div>';

        /* } else {
          $to_page.='<div class="fksprobtask" id="fksprobtask'.$i/2 .'">';
          $to_page.=p_render("xhtml", p_get_instructions($probstask[$i]), $info);
          $to_page.='</div>';
          $to_page.= '<h3 onclick="viewsolution('.$i/2 .')"> zobraz riešenie </h3>';
          $to_page.='<div class="fksprobsol" id="fksprobsol'.$i/2 .'" style="display:none">';
          if(!$probssolution[$i]==NUll){
          $to_page.= p_render("xhtml", p_get_instructions($probssolution[$i]), $info);
          }else{
          $to_page.= 'Rešení ešte nebolo nahrané';
          }

          $to_page.='</div>';
          } */

        return array($state, array($to_page));
    }

    public function render($mode, Doku_Renderer &$renderer, $data) {
        // $data is what the function handle return'ed.
        if ($mode == 'xhtml') {
            /** @var Do ku_Renderer_xhtml $renderer */
            list($state, $match) = $data;
            list($to_page) = $match;
            $renderer->doc .= $to_page;
        }
        return false;
    }

}
