<?php
/**
 * Tine 2.0
 * 
 * @package     Tinebase
 * @subpackage  Timemachine 
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2007-2008 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 *
 */


/**
 * Timemachine works on record basis and supplies records as they where at a
 * given point in history. Moreover it answers the question which records have
 * been added, modiefied or deleted in a given timespan.
 * 
 * This are the most important usecases timemachine is designed for:
 * - Provide a consistent data view for a given time. This is important for 
 *   syncronisation engines like syncML.
 * - Provide history information, which are needed to implement a sophisticated
 *   concurrency management on field basis.
 * - Provide datas for record history investigations.
 * - Provide datas for desaster recovery.
 * 
 * Tinebase_Timemachine interfaces/classes build a framework, which needs to be 
 * implemented/extended by the backends of an application.
 * 
 * NOTE: Timespans are allways defined, with the beginning point excluded and
 * the end point included. Mathematical: (_from, _until]
 * NOTE: Records _at_ a given point in history include changes which contingently
 * where made _at_ the end of time resolution of this point
 * 
 * @package Tinebase
 * @subpackage Timemachine
 */
abstract class Tinebase_Timemachine_Abstract implements Tinebase_Timemachine_Interface
{
    /**
     * Table name for the application with the following columns:
     *   identifier ,created_by, creation_time, last_modified_by, last_modified_time,
     *   is_deleted, deleted_time, deleted_by
     */
    protected $_timemachine_tablename;
    
    /**
     * Holds an instance of the corresponding table
     * 
     * @var Tinebase_Db_Table
     */
    protected $_timemachine_table;
    
    /**
     * Holds ModificationLog instance
     * 
     * @var Tinebase_Timemachine_ModificationLog
     */
    private $_modificationLog;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_timemachine_table = new Tinebase_Db_Table(array(
            'name' => $this->_timemachine_tablename
        ));
    }
    
    /**
     * Returns ids(strings) of records which where created in a given timespan.
     * 
     * @param Tinebase_DateTime _from beginning point of timespan, excluding point itself
     * @param Tinebase_DateTime _until end point of timespan, included point itself
     * @param Tinebase_Record_Filter _filter
     * @return array array of identifiers
     * @access public
     */
    public function getCreated( Tinebase_DateTime $_from, Tinebase_DateTime $_until, Tinebase_Record_Filter $_filter )
    {
        
    }
    
    /**
     * Returns uids(strings) of records which where modified in a given timespan.
     * 
     * @param Tinebase_DateTime _from beginning point of timespan, excluding point itself
     * @param Tinebase_DateTime _until end point of timespan, included point itself
     * @param Tinebase_Record_Filter _filter
     * @return array array of identifiers
     * @access public
     */
    public function getModified( Tinebase_DateTime $_from, Tinebase_DateTime $_until, Tinebase_Record_Filter $_filter )
    {
        
    }
    
    /**
     * Returns ids(strings) of records which where deleted in a given timespan.
     * 
     * @param Tinebase_DateTime _from beginning point of timespan, including point itself
     * @param Tinebase_DateTime _until end point of timespan, included point itself
     * @param Tinebase_Record_Filter _filter
     * @return array array of identifiers
     * @access public
     */
    public function getDeleted( Tinebase_DateTime $_from, Tinebase_DateTime $_until, Tinebase_Record_Filter $_filter )
    {
        
    }
    
    /**
     * Returns a record as it was at a given point in history
     * 
     * @param string _id 
     * @param Tinebase_DateTime _at 
     * @return Tinebase_Record
     * @access public
     */
    public function getRecord( $_id,  Tinebase_DateTime $_at );
    
    /**
     * Returns a set of records as they where at a given point in history
     * 
     * @param array _ids array of strings 
     * @param Tinebase_DateTime _at 
     * @return Tinebase_Record_RecordSet
     * @access public
     */
    public function getRecords( array $_ids,  Tinebase_DateTime $_at );
    
    /**
     * Returns instance of Tinebase_Timemachine_ModificationLog
     * (only create instace on demand)
     * 
     * @return Tinebase_Timemachine_ModificationLog
     */
    protected function getModificationLog()
    {
        if (!$this->_modificationLog) {
            $this->_modificationLog = Tinebase_Timemachine_ModificationLog::getInstance();
        }
        
        return $this->_modificationLog;
    }
    
} // end of Tinebase_Timemachine_Interface
?>
