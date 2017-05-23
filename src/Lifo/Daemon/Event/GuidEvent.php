<?php

namespace Lifo\Daemon\Event;


/**
 * Event object for generating a GUID for Shared Memory routines.
 */
class GuidEvent extends DaemonEvent
{
    /**
     * @var int
     */
    private $guid = null;
    /**
     * @var string
     */
    private $file;
    /**
     * @var string
     */
    private $alias;
    /**
     * @var string
     */
    private $filename;

    public function __construct($file = null, $alias = 'mediator', &$filename = null)
    {
        parent::__construct();
        $this->file = $file;
        $this->alias = $alias;
        $this->filename = &$filename;
    }

    /**
     * @return int
     */
    public function getGuid()
    {
        return $this->guid;
    }

    /**
     * @param int $guid
     */
    public function setGuid($guid)
    {
        $this->guid = $guid;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * @param string $filename
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;
    }

    /**
     * @return string
     */
    public function getFile()
    {
        return $this->file;
    }

}