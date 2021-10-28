<?php

class APITaskAssignment {
    const CASE_MANAGER = 24;
    const SERVICE = 47;
    const PATIENT = 39;

    // Private members
    private $teamId;
    private $roleId;
    private $userId;

    public function __construct($roleId = null, $teamId = null, $userId = null) {
        $this->roleId = $roleId;
        $this->teamId = $teamId;
        $this->userId = $userId;
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APITaskAssignment
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $assignment = new APITaskAssignment();
        if ($xmlNode->team) {
            $assignment->teamId = NullableString($xmlNode->team->id);
        }
        if ($xmlNode->role) {
            $assignment->roleId = NullableString($xmlNode->role->id);
        }
        if ($xmlNode->user) {
            $assignment->userId = NullableString($xmlNode->user->id);
        }
        return $assignment;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */

    /**
     *
     * @return string
     */
    public function getTeamId() {
        return $this->teamId;
    }

    /**
     *
     * @return string
     */
    public function getRoleId() {
        return $this->roleId;
    }

    /**
     *
     * @return string
     */
    public function getUserId() {
        return $this->userId;
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */
    /**
     *
     * @param string $value
     */
    public function setTeamId($value) {
        $this->teamId = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setRoleId($value) {
        $this->roleId = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setUserId($value) {
        $this->userId = $value;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */

    /**
     *
     * @param XMLHelper $xml
     * @param SimpleXMLElement $parentNode
     * @return SimpleXMLElement
     */
    public function toXML($xml, $parentNode = null) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }

        $node = $xml->createChildNode($parentNode, "team");
        $xml->createChildNode($node, "id", $this->getTeamId());

        $node = $xml->createChildNode($parentNode, "role");
        $xml->createChildNode($node, "id", $this->getRoleId());

        $node = $xml->createChildNode($parentNode, "user");
        $xml->createChildNode($node, "id", $this->getUserId());

        return $parentNode;
    }
}