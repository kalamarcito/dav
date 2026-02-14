<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

use Sabre\DAV\Sharing\Plugin as SPlugin;

/**
 * This object represents a CardDAV address book that is shared by a different user.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class SharedAddressBook extends AddressBook implements ISharedAddressBook
{
    /**
     * Returns the 'access level' for the instance of this shared resource.
     *
     * The value should be one of the Sabre\DAV\Sharing\Plugin::ACCESS_
     * constants.
     *
     * @return int
     */
    public function getShareAccess()
    {
        return isset($this->addressBookInfo['share-access']) ? $this->addressBookInfo['share-access'] : SPlugin::ACCESS_NOTSHARED;
    }

    /**
     * This function must return a URI that uniquely identifies the shared
     * resource. This URI should be identical across instances, and is
     * also used in several other XML bodies to connect invites to
     * resources.
     *
     * This may simply be a relative reference to the original shared instance,
     * but it could also be a urn. As long as it's a valid URI and unique.
     *
     * @return string
     */
    public function getShareResourceUri()
    {
        return $this->addressBookInfo['share-resource-uri'];
    }

    /**
     * Updates the list of sharees.
     *
     * Every item must be a Sharee object.
     *
     * @param \Sabre\DAV\Xml\Element\Sharee[] $sharees
     */
    public function updateInvites(array $sharees)
    {
        $this->carddavBackend->updateInvites($this->addressBookInfo['id'], $sharees);
    }

    /**
     * Returns the list of people whom this resource is shared with.
     *
     * Every item in the returned array must be a Sharee object with
     * at least the following properties set:
     *
     * * $href
     * * $shareAccess
     * * $inviteStatus
     *
     * and optionally:
     *
     * * $properties
     *
     * @return \Sabre\DAV\Xml\Element\Sharee[]
     */
    public function getInvites()
    {
        return $this->carddavBackend->getInvites($this->addressBookInfo['id']);
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    public function getACL()
    {
        $acl = [];

        switch ($this->getShareAccess()) {
            case SPlugin::ACCESS_NOTSHARED:
            case SPlugin::ACCESS_SHAREDOWNER:
                $acl[] = [
                    'privilege' => '{DAV:}share',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ];
                // no break intentional!
            case SPlugin::ACCESS_READWRITE:
                $acl[] = [
                    'privilege' => '{DAV:}write',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ];
                // no break intentional!
            case SPlugin::ACCESS_READ:
                $acl[] = [
                    'privilege' => '{DAV:}write-properties',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ];
                break;
        }

        return $acl;
    }

    /**
     * This method returns the ACL's for address book objects in this address book.
     * The result of this method automatically gets passed to the
     * card nodes in this address book.
     *
     * @return array
     */
    public function getChildACL()
    {
        $acl = [];

        switch ($this->getShareAccess()) {
            case SPlugin::ACCESS_NOTSHARED:
            case SPlugin::ACCESS_SHAREDOWNER:
            case SPlugin::ACCESS_READWRITE:
                $acl[] = [
                    'privilege' => '{DAV:}write',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ];
                // no break intentional
            case SPlugin::ACCESS_READ:
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ];
                break;
        }

        return $acl;
    }
}