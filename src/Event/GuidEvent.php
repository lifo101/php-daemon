<?php

namespace Lifo\Daemon\Event;


/**
 * Event object for generating a GUID for Shared Memory routines.
 */
class GuidEvent extends DaemonEvent
{
    private ?int    $guid = null;
    private ?string $file;
    private ?string $alias;
    private ?string $filename;

    public function __construct($file = null, string $alias = 'mediator', ?string &$filename = null)
    {
        parent::__construct();
        $this->file = $file;
        $this->alias = $alias;
        $this->filename = &$filename;
    }

    public function getGuid(): ?int
    {
        return $this->guid;
    }

    public function setGuid(?int $guid)
    {
        $this->guid = $guid;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename)
    {
        $this->filename = $filename;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

}