<?php
/**
 * Default settings for the fkstaskrepo plugin
 *
 * @author Michal Koutný <michal@fykos.cz>
 */

$conf['remote_path_mask']     = '/vyfuk%1$s/batch%2$s/out/zadaniWeb%2$s.xml';

$conf['series_template'] = 'system:series_template';
$conf['task_template'] = 'system:task_template';
$conf['task_template_search'] = 'system:task_template';

$conf['page_path_mask_cs'] = "rocnik@year@:ulohy:serie@series@";
$conf['page_path_mask_en'] = "year@year@:tasks:series@series@";

$conf['brochure_path_cs']="rocnik@year@:ulohy:serie@series@.pdf";
$conf['brochure_path_en']="year@year@:tasks:series@series@.pdf";

$conf['solution_path_cs']=":rocnik@year@:ulohy:problem@label@.pdf";
$conf['solution_path_en']=":year@year@:tasks:problems@label@.pdf";

$conf['archive_path_cs']='ulohy:start';
$conf['archive_path_en']='ulohy:start';

$conf['im_convert']='';
