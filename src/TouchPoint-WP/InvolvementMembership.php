<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * Connect a Person to an Involvement
 */
class InvolvementMembership
{
    public Person $person;
    public Involvement $involvement;
    public ?string $mt;
    public ?string $at;
    public ?string $description;

    protected int $_iid;
    protected int $_pid;

    /**
     * @param $pid
     * @param $iid
     */
    public function __construct($pid, $iid)
    {
        $this->_pid = intval($pid);
        $this->_iid = intval($iid);
    }

    /**
     * @return Person
     * @throws TouchPointWP_Exception
     */
    public function Person(): Person
    {
        if (! isset($this->person)) {
            throw new TouchPointWP_Exception("Not yet supported");
        }

        return $this->person;
    }

    /**
     * @return Involvement
     * @throws TouchPointWP_Exception
     */
    public function Involvement(): Involvement
    {
        if (! isset($this->involvement)) {
            throw new TouchPointWP_Exception("Not yet supported");
        }

        return $this->involvement;
    }
}