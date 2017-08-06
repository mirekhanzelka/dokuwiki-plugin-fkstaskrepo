<?php

/**
 * DokuWiki Plugin fkstaskrepo (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michal Koutný <michal@fykos.cz>
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class admin_plugin_fkstaskrepo extends DokuWiki_Admin_Plugin {

    const DEFAULT_LANGUAGE = 'cs';
    static $availableVersions = [1];

    /**
     *
     * @var helper_plugin_fkstaskrepo
     */
    private $helper;

    public function __construct() {
        $this->helper = $this->loadHelper('fkstaskrepo');
    }

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort() {
        return 10;
    }

    /**
     * @return bool true if only access for superuser, false is for superusers and moderators
     */
    public function forAdminOnly() {
        return false;
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle() {
        global $INPUT;
        $year = $INPUT->int('year', null);
        $series = $INPUT->int('series', null);
        $language = $INPUT->str('language', null);

        if ($year !== null && $series !== null && $language !== null) {
            if ($language == "ALL") {
                $language = null;
            }
            if ($_FILES['xml_file'] && $_FILES['xml_file']['name']) {
                if ($_FILES['xml_file']['error'] > 0) {
                    msg('Upload failed.', -1);
                    return;
                }
                $content = file_get_contents($_FILES['xml_file']['tmp_name']);
            } else {
                $content = $this->helper->getSeriesData($year, $series, helper_plugin_fksdownloader::EXPIRATION_FRESH);
                if (!$content) {
                    return;
                }
            }
            $this->processSeries($content, $year, $series, $language);
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html() {
        global $ID;
        ptln('<h1>' . $this->getLang('menu') . '</h1>');
        $form = new \dokuwiki\Form\Form();

        $form->attrs(['class' => $this->getPluginName(), 'enctype' => 'multipart/form-data']);
        $form->addFieldsetOpen($this->getLang('update'));
        $form->setHiddenField('id', $ID);
        $form->setHiddenField('do', 'admin');
        $form->addTextInput('year', $this->getLang('year'))->attr('pattern', '[0-9]+');
        $form->addTextInput('series', $this->getLang('series'))->attr('pattern', '[0-9]+');
        $form->addDropdown('language', ['ALL', 'cs', 'en'], $this->getLang('language'));

        $form->addElement(new \dokuwiki\Form\InputElement('file', 'xml_file', $this->getLang('xml_file')));
        $form->addCheckbox('hard', $this->getLang('hard_update'));
        $form->addButton('submit', $this->getLang('update'));

        $form->addFieldsetClose();
        echo $form->toHTML();
    }

    private function processSeries($content, $year, $series, $language) {
        global $INPUT;

        $seriesXML = simplexml_load_string($content);
        if (!in_array((int)$seriesXML->attributes()->version, self::$availableVersions)) {
            msg('Version not supported', -1);
            return;
        };

        $deadline = $seriesXML->deadline;
        $deadline_post = $seriesXML->{'deadline-post'};

        if ($INPUT->int('hard') == 0) {
            if ((int)$seriesXML->number != $series) {
                msg('Series must be same as in the XML', -1);
                return;
            }
            $m = [];
            preg_match('/[0-9]+/', $seriesXML->contest, $m);

            if ((int)$m[0] != $year) {
                msg('Year must be same as in the XML', -1);
                return;
            }
        }
        $languages = $this->getLanguages($seriesXML);
        foreach ($languages as $lang) {
            //$this->extractProblem($seriesXML, $lang);

            if ($language && $lang != $language) {
                continue;
            }
            $pagePath = sprintf($this->getConf('page_path_mask_' . $lang), $year, $series);
            if ($pagePath == "") {
                msg('No page path defined for language ' . $lang, -1);
                continue;
            }
            $this->setTranslations($pagePath, $year, $series, $languages, $lang);
            $pageTemplate = io_readFile(wikiFN($this->getConf('series_template')));

            $pageContent = $this->replaceVariables([
                'human-series' => $series . '. ' . $this->helper->getSpecLang('series', $lang),
                'label' => '@label@',
                'brochure_path' => str_replace(['@year@', '@series@'],
                    [
                        $year,
                        $series
                    ],
                    $this->getConf('brochure_path_' . $lang)),
                'human-deadline' => $this->helper->getSpecLang('deadline', $lang) . ': ' .
                    date($this->helper->getSpecLang('deadline-format', $lang), strtotime($deadline)),
                'human-deadline-post' => $this->helper->getSpecLang('deadline-post', $lang) . ': ' .
                    date($this->helper->getSpecLang('deadline-post-format', $lang), strtotime($deadline_post)),
                'brochure' => $this->helper->getSpecLang('brochure', $lang),

                'human-year' => $year . '. ' . $this->helper->getSpecLang('years', $lang),
                'lang' => $lang,
                'year' => $year,
                'series' => $series,
            ],
                $pageTemplate);

            $pageContent = $this->replaceProblems($pageContent, $seriesXML, $year, $series, $lang);

            io_saveFile(wikiFN($pagePath), $pageContent);
            msg(sprintf('Updated <a href="%s">%s</a>.', wl($pagePath, null, true), $pagePath));
        }

    }

    private function replaceProblems($pageContent, SimpleXMLElement $seriesXML, $year, $series, $lang) {
        $that = $this;
        return preg_replace_callback('/--\s*problem\s--(.*)--\s*endproblem\s*--/is',
            function () use ($seriesXML, $that, $year, $series, $lang) {
                $problemsString = '';
                /**
                 * @var $problem SimpleXMLElement
                 */
                foreach ($seriesXML->problems->children() as $problem) {
                    $problemsString .= $this->prepareProblem($problem, $year, $series, $lang);
                }
                return $problemsString;
            },
            $pageContent);
    }

    private function prepareProblem(SimpleXMLElement $problem, $year, $series, $lang) {
        // preprocess figure
        $figure = $this->helper->extractFigure($problem, $lang);
        $figuresPaths = $this->saveFigure($figure, $year, $series, (string)$problem->label, $lang);

        $problemData = [];
        /**
         * @var $child SimpleXMLElement
         */
        foreach ($problem->children() as $k => $child) {
            if ($this->helper->isActualLang($child, $lang)) {
                /* is array? */
                if ($child->count()) {
                    $problemData[$k] = (array)$child->children();
                } else {
                    if ((trim((string)$child) != "")) {
                        $problemData[$k] = (string)$child;
                    }
                }
            }
        }
        $problemData['figures'] = $figuresPaths;
        $problemData['task'] = $this->helper->texPreproc->preproc($problemData['task']);
        $this->helper->updateProblemData($problemData, $year, $series, $problemData['label'], $lang);
        $this->helper->storeTags($year, $series, $problemData['label'], (array)$problem->topics->topic);
        return '<fkstaskrepo lang="' . $lang . '" year="' . $year . '" series="' . $series . '" problem="' .
        $problemData['label'] . '"/>' . "\n";

    }

    private function saveFigure($figure, $year, $series, $label, $lang) {
        $figuresPaths = [];
        if (!empty($figure) && !empty($figure['data'])) {
            foreach ($figure['data'] as $type => $imgContent) {
                if (!$type) {
                    msg('invalid or empty extenson figure: ' . $type, -1);
                    continue;
                }
                $name = $this->helper->getImagePath($year, $series, $label, $lang, $type);
                if (io_saveFile(mediaFN($name), (string)trim($imgContent))) {
                    msg('image: ' . $name . ' for language ' . $lang . ' has been saved', 1);
                }
                $figuresPaths[] = ['path' => $name, 'caption' => $figure['caption']];
            }
        }
        return $figuresPaths;
    }

    private function setTranslations($pagePath, $year, $series, $languages, $lang) {
        global $INFO;

        $file = wikiFN($pagePath);
        $created = @filectime($file);
        $meta = [];
        $meta['date']['created'] = $created;
        $user = $_SERVER['REMOTE_USER'];
        if ($user) $meta['creator'] = $INFO['userinfo']['name'];
        if ($lang != self::DEFAULT_LANGUAGE) {
            ${self::DEFAULT_LANGUAGE . 'Path'} = sprintf($this->getConf('page_path_mask_' . self::DEFAULT_LANGUAGE),
                $year,
                $series);

            $meta['relation']['istranslationof'][${self::DEFAULT_LANGUAGE . 'Path'}] = self::DEFAULT_LANGUAGE;
        } else {
            $meta['relation']['translations'] = [];
            foreach ($languages as $l) {
                $meta['relation']['translations'][sprintf($this->getConf('page_path_mask_' . $l), $year, $series)] = $l;
            }

        }
        $meta['language'] = $lang;

        p_set_metadata($pagePath, $meta);
    }

    private function getLanguages(SimpleXMLElement $seriesXML) {
        $languages = [];
        /**
         * @var $e SimpleXMLElement
         */
        foreach ($seriesXML->problems->xpath('*/*[@xml:lang]') as $e) {
            $l = (string)$e->attributes('http://www.w3.org/XML/1998/namespace')->lang;
            if (!in_array($l, $languages)) {
                $languages[] = $l;
            }
        }
        if (count($languages) == 0) {
            msg('No language found!!', -1);
        }
        return $languages;
    }

    private function replaceVariables($parameters, $template) {
        $that = $this;

        $result = preg_replace_callback('/@([^@]+)@/',
            function ($match) use ($parameters, $that) {
                $key = $match[1];
                if (!isset($parameters[$key])) {
                    msg(sprintf($that->getLang('undefined_template_variable'), $key));
                    return '';
                } else {
                    return $parameters[$key];
                }
            },
            $template);
        return $result;
    }
}