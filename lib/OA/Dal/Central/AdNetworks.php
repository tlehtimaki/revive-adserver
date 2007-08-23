<?php

/*
+---------------------------------------------------------------------------+
| Openads v2.5                                                              |
| ============                                                              |
|                                                                           |
| Copyright (c) 2003-2007 Openads Limited                                   |
| For contact details, see: http://www.openads.org/                         |
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

require_once MAX_PATH.'/lib/OA/Dal/Central/Common.php';


/**
 * Dal methods for the ad networks OAC API
 *
 */
class OA_Dal_Central_AdNetworks extends OA_Dal_Central_Common
{
    /**
     * A method to delete entities in a single batch
     *
     * @param array $aCreated Keys are the entity name (publishers, advertisers,
     *                        campaigns, banners, zones) and values are an array
     *                        of the IDs that should be deleted
     */
    function undoEntities($aCreated)
    {
        $aEntities = array(
            'publishers'  => array('affiliates', 'affiliateid'),
            'advertisers' => array('clients', 'clientid'),
            'campaigns'   => array('campaigns', 'campaignid'),
            'banners'     => array('banners', 'bannerid'),
            'zones'       => array('zones', 'zoneid')
        );

        foreach (array_keys($aCreated) as $entity) {
            if (isset($aCreated[$entity]) && count($aCreated[$entity])) {
                $doEntity = OA_Dal::factoryDO($aEntities[$entity][0]);
                $doEntity->whereInAdd($aEntities[$entity][1], $aCreated[$entity]);
                $doEntity->delete(true);
            }
        }
    }

    /**
     * A method to get an array which maps OAC banner IDs to OAP banner IDs
     *
     * @param array $bannerIds The OAC banner IDs
     * @return array OAC to OAP map array
     */
    function getBannerIdsFromOacIds($bannerIds)
    {
        $doBanners = OA_Dal::factoryDO('banners');
        $doBanners->whereInAdd('oac_banner_id', $bannerIds);
        $doBanners->orderBy('bannerid');
        $doBanners->find();

        $aOacBannerIds = array();
        while ($doBanners->fetch()) {
            $aOacBannerIds[$doBanners->oac_banner_id] = $doBanners->bannerid;
        }

        return $aOacBannerIds;
    }

    /**
     * A method to convert the UTC start and end dates to day/hour
     * and add them to the revenue array.
     *
     * @param array $aRevenue The revenue array
     */
    function revenueAddDayHours(&$aRevenue)
    {
        if (isset($aRevenue['start_day'])) {
            return;
        }

        $aRevenue += array(
            'start_day'  => $aRevenue['start']->format('%Y-%m-%d'),
            'start_hour' => (int)$aRevenue['start']->format('%H'),
            'end_day'    => $aRevenue['end']->format('%Y-%m-%d'),
            'end_hour'   => (int)$aRevenue['end']->format('%H')
        );
    }

    function revenueGetWhereCondition($aParams)
    {
        return "
            (
                (day = '{$aParams['start_day']}' AND hour >= {$aParams['start_hour']}) OR
                (day > '{$aParams['start_day']}' AND day < '{$aParams['end_day']}') OR
                (day = '{$aParams['end_day']}' AND hour <= {$aParams['end_hour']})
            )
        ";
    }

    function revenueGetStats($bannerId, $aParams)
    {
        $this->revenueAddDayHours($aParams);

        $doDsah = OA_Dal::factoryDO('data_summary_ad_hourly');
        $doDsah->ad_id = $bannerId;
        $doDsah->whereAdd($this->revenueGetWhereCondition($aParams));
        $doDsah->orderBy('day, hour');

        $aStats = $doDsah->getAll(array(), true, false);

        return $aStats;
    }

    function revenueGetActiveStats($bannerId, $aParams, $actionType)
    {
        $aStats = $this->revenueGetStats($bannerId, $aParams);

        $aActiveStats = array();
        foreach ($aStats as $key => $row) {
            if ($row[$actionType] > 0) {
                $aActiveStats[$key] = $row[$actionType];
            }
        }

        return $aActiveStats;
    }

    function revenuePerformUpdate($bannerId, $aRevenue, $recursionLevel = 0)
    {
        if ($recursionLevel > 1) {
            return false;
        }

        $actionType = $aRevenue['type'] = 'CPC' ? 'clicks' : 'impressions';

        $aActiveStats = $this->revenueGetActiveStats($bannerId, $aRevenue, $actionType);

        $sumActive = array_sum($aActiveStats);

        $assignedRevenue = 0;
        if ($sumActive) {
            $aActiveRevenues = array();
            foreach ($aActiveStats as $key => $value) {
                $aActiveRevenues[$key] = floor(100 * $value * $aRevenue['revenue'] / $sumActive) / 100;
                $assignedRevenue += $aActiveRevenues[$key];
            }

            if ($aRevenue['revenue'] != $assignedRevenue) {
                end($aActiveRevenues);
                $aActiveRevenues[key($aActiveRevenues)] += $aRevenue['revenue'] - $assignedRevenue;
            }

            return $this->revenueUpdateStats($aActiveRevenues);
        } else {
            if ($this->revenueInsertStats($bannerId, $aRevenue, $actionType)) {
                return $this->revenuePerformUpdate($bannerId, $aRevenue, $actionType, $recursionLevel + 1);
            }
        }

        return false;
    }

    function revenueGetLinkedZones($bannerId)
    {
        $doAza = OA_Dal::factoryDO('ad_zone_assoc');
        $doAza->ad_id = $bannerId;
        $doAza->find();

        $aZoneIds = array();
        while ($doAza->fetch()) {
            $aZoneIds[] = $doAza->zone_id;
        }

        return $aZoneIds;
    }

    function revenueInsertStats($bannerId, $aParams, $actionType)
    {
        $aZoneIds = $this->revenueGetLinkedZones($bannerId);

        $oSpan = new Date_Span('0-1-0-0');
        $oDate = new Date($aParams['start']);

        $i = 0;
        while (!$oDate->after(new Date($aParams['end']))) {
            foreach ($aZoneIds as $zoneId) {
                $doDsah = OA_Dal::factoryDO('data_summary_ad_hourly');
                $doDsah->day     = $oDate->format('%Y-%m-%d');
                $doDsah->hour    = (int)$oDate->format('%H');
                $doDsah->ad_id   = $bannerId;
                $doDsah->zone_id = $zoneId;

                $doDsahClone = clone($doDsah);
                if (!$doDsahClone->count()) {
                    $doDsah->$actionType = 1;
                    $doDsah->updated = OA::getNow();
                    if ($doDsah->insert()) {
                        $i++;
                    }
                }
            }

            $oDate->addSpan($oSpan);
        }

        return $i > 0;
    }

    function revenueClearStats($bannerId, $aParams)
    {
        $this->revenueAddDayHours($aParams);

        $where = $this->revenueGetWhereCondition($aParams);

        $tableDsah = $this->oDbh->quoteIdentifier($GLOBALS['_MAX']['CONF']['table']['prefix'].'data_summary_ad_hourly');

        $result = $this->oDbh->exec("
            UPDATE
                {$tableDsah}
            SET
                total_revenue = 0,
                updated = '".OA::getNow()."'
            WHERE
                ad_id = {$bannerId} AND
                (total_revenue <> 0 OR total_revenue IS NULL) AND
                {$where}
            ");

        return !PEAR::isError($result);
    }

    function revenueUpdateStats($aStats)
    {
        foreach ($aStats as $key => $value) {
            $doDsah = OA_Dal::factoryDO('data_summary_ad_hourly');
            $doDsah->get($key);
            $doDsah->total_revenue = $value;
            $doDsah->updated = OA::getNow();
            $result = $doDsah->update();

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * A method to generate a unique advertiser name
     *
     * @param string $name
     * @return string The unique name
     */
    function getUniqueAdvertiserName($name)
    {
        return $this->_getUniqueName($name, 'clients', 'clientname');
    }

    /**
     * A method to generate a unique campaign name
     *
     * @param string $name
     * @return string The unique name
     */
    function getUniqueCampaignName($name)
    {
        return $this->_getUniqueName($name, 'campaigns', 'campaignname');
    }

    /**
     * A method to generate a unique banner name
     *
     * @param string $name
     * @return string The unique name
     */
    function getUniqueBannerName($name)
    {
        return $this->_getUniqueName($name, 'banners', 'description');
    }

    /**
     * A method to generate a unique publisher name
     *
     * @param string $name
     * @return string The unique name
     */
    function getUniquePublisherName($name)
    {
        return $this->_getUniqueName($name, 'affiliates', 'name');
    }

    /**
     * A method to generate an unique zone name
     *
     * @param string $name
     * @return string The unique name
     */
    function getUniqueZoneName($name)
    {
        return $this->_getUniqueName($name, 'zones', 'zonename');
    }

    /**
     * A generic internal method to generate unique names for entities
     *
     * @param string $name The original name
     * @param string $entityTable The table to look for duplicate names
     * @param string $entityName The field to look for duplicate names
     * @return string The unique name
     */
    function _getUniqueName($name, $entityTable, $entityName)
    {
        $doEntities = OA_Dal::factoryDO($entityTable);
        $doEntities->find();

        $aNames = array();
        while ($doEntities->fetch()) {
            $aNames[] = $doEntities->$entityName;
        }

        if (!in_array($name, $aNames)) {
            return $name;
        }

        $aNumbers = array();
        foreach ($aNames as $value) {
            if (preg_match('/^'.preg_quote($name, '/').' \((\d+)\)$/', $value, $m)) {
                $aNumbers[] = intval($m[1]);
            }
        }

        if (count($aNumbers)) {
            rsort($aNumbers, SORT_NUMERIC);

            $number = current($aNumbers) + 1;
        } else {
            $number = 2;
        }

        return "{$name} ({$number})";
    }
}

?>