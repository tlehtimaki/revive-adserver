<?php

/*
+---------------------------------------------------------------------------+
| OpenX v${RELEASE_MAJOR_MINOR}                                                                |
| =======${RELEASE_MAJOR_MINOR_DOUBLE_UNDERLINE}                                                                |
|                                                                           |
| Copyright (c) 2003-2009 OpenX Limited                                     |
| For contact details, see: http://www.openx.org/                           |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/

require_once MAX_PATH . '/lib/max/other/common.php';
require_once LIB_PATH . '/Plugin/Component.php';
require_once MAX_PATH . '/lib/max/Plugin/Translation.php';
require_once MAX_PATH . '/lib/OA.php';
require_once MAX_PATH . '/lib/OA/Admin/DaySpan.php';
require_once MAX_PATH . '/lib/OA/Admin/Statistics/Factory.php';
require_once MAX_PATH . '/lib/OA/Permission.php';

/**
 * Error codes used by report plugins
 */
define('PLUGINS_REPORTS_MISSING_SHEETS_ERROR' , 1);


/**
 * Plugins_Reports is an abstract class that defines an interface for every
 * report plugin to implement.
 *
 * @abstract
 * @package    OpenXPlugin
 * @subpackage Reports
 * @author     Andrew Hill <andrew.hill@openx.org>
 * @author     Radek Maciaszek <radek@m3.net>
 * @author     Robert Hunter <roh@m3.net>
 */
class Plugins_Reports extends OX_Component
{

    /**
     * The name of the plugin.
     *
     * @var string
     */
    var $_name;

    /**
     * The description of the plugin.
     *
     * @var string
     */
    var $_description;

    /**
     * The report category (eg. "admin", "standard").
     *
     * @var string
     */
    var $_category;

    /**
     * The name of the report category.
     *
     * @var string
     */
    var $_categoryName;

    /**
     * The name of the author.
     *
     * @var string
     */
    var $_author;

    /**
     * The format the report is returned as (eg. "xls").
     *
     * @var string
     */
    var $_export;

    /**
     * The users authorised to run the report (eg. array(OA_ACCOUNT_ADMIN,
     * OA_ACCOUNT_MANAGER), etc).
     *
     * @var integer
     */
    var $_authorize;

    /**
     * An array containing the details required to display the
     * report's input value form in the UI.
     *
     * @var array
     */
    var $_import;

    /**
     * The report writer to use for generating reports.
     *
     * @var object
     */
    var $_oReportWriter;

    /**
     * The common OA_Admin_DaySpan object used by all reports to
     * limit the time range of the report.
     *
     * @var OA_Admin_DaySpan
     */
    var $_oDaySpan;

    /**
     * A string representation of the start date of the report.
     *
     * @var string
     */
    var $_startDateString;

    /**
     * A string representation of the end date of the report.
     *
     * @var string
     */
    var $_endDateString;

    /**
     * A variable to decide if the big red TZ inaccuracy warning box should be displayed
     *
     * @var bool
     */
    var $_displayInaccurateStatsWarning = false;

    /**
     * A public method to call an implemented report's initInfo() method,
     * and then return the plugin's information array as generated by the
     * initInfo() method.
     *
     * @return array The array generated by the
     *               {@link Plugins_Reports::infoArray()} method.
     */
    function info()
    {
        $this->initInfo();
        return $this->infoArray();
    }

    /**
     * An abstract method that MUST be implemented in every plugin, to set the
     * plugin's private variables with the required details about the plugin.
     *
     * @abstract
     */
    function initInfo() {}

    /**
     * An abstract method that MUST be implemented in every plugin, which must
     * return an array of the required information for laying out the plugin's
     * report generation screen/the variables required for generating the report.
     *
     * @abstract
     */
    function getDefaults() {}

    /**
     * An abstract method that MUST be implemented in every plugin, to save
     * the values used for the report by the user to the user's session
     * preferences, so that they can be re-used in other reports.
     *
     * @abstract
     */
    function saveDefaults() {}

    /**
     * An abstract method that MUST be implemented in every plugin, to execute
     * the plugin, generating the required report.
     *
     * @abstract
     * @return void|int - Return error code on errors
     */
    function execute() {}

    /**
     * Return error message for given error code
     *
     * @param int $errorCode
     * @return string
     */
    function getErrorMessage($errorCode) {
        switch ($errorCode) {
           case PLUGINS_REPORTS_MISSING_SHEETS_ERROR :
               return $GLOBALS['strReportErrorMissingSheets'];
           default :
               return $GLOBALS['strReportErrorUnknownCode'].htmlentities($errorCode);
        }
    }

    /**
     * An abstract method that MUST be implemented in every plugin, to return
     * an array of index/value strings to display as sub-headings in report
     * worksheets.
     *
     * @abstract
     * @access private
     * @return array The array of index/value sub-headings.
     */
    function _getReportParametersForDisplay() {}

    /**
     * A public method to return the required information about the report plugin.
     *
     * @return array An array providing information about the report class.
     *               The details required to be set in the array are:
     *                  'plugin-name'          => The (translated) name of the plugin
     *                  'plugin-description'   => The (translated) description of the plugin
     *                  'plugin-category'      => The report category (eg. admin, advertiser,
     *                                            agency, publisher)
     *                  'plugin-category-name' => The (translated) name of the report category
     *                  'plugin-author'        => The name of the author
     *                  'plugin-export'        => The format the report is returned as
     *                  'plugin-authorize'     => The users authorised to run the report (eg. OA_ACCOUNT_ADMIN,
     *                                            OA_ACCOUNT_MANAGER, etc)
     *                  'plugin-import'        => An array containing the details required to display the
     *                                            report's input value form in the UI
     */
    function infoArray()
    {
        $this->initInfo();
        include_once MAX_PATH . '/lib/max/Plugin/Translation.php';
        MAX_Plugin_Translation::init($this->module, $this->package);
        $aPluginInfo = array (
            "plugin-name"          => MAX_Plugin_Translation::translate($this->_name, $this->module, $this->package),
            "plugin-description"   => MAX_Plugin_Translation::translate($this->_description, $this->module, $this->package),
            'plugin-category'      => $this->_category,
            'plugin-category-name' => MAX_Plugin_Translation::translate($this->_categoryName, $this->module, $this->package),
            "plugin-author"        => $this->_author,
            "plugin-export"        => $this->_export,
            "plugin-authorize"     => $this->_authorize,
            "plugin-import"        => $this->_import
        );
        return $aPluginInfo;
    }

    /**
     * A method to determine if a report can be executed by a user or not.
     *
     * @return boolean True if the report is allowed to be executed by the
     *                 current user, false otherwise.
     */
    function isAllowedToExecute()
    {
        // Backwards-compatible way of pulling authorization
        $aInfo = $this->info();
        $authorizedUserTypes = $aInfo['plugin-authorize'];
        return OA_Permission::isAccount($authorizedUserTypes);
    }

    /**
     * A method to set the report writer object to use when generating reports.
     *
     * @param object $oWriter The report writer to use.
     */
    function useReportWriter(&$oWriter)
    {
        $this->_oReportWriter =& $oWriter;
    }

    /**
     * A private method to prepare the report range information from an
     * OA_Admin_DaySpan object.
     *
     * @access private
     * @param OA_Admin_DaySpan $oDaySpan The OA_Admin_DaySpan object to set
     *                                   the report range information from.
     */
    function _prepareReportRange($oDaySpan)
    {
        global $date_format;
        if (!empty($oDaySpan)) {
            $this->_oDaySpan        = $oDaySpan;
            $this->_startDateString = $oDaySpan->getStartDateString($date_format);
            $this->_endDateString   = $oDaySpan->getEndDateString($date_format);
        } else {
            $oDaySpan               = new OA_Admin_DaySpan();

            // take as the start date the date when adds were serverd
            $aConf = $GLOBALS['_MAX']['CONF'];
            $oDbh = OA_DB::singleton();
            $query = "SELECT MIN(date_time) as min_datetime FROM ". $oDbh->quoteIdentifier($aConf['table']['prefix'].$aConf['table']['data_summary_ad_hourly'],true) . " WHERE 1=1";
            $startDate = $oDbh->queryRow($query);
            $startDate = $startDate['min_datetime'];
            $oStartDate = new Date($startDate);

            $oEndDate               = new Date();
            $oDaySpan->setSpanDays($oStartDate, $oEndDate);
            $this->_oDaySpan        = &$oDaySpan;
            $this->_startDateString = MAX_Plugin_Translation::translate('Beginning', $this->module, $this->package);
            $this->_endDateString   = $oDaySpan->getEndDateString($date_format);
        }

        $utcUpdate = OA_Dal_ApplicationVariables::get('utc_update');
        if (!empty($utcUpdate)) {
            $oUpdate = new Date($utcUpdate);
            $oUpdate->setTZbyID('UTC');
            // Add 12 hours
            $oUpdate->addSeconds(3600 * 12);

            $startDate = new Date($oDaySpan->oStartDate);
            $endDate   = new Date($oDaySpan->oEndDate);

            if ($oUpdate->after($endDate) || $oUpdate->after($startDate)) {
                $this->_displayInaccurateStatsWarning = true;
            }
        }
    }

    /**
     * A private method to prepare the output filename for a plugin report.
     *
     * @access private
     * @return string The string name of the report.
     */
    function _getReportFileName()
    {
        $reportFileName = '';
        $reportFileName .= MAX_Plugin_Translation::translate(html_entity_decode($this->_name, null, 'UTF-8'), $this->module, $this->package);
        $reportFileName .= ' ';
        $reportFileName .= $GLOBALS['strFrom'];
        $reportFileName .= ' ';
        $reportFileName .= $this->_startDateString;
        $reportFileName .= ' ';
        $reportFileName .= $GLOBALS['strTo'];
        $reportFileName .= ' ';
        $reportFileName .= $this->_endDateString;
        $reportFileName .= '.';
        $reportFileName .= $this->_export;

        return $reportFileName;
    }

    /**
     * A private method to create a new report worksheet and fill it with
     * the supplied tabular data.
     *
     * @param string $worksheet The name of the worksheet to be created.
     * @param array  $aHeaders  An array of column headings for the data.
     * @param array  $aData     An array of arrays of data.
     * @param string $title     An optional title for the worksheet.
     */
    function createSubReport($worksheet, $aHeaders, $aData, $title = '')
    {
        // Use the name of the worksheet as the worksheet's title, if
        // no title supplied
        if ($title == '') {
            $title = $worksheet;
        }

        // check if worksheet name is <= 31 chracters, if so trim because PEAR errors out
        if (strlen($worksheet) >= 31) {
            $worksheet  = substr($worksheet, 0, 30);
        }

        $this->_oReportWriter->createReportWorksheet(
            $worksheet,
            $this->_name,
            $this->_getReportParametersForDisplay(),
            $this->_getReportWarningsForDisplay()
        );
        $this->_oReportWriter->createReportSection($worksheet, $title, $aHeaders, $aData, 30, null);
    }

    /**
     * A private method to return an array containing the start and end dates
     * of a report in a format that is suitable for display in a worksheet's
     * sub-heading.
     *
     * @access private
     * @return array An array containing the Start Date and End Date, if required.
     */
    function _getDisplayableParametersFromDaySpan()
    {
        $aParams = array();
        if (!is_null($this->_oDaySpan)) {
            global $date_format;
            $aParams[MAX_Plugin_Translation::translate('Start Date', $this->module, $this->package)] =
                $this->_oDaySpan->getStartDateString($date_format);
            $aParams[MAX_Plugin_Translation::translate('End Date', $this->module, $this->package)] =
                $this->_oDaySpan->getEndDateString($date_format);
        }
        return $aParams;
    }

    /**
     * A method to obtain statistics for reports from the same statistics controllers
     * that are used in the UI, but without formatting or paging data, and return
     * the section headers and data independently.
     *
     * @param string $controllerType The required OA_Admin_Statistics_Common type.
     * @param OA_Admin_Statistics_Common $oStatsController An optional parameter to pass in a
     *              ready-prepared stats controller object, to be used instead of creating
     *              and populating the stats.
     * @return array An array containing headers (key 0) and data (key 1)
     */
    function getHeadersAndDataFromStatsController($controllerType, $oStatsController = null)
    {
        if (is_null($oStatsController)) {
            $oStatsController = &OA_Admin_Statistics_Factory::getController(
                $controllerType,
                array(
                    'skipFormatting' => true,
                    'disablePager'   => true
                )
            );
            if (PEAR::isError($oStatsController)) {
                return array('Unkcown Stats Controller ', array ($oStatsController->getMessage()));
            }
            $oStatsController->start();
        }
        $aStats = $oStatsController->exportArray();
        $aHeaders = array();
        foreach ($aStats['headers'] as $k => $v) {
            switch ($aStats['formats'][$k]) {
                case 'default':
                    $aHeaders[$v] = 'numeric';
                    break;
                case 'currency':
                    $aHeaders[$v] = 'decimal';
                    break;
                case 'percent':
                case 'date':
                case 'time':
                    $aHeaders[$v] = $aStats['formats'][$k];
                    break;
                case 'text':
                default:
                    $aHeaders[$v] = 'text';
                    break;
            }
        }
        $aData = array();
        foreach ($aStats['data'] as $i => $aRow) {
            foreach ($aRow as $k => $v) {
                $aData[$i][] = $aStats['formats'][$k] == 'datetime' ? $this->_oReportWriter->convertToDate($v) : $v;
            }
        }
        return array($aHeaders, $aData);
    }

    function _getReportWarningsForDisplay()
    {
        $aWarnings = array();
        if ($this->_displayInaccurateStatsWarning) {
            $aWarnings[] = $GLOBALS['strWarningInaccurateReport'];
        }

        return $aWarnings;
    }
}

?>
