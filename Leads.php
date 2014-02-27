<?php

/**
 * leads Extension for Contao Open Source CMS
 *
 * @copyright  Copyright (c) 2011-2014, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 * @link       http://github.com/terminal42/contao-leads
 */

use \Haste\File\CsvWriter;

class Leads extends Controller
{

    /**
     * Prepare a form value for storage in lead table
     * @param mixed
     * @param Database_Result
     */
    public static function prepareValue($varValue, $objField)
    {
        // Run for all values in an array
        if (is_array($varValue)) {
            foreach ($varValue as $k => $v) {
                $varValue[$k] = self::prepareValue($v, $objField);
            }

            return $varValue;
        }

        // Convert date formats into timestamps
        if ($varValue != '' && in_array($objField->rgxp, array('date', 'time', 'datim'))) {
            $objDate = new Date($varValue, $GLOBALS['TL_CONFIG'][$objField->rgxp . 'Format']);
            $varValue = $objDate->tstamp;
        }

        return $varValue;
    }


    /**
     * Get the label for a form value to store in lead table
     * @param mixed
     * @param array
     * @param Database_Result
     */
    public static function prepareLabel($varValue, $arrOptions, $objField)
    {
        // Run for all values in an array
        if (is_array($varValue)) {
            foreach ($varValue as $k => $v) {
                $varValue[$k] = self::prepareLabel($v, $arrOptions, $objField);
            }

            return $varValue;
        }

        foreach ($arrOptions as $arrOption) {
            if ($arrOption['value'] == $varValue && $arrOption['label'] != '') {
                return $arrOption['label'];
            }
        }

        return $varValue;
    }


    /**
     * Format a lead field for list view
     * @param object
     * @return string
     */
    public static function formatValue($objData)
    {
        $strValue = implode(', ', deserialize($objData->value, true));

        if ($objData->label != '') {
            $strLabel = $objData->label;
            $arrLabel = deserialize($objData->label);

            if (is_array($arrLabel) && !empty($arrLabel)) {
                $strLabel = implode(', ', $arrLabel);
            }

            $strValue = $strLabel . ' (' . $strValue . ')';
        }

        return $strValue;
    }


    /**
     * Dynamically load the name for the current lead view
     * @param string
     * @param string
     */
    public function loadLeadName($strName, $strLanguage)
    {
        if ($strName == 'modules' && $this->Input->get('do') == 'lead') {
            $objForm = \Database::getInstance()->prepare("SELECT * FROM tl_form WHERE id=?")->execute($this->Input->get('master'));

            $GLOBALS['TL_LANG']['MOD']['lead'][0] = $objForm->leadMenuLabel ? $objForm->leadMenuLabel : $objForm->title;
        }
    }


    /**
     * Add leads to the backend navigation
     * @param array
     * @param bool
     * @return array
     */
    public function loadBackendModules($arrModules, $blnShowAll)
    {
        if (!\Database::getInstance()->tableExists('tl_lead')) {
            unset($arrModules['leads']);
            return $arrModules;
        }

        $objForms = \Database::getInstance()->execute("
                SELECT f.id, f.title, IF(f.leadMenuLabel='', f.title, f.leadMenuLabel) AS leadMenuLabel
                FROM tl_form f
                LEFT JOIN tl_lead l ON l.master_id=f.id
                WHERE leadEnabled='1' AND leadMaster=0
            UNION
                SELECT l.master_id AS id, IFNULL(f.title, CONCAT('ID ', l.master_id)) AS title, IFNULL(IF(f.leadMenuLabel='', f.title, f.leadMenuLabel), CONCAT('ID ', l.master_id)) AS leadMenuLabel
                FROM tl_lead l
                LEFT JOIN tl_form f ON l.master_id=f.id
                WHERE ISNULL(f.id)
                ORDER BY leadMenuLabel
        ");

        if (!$objForms->numRows) {
            unset($arrModules['leads']);
            return $arrModules;
        }

        $arrSession = $this->Session->get('backend_modules');
        $blnOpen = $arrSession['leads'] || $blnShowAll;
        $arrModules['leads']['modules'] = array();

        if ($blnOpen) {
            while ($objForms->next()) {

                $arrModules['leads']['modules']['lead_'.$objForms->id] = array(
                    'tables'    => array('tl_lead'),
                    'title'     => specialchars(sprintf($GLOBALS['TL_LANG']['MOD']['leads'][1], $objForms->title)),
                    'label'     => $objForms->leadMenuLabel,
                    'icon'      => 'style="background-image:url(\'system/modules/leads/assets/icon.png\')"',
                    'class'     => 'navigation leads',
                    'href'      => 'contao/main.php?do=lead&master='.$objForms->id,
                );
            }
        } else {
            $arrModules['leads']['modules'] = false;
            $arrModules['leads']['icon'] = 'modPlus.gif';
            $arrModules['leads']['title'] = specialchars($GLOBALS['TL_LANG']['MSC']['expandNode']);
        }

        return $arrModules;
    }


    /**
     * Process data submitted through the form generator
     * @param array
     * @param array
     * @param array
     */
    public function processFormData(&$arrPost, &$arrForm, &$arrFiles)
    {
        if ($arrForm['leadEnabled']) {
            $time = time();

            $intLead = \Database::getInstance()->prepare("
                INSERT INTO tl_lead (tstamp,created,language,form_id,master_id,member_id) VALUES (?,?,?,?,?,?)
            ")->executeUncached(
                $time,
                $time,
                $GLOBALS['TL_LANGUAGE'],
                $arrForm['id'],
                ($arrForm['leadMaster'] ? $arrForm['leadMaster'] : $arrForm['id']),
                (FE_USER_LOGGED_IN === true ? \FrontendUser::getInstance()->id : 0)
            )->insertId;


            // Fetch master form fields
            if ($arrForm['leadMaster'] > 0) {
                $objFields = \Database::getInstance()->prepare("SELECT f2.*, f1.id AS master_id, f1.name AS postName FROM tl_form_field f1 LEFT JOIN tl_form_field f2 ON f1.leadStore=f2.id WHERE f1.pid=? AND f1.leadStore>0 AND f2.leadStore='1' ORDER BY f2.sorting")->execute($arrForm['id']);
            } else {
                $objFields = \Database::getInstance()->prepare("SELECT *, id AS master_id, name AS postName FROM tl_form_field WHERE pid=? AND leadStore='1' ORDER BY sorting")->execute($arrForm['id']);
            }

            while ($objFields->next()) {

                if (isset($arrPost[$objFields->postName])) {
                    $varLabel = '';
                    $varValue = $arrPost[$objFields->postName];

                    if ($objFields->options != '') {
                        $arrOptions = deserialize($objFields->options, true);
                        $varLabel = Leads::prepareLabel($varValue, $arrOptions, $objFields);
                    }

                    $varValue = Leads::prepareValue($varValue, $objFields);

                    $arrSet = array(
                        'pid'            => $intLead,
                        'sorting'        => $objFields->sorting,
                        'tstamp'        => $time,
                        'master_id'        => $objFields->master_id,
                        'field_id'        => $objFields->id,
                        'name'            => $objFields->name,
                        'value'            => $varValue,
                        'label'            => $varLabel,
                    );

                    // HOOK: add custom logic
                    if (isset($GLOBALS['TL_HOOKS']['modifyLeadsDataOnStore']) && is_array($GLOBALS['TL_HOOKS']['modifyLeadsDataOnStore'])) {
                        foreach ($GLOBALS['TL_HOOKS']['modifyLeadsDataOnStore'] as $callback) {
                            $this->import($callback[0]);
                            $this->$callback[0]->$callback[1]($arrPost, $arrForm, $arrFiles, $intLead, $objFields, $arrSet);
                        }
                    }

                    \Database::getInstance()->prepare("INSERT INTO tl_lead_data %s")->set($arrSet)->executeUncached();
                }
            }

            // HOOK: add custom logic
            if (isset($GLOBALS['TL_HOOKS']['storeLeadsData']) && is_array($GLOBALS['TL_HOOKS']['storeLeadsData'])) {
                foreach ($GLOBALS['TL_HOOKS']['storeLeadsData'] as $callback) {
                    $this->import($callback[0]);
                    $this->$callback[0]->$callback[1]($arrPost, $arrForm, $arrFiles, $intLead, $objFields);
                }
            }
        }
    }


    /**
     * Export data to CSV or excel
     * @param int master id
     * @param string type (excel or csv [default])
     * @param array lead data ids (optional)
     *
     */
    public function export($intMaster, $strType='csv', $arrIds=null)
    {
        $objFields = \Database::getInstance()->prepare("
            SELECT
                ld.master_id AS id,
                IFNULL(ff.name, ld.name) AS name,
                IF(ff.label IS NULL OR ff.label='', ld.name, ff.label) AS label
            FROM tl_lead_data ld
            LEFT JOIN tl_form_field ff ON ff.id=ld.master_id
            WHERE ld.pid IN (SELECT id FROM tl_lead WHERE master_id=?)
            GROUP BY ld.master_id
            ORDER BY IFNULL(ff.sorting, ld.sorting)
        ")->executeUncached($intMaster);

        $arrHeader = array();

        // Add header fields
        while ($objFields->next()) {
            $arrHeader[] = $objFields->label;
            $arrFields[] = $objFields->id;
        }

        // Add base information columns
        array_unshift($arrHeader, $GLOBALS['TL_LANG']['tl_lead']['member'][0]);
        array_unshift($arrHeader, $GLOBALS['TL_LANG']['tl_lead']['form_id'][0]);
        array_unshift($arrHeader, $GLOBALS['TL_LANG']['tl_lead']['created'][0]);

        $strWhere = '';

        if (is_array($arrIds) && !empty($arrIds)) {
            $strWhere = ' WHERE l.id IN(' . implode(',', $arrIds) . ')';
        }

        $arrData = array();
        $objData = \Database::getInstance()->query("
            SELECT
                ld.*,
                l.created,
                (SELECT title FROM tl_form WHERE id=l.form_id) AS form_name,
                IFNULL((SELECT CONCAT(firstname, ' ', lastname) FROM tl_member WHERE id=l.member_id), '') AS member_name
            FROM tl_lead_data ld
            LEFT JOIN tl_lead l ON l.id=ld.pid$strWhere
            ORDER BY l.created DESC
        ");

        while ($objData->next()) {
            $arrData[$objData->pid][$objData->master_id] = $objData->row();
        }

        $objDataProvider = new CsvWriter\DataProvider\ArrayProvider($arrData);
        $objDataProvider->setHeaderFields($arrHeader);
        $objCsv = new CsvWriter\CsvWriter($objDataProvider);
        $objCsv->enableHeaderFields();

        $objCsv->download('', function($arrFieldData) use ($arrFields) {
            $arrRow = array();

            $arrFirst = reset($arrFieldData);
            $arrRow[] = \Date::parse($GLOBALS['TL_CONFIG']['datimFormat'], $arrFirst['created']);
            $arrRow[] = $arrFirst['form_name'];
            $arrRow[] = $arrFirst['member_name'];

            foreach ($arrFields as $intField) {
                $arrRow[] = Leads::formatValue((object) $arrFieldData[$intField]);
            }

            return $arrRow;
        });
    }
}
