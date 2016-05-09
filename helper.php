<?php

/**
 * DokuWiki Plugin fkstaskrepo (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michal Koutný <michal@fykos.cz>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once 'tex_preproc.php';

class helper_plugin_fkstaskrepo extends DokuWiki_Plugin {

    /**
     * @var helper_plugin_fksdownloader
     */
    private $downloader;

    /**
     * @var fkstaskrepo_tex_preproc;
     */
    public $texPreproc;

    /**
     * @var helper_plugin_sqlite
     */
    private $sqlite;

    public function __construct() {
        $this->downloader = $this->loadHelper('fksdownloader');
        $this->texPreproc = new fkstaskrepo_tex_preproc();

// initialize sqlite
        $this->sqlite = $this->loadHelper('sqlite',false);
        $pluginName = $this->getPluginName();
        if(!$this->sqlite){
            msg($pluginName.': This plugin requires the sqlite plugin. Please install it.');
            return;
        }
        if(!$this->sqlite->init($pluginName,DOKU_PLUGIN.$pluginName.DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR)){
            msg($pluginName.': Cannot initialize database.');
            return;
        }
    }

    /**
     * Return info about supported methods in this Helper Plugin
     *
     * @return array of public methods
     */
    public function getMethods() {
        return array(
//TODO
        );
    }

    public function getProblemData($year,$series,$problem,$lang) {

        $localData = $this->getLocalData($year,$series,$problem,$lang);

        $globalData = $this->extractProblem($this->getSeriesData($year,$series),$problem,$lang);



        return array_merge($globalData,$localData,array('year' => $year,'series' => $series,'problem' => $problem,'lang' => $lang));
    }

    public function updateProblemData($data,$year,$series,$problem,$lang) {

        $globalData = $this->extractProblem($this->getSeriesData($year,$series),$problem,$lang);

// empty task text -- revert original
        if(array_key_exists('task',$data) && $data['task'] == ''){
            unset($data['task']);
        }


        $toStore = array_diff($data,$globalData);

        if(array_key_exists('task',$toStore)){
            $toStore['taskTS'] = time();
        }

        $filename = $this->getProblemFile($year,$series,$problem,$lang);
        io_saveFile($filename,serialize($toStore));
    }

    private function getLocalData($year,$series,$problem,$lang) {
        $filename = $this->getProblemFile($year,$series,$problem,$lang);
        $content = io_readFile($filename,false);
        if($content){
            $data = unserialize($content);
        }else{
            $data = array();
        }
//$tags = $this->loadTags($year,$series,$problem);
// $data['tags'] = $tags;
        return $data;
    }

    /*     * **************
     * XML data
     */

    private function getPath($year,$series) {
        $mask = $this->getConf('remote_path_mask');
        return sprintf($mask,$year,$series);
    }

    public function getProblemFile($year,$series,$problem,$lang) {
        $id = $this->getPluginName().":".$year.":".$series."-".$problem."_".$lang;
        return metaFN($id,'.dat');
    }

    public function getSeriesData($year,$series,$expiration = helper_plugin_fksdownloader::EXPIRATION_NEVER) {
        $path = $this->getPath($year,$series);
        return $this->downloader->downloadWebServer($expiration,$path);
    }

    public function getSeriesFilename($year,$series) {
        return $this->downloader->getCacheFilename($this->downloader->getWebServerFilename($this->getPath($year,$series)));
    }

    public function extractProblem($data,$problemLabel,$lang = 'cs') {

        $series = simplexml_load_string($data);

        $problems = $series->problems;

        $deadline = $series->deadline;
        $dedline_post = $series->{'deadline-post'};
        $problemData = null;
        if(!$problems){
            return array();
        }
        foreach ($problems->problem as $problem) {

            if(isset($problem->label) && (string) $problem->label == $problemLabel){

                foreach ($problem->children() as $k => $child) {
                    if((string) $child->attributes('http://www.w3.org/XML/1998/namespace')->lang != ""){
                        if($lang == (string) $child->attributes('http://www.w3.org/XML/1998/namespace')->lang){
                            $problemData[$k] = (string) $child;
                        }
                        continue;
                    }


                    if(count($child) > 1){
                        foreach ($child->children() as $ch) {
                            $problemData[$k] = (array) $child->children();
                        }
                        continue;
                    }
                    $problemData[$k] = (string) $child;
                }


                break;
            }
        }
 


        return $problemData;
    }

    /*     * **************
     * Tags
     */

    public function storeTags($year,$series,$problem,$tags) {
        // allocate problem ID
        $sql = 'select problem_id from problem where year = ? and series = ? and problem = ?';
        $res = $this->sqlite->query($sql,$year,$series,$problem);
        $problemId = $this->sqlite->res2single($res);
        if(!$problemId){
            $this->sqlite->query('insert into problem (year, series, problem) values(?, ?, ?)',$year,$series,$problem);
            $res = $this->sqlite->query($sql,$year,$series,$problem);
            $problemId = $this->sqlite->res2single($res);
        }

        // flush and insert tags
        $this->sqlite->query('begin transaction');
        $this->sqlite->query('delete from problem_tag where problem_id = ?',$problemId);
        foreach ($tags as $tag) {
            // allocate tag ID
            $sql = 'select tag_id from tag where tag_cs = ?';
            $res = $this->sqlite->query($sql,$tag);
            $tagId = $this->sqlite->res2single($res);
            if(!$tagId){
                $this->sqlite->query('insert into tag (tag_cs) values(?)',$tag);
                $res = $this->sqlite->query($sql,$tag);
                $tagId = $this->sqlite->res2single($res);
            }

            $this->sqlite->query('insert into problem_tag (problem_id, tag_id) values(?, ?)',$problemId,$tagId);
        }

        $this->sqlite->query('delete from tag where tag_id not in (select tag_id from problem_tag)'); // garbage collection
        $this->sqlite->query('commit transaction');
    }

    private function loadTags($year,$series,$problem) {
        $sql = 'select problem_id from problem where year = ? and series = ? and problem = ?';
        $res = $this->sqlite->query($sql,$year,$series,$problem);
        $problemId = $this->sqlite->res2single($res);

        if(!$problemId){
            return array();
        }

        $res = $this->sqlite->query('select t.tag_cs from tag t left join problem_tag pt on pt.tag_id = t.tag_id where pt.problem_id =?',$problemId);
        $result = array();
        foreach ($this->sqlite->res2arr($res) as $row) {
            $result[] = $row['tag_cs'];
        }
        return $result;
    }

    public function getTags($lang = 'cs') {
        $sql = 'select t.tag_cs as tag, count(pt.problem_id) as count from tag t left join problem_tag pt on pt.tag_id = t.tag_id group by t.tag_id order by 1';
        $res = $this->sqlite->query($sql);
        return $this->sqlite->res2arr($res);
    }

    public function getProblemsByTag($tag,$lang = 'cs') {
        $sql = 'select tag_id from tag where tag_cs = ?';
        $res = $this->sqlite->query($sql,$tag);
        $tagId = $this->sqlite->res2single($res);
        if(!$tagId){
            return array();
        }

        $res = $this->sqlite->query('select distinct p.year, p.series, p.problem from problem p left join problem_tag pt on pt.problem_id = p.problem_id where pt.tag_id = ? order by 1 desc, 2 desc, 3 asc',$tagId);
        $result = array();
        foreach ($this->sqlite->res2arr($res) as $row) {
            $result[] = array($row['year'],$row['series'],$row['problem']);
        }
        return $result;
    }

    final public function getSpecLang($id,$lang = null) {
        global $conf;
        if(!$lang || $lang == $conf['lang']){
            return $this->getLang($id);
        }

        $this->localised = false;
        $confLang = $conf['lang'];
        $conf['lang'] = $lang;
        $l = $this->getLang($id);
        $conf['lang'] = $confLang;
        $this->localised = false;
        return $l;
    }

    public function getImagePath($year,$series,$problem,$lang,$type=null) {
        if($type){
            return $this->getPluginName().':figure:year'.$year.'_series'.$series.'_'.$problem.'_'.$lang.'.'.$type;
        }
        return $this->getPluginName().':figure:year'.$year.'_series'.$series.'_'.$problem.'_'.$lang;
    }

    public function prepareContent($data,$templatePage) {
        global $ID;

        $templateFile = wikiFN($templatePage);
        $templateString = io_readFile($templateFile);
        foreach ($data as $key => $value) {

            switch ($key) {

                case 'points':
                    if($value == 1){
                        $l = $this->getSpecLang('points-N-SG_vote',$data['lang']);
                    }elseif($value > 0 && $value < 5){
                        $l = $this->getSpecLang('points-N-PL_vote',$data['lang']);
                    }else{
                        $l = $this->getSpecLang('points-G-PL_vote',$data['lang']);
                    }
                    $value = $value." ".$l;
                    break;

                case 'label':
                    $templateString = str_replace("@human-$key@",$this->getSpecLang($key,$data['lang']).' '.$value,$templateString);
                    break;
                case 'series':
                case 'year':
                    $s = ($key == 'year') ? 's' : "";
                    $templateString = str_replace("@human-$key@",$value.'. '.$this->getSpecLang($key.$s,$data['lang']),$templateString);
                    break;


                case 'figure':

                    $value = '{{'.$this->getImagePath($data['year'],$data['series'],$data['number'],$data['lang']).' |}}';
                    break;

                default :
                    break;
            }
            $templateString = str_replace("@$key@",$value,$templateString);
        }
        $tags = $this->loadTags($data['year'],$data['series'],$data['label']);
        $t = "";
        foreach ($tags as $tag) {
            $t.= '[['.wl($ID,array(syntax_plugin_fkstaskrepo_table::URL_PARAM => $tag)).'|'.hsc($this->getSpecLang('tag__'.$tag,$data['lang'])).']] ';
        }
        $templateString = str_replace("@tags@",$t,$templateString);
        $templateString = str_replace("@figure@","",$templateString);


        return p_get_instructions($templateString);
    }

}

class fkstaskrepo_exception extends RuntimeException {
    
}

// vim:ts=4:sw=4:et:
