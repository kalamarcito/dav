<?php

declare(strict_types=1);

namespace Sabre\CardDAV\Backend;

use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Element\Sharee;

/**
 * PDO CardDAV backend.
 *
 * This CardDAV backend uses PDO to store addressbooks
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class PDO extends AbstractBackend implements SyncSupport, SharingSupport
{
    /**
     * PDO connection.
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * The PDO table name used to store addressbooks.
     */
    public $addressBooksTableName = 'addressbooks';

    /**
     * The PDO table name used to store addressbook instances.
     */
    public $addressBookInstancesTableName = 'addressbookinstances';

    /**
     * The PDO table name used to store cards.
     */
    public $cardsTableName = 'cards';

    /**
     * The table name that will be used for tracking changes in address books.
     *
     * @var string
     */
    public $addressBookChangesTableName = 'addressbookchanges';

    /**
     * Sets up the object.
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Returns the list of addressbooks for a specific user.
     *
     * @param string $principalUri
     *
     * @return array
     */
    public function getAddressBooksForUser($principalUri)
    {
        $fields = 'addressbookid, uri, displayname, principaluri, description, access, permissions';

        $stmt = $this->pdo->prepare(<<<SQL
SELECT {$this->addressBookInstancesTableName}.id as id, $fields, synctoken FROM {$this->addressBookInstancesTableName}
    LEFT JOIN {$this->addressBooksTableName} ON
        {$this->addressBookInstancesTableName}.addressbookid = {$this->addressBooksTableName}.id
WHERE principaluri = ? ORDER BY uri ASC
SQL
        );
        $stmt->execute([$principalUri]);

        $addressBooks = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $addressBook = [
                'id' => [(int) $row['addressbookid'], (int) $row['id']],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{DAV:}displayname' => $row['displayname'],
                '{'.CardDAV\Plugin::NS_CARDDAV.'}addressbook-description' => $row['description'],
                '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
                '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ?: '0',
                'share-resource-uri' => '/ns/share/'.$row['addressbookid'],
            ];

            $addressBook['share-access'] = (int) $row['access'];
            $addressBook['permissions'] = (int) ($row['permissions'] ?? 0);
            // 1 = owner, 2 = readonly, 3 = readwrite
            if ($row['access'] > 1) {
                // read-only is for backwards compatibility. Might go away in
                // the future.
                $addressBook['read-only'] = \Sabre\DAV\Sharing\Plugin::ACCESS_READ === (int) $row['access'];
            }

            $addressBooks[] = $addressBook;
        }

        return $addressBooks;
    }

    /**
     * Updates properties for an address book.
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documentation for more info and examples.
     *
     * @param mixed $addressBookId
     */
    public function updateAddressBook($addressBookId, PropPatch $propPatch)
    {
        if (!is_array($addressBookId)) {
            throw new \InvalidArgumentException('The value passed to $addressBookId is expected to be an array with an addressBookId and an instanceId');
        }
        list($addressBookId, $instanceId) = $addressBookId;

        $supportedProperties = [
            '{DAV:}displayname',
            '{'.CardDAV\Plugin::NS_CARDDAV.'}addressbook-description',
        ];

        $propPatch->handle($supportedProperties, function ($mutations) use ($instanceId) {
            $updates = [];
            foreach ($mutations as $property => $newValue) {
                switch ($property) {
                    case '{DAV:}displayname':
                        $updates['displayname'] = $newValue;
                        break;
                    case '{'.CardDAV\Plugin::NS_CARDDAV.'}addressbook-description':
                        $updates['description'] = $newValue;
                        break;
                }
            }
            $query = 'UPDATE '.$this->addressBookInstancesTableName.' SET ';
            $first = true;
            foreach ($updates as $key => $value) {
                if ($first) {
                    $first = false;
                } else {
                    $query .= ', ';
                }
                $query .= ' '.$key.' = :'.$key.' ';
            }
            $query .= ' WHERE id = :instanceid';

            $stmt = $this->pdo->prepare($query);
            $updates['instanceid'] = $instanceId;

            $stmt->execute($updates);

            return true;
        });
    }

    /**
     * Creates a new address book.
     *
     * @param string $principalUri
     * @param string $url          just the 'basename' of the url
     *
     * @return array [addressBookId, instanceId]
     */
    public function createAddressBook($principalUri, $url, array $properties)
    {
        $fieldNames = [
            'principaluri',
            'uri',
            'addressbookid',
        ];
        $values = [
            ':principaluri' => $principalUri,
            ':uri' => $url,
        ];

        $stmt = $this->pdo->prepare('INSERT INTO '.$this->addressBooksTableName.' (synctoken) VALUES (1)');
        $stmt->execute();

        $addressBookId = $this->pdo->lastInsertId(
            $this->addressBooksTableName.'_id_seq'
        );

        $values[':addressbookid'] = $addressBookId;

        foreach ($properties as $property => $newValue) {
            switch ($property) {
                case '{DAV:}displayname':
                    $values[':displayname'] = $newValue;
                    $fieldNames[] = 'displayname';
                    break;
                case '{'.CardDAV\Plugin::NS_CARDDAV.'}addressbook-description':
                    $values[':description'] = $newValue;
                    $fieldNames[] = 'description';
                    break;
                default:
                    throw new DAV\Exception\BadRequest('Unknown property: '.$property);
            }
        }

        $stmt = $this->pdo->prepare('INSERT INTO '.$this->addressBookInstancesTableName.' ('.implode(', ', $fieldNames).') VALUES ('.implode(', ', array_keys($values)).')');
        $stmt->execute($values);

        return [
            $addressBookId,
            $this->pdo->lastInsertId($this->addressBookInstancesTableName.'_id_seq'),
        ];
    }

    /**
     * Deletes an entire addressbook and all its contents.
     *
     * @param mixed $addressBookId
     */
    public function deleteAddressBook($addressBookId)
    {
        if (!is_array($addressBookId)) {
            throw new \InvalidArgumentException('The value passed to $addressBookId is expected to be an array with an addressBookId and an instanceId');
        }
        list($addressBookId, $instanceId) = $addressBookId;

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->cardsTableName.' WHERE addressbookid = ?');
        $stmt->execute([$addressBookId]);

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->addressBookInstancesTableName.' WHERE id = ?');
        $stmt->execute([$instanceId]);

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->addressBookChangesTableName.' WHERE addressbookid = ?');
        $stmt->execute([$addressBookId]);

        // Only delete from addressbooks table if this was the last instance
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM '.$this->addressBookInstancesTableName.' WHERE addressbookid = ?');
        $stmt->execute([$addressBookId]);
        $instanceCount = $stmt->fetchColumn();

        if (0 == $instanceCount) {
            $stmt = $this->pdo->prepare('DELETE FROM '.$this->addressBooksTableName.' WHERE id = ?');
            $stmt->execute([$addressBookId]);
        }
    }

    /**
     * Returns all cards for a specific addressbook id.
     *
     * This method should return the following properties for each card:
     *   * carddata - raw vcard data
     *   * uri - Some unique url
     *   * lastmodified - A unix timestamp
     *
     * It's recommended to also return the following properties:
     *   * etag - A unique etag. This must change every time the card changes.
     *   * size - The size of the card in bytes.
     *
     * If these last two properties are provided, less time will be spent
     * calculating them. If they are specified, you can also omit carddata.
     * This may speed up certain requests, especially with large cards.
     *
     * @param mixed $addressbookId
     *
     * @return array
     */
    public function getCards($addressbookId)
    {
        if (is_array($addressbookId)) {
            $addressbookId = $addressbookId[0];
        }

        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, size FROM '.$this->cardsTableName.' WHERE addressbookid = ?');
        $stmt->execute([$addressbookId]);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $row['etag'] = '"'.$row['etag'].'"';
            $row['lastmodified'] = (int) $row['lastmodified'];
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Returns a specific card.
     *
     * The same set of properties must be returned as with getCards. The only
     * exception is that 'carddata' is absolutely required.
     *
     * If the card does not exist, you must return false.
     *
     * @param mixed  $addressBookId
     * @param string $cardUri
     *
     * @return array
     */
    public function getCard($addressBookId, $cardUri)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        $stmt = $this->pdo->prepare('SELECT id, carddata, uri, lastmodified, etag, size FROM '.$this->cardsTableName.' WHERE addressbookid = ? AND uri = ? LIMIT 1');
        $stmt->execute([$addressBookId, $cardUri]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        $result['etag'] = '"'.$result['etag'].'"';
        $result['lastmodified'] = (int) $result['lastmodified'];

        return $result;
    }

    /**
     * Returns a list of cards.
     *
     * This method should work identical to getCard, but instead return all the
     * cards in the list as an array.
     *
     * If the backend supports this, it may allow for some speed-ups.
     *
     * @param mixed $addressBookId
     *
     * @return array
     */
    public function getMultipleCards($addressBookId, array $uris)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        $query = 'SELECT id, uri, lastmodified, etag, size, carddata FROM '.$this->cardsTableName.' WHERE addressbookid = ? AND uri IN (';
        // Inserting a whole bunch of question marks
        $query .= implode(',', array_fill(0, count($uris), '?'));
        $query .= ')';

        $stmt = $this->pdo->prepare($query);
        $stmt->execute(array_merge([$addressBookId], $uris));
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $row['etag'] = '"'.$row['etag'].'"';
            $row['lastmodified'] = (int) $row['lastmodified'];
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Creates a new card.
     *
     * The addressbook id will be passed as the first argument. This is the
     * same id as it is returned from the getAddressBooksForUser method.
     *
     * The cardUri is a base uri, and doesn't include the full path. The
     * cardData argument is the vcard body, and is passed as a string.
     *
     * It is possible to return an ETag from this method. This ETag is for the
     * newly created resource, and must be enclosed with double quotes (that
     * is, the string itself must contain the double quotes).
     *
     * You should only return the ETag if you store the carddata as-is. If a
     * subsequent GET request on the same card does not have the same body,
     * byte-by-byte and you did return an ETag here, clients tend to get
     * confused.
     *
     * If you don't return an ETag, you can just return null.
     *
     * @param mixed  $addressBookId
     * @param string $cardUri
     * @param string $cardData
     *
     * @return string|null
     */
    public function createCard($addressBookId, $cardUri, $cardData)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        $stmt = $this->pdo->prepare('INSERT INTO '.$this->cardsTableName.' (carddata, uri, lastmodified, addressbookid, size, etag) VALUES (?, ?, ?, ?, ?, ?)');

        $etag = md5($cardData);

        $stmt->execute([
            $cardData,
            $cardUri,
            time(),
            $addressBookId,
            strlen($cardData),
            $etag,
        ]);

        $this->addChange($addressBookId, $cardUri, 1);

        return '"'.$etag.'"';
    }

    /**
     * Updates a card.
     *
     * The addressbook id will be passed as the first argument. This is the
     * same id as it is returned from the getAddressBooksForUser method.
     *
     * The cardUri is a base uri, and doesn't include the full path. The
     * cardData argument is the vcard body, and is passed as a string.
     *
     * It is possible to return an ETag from this method. This ETag should
     * match that of the updated resource, and must be enclosed with double
     * quotes (that is: the string itself must contain the actual quotes).
     *
     * You should only return the ETag if you store the carddata as-is. If a
     * subsequent GET request on the same card does not have the same body,
     * byte-by-byte and you did return an ETag here, clients tend to get
     * confused.
     *
     * If you don't return an ETag, you can just return null.
     *
     * @param mixed  $addressBookId
     * @param string $cardUri
     * @param string $cardData
     *
     * @return string|null
     */
    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        $stmt = $this->pdo->prepare('UPDATE '.$this->cardsTableName.' SET carddata = ?, lastmodified = ?, size = ?, etag = ? WHERE uri = ? AND addressbookid =?');

        $etag = md5($cardData);
        $stmt->execute([
            $cardData,
            time(),
            strlen($cardData),
            $etag,
            $cardUri,
            $addressBookId,
        ]);

        $this->addChange($addressBookId, $cardUri, 2);

        return '"'.$etag.'"';
    }

    /**
     * Deletes a card.
     *
     * @param mixed  $addressBookId
     * @param string $cardUri
     *
     * @return bool
     */
    public function deleteCard($addressBookId, $cardUri)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->cardsTableName.' WHERE addressbookid = ? AND uri = ?');
        $stmt->execute([$addressBookId, $cardUri]);

        $this->addChange($addressBookId, $cardUri, 3);

        return 1 === $stmt->rowCount();
    }

    /**
     * The getChanges method returns all the changes that have happened, since
     * the specified syncToken in the specified address book.
     *
     * This function should return an array, such as the following:
     *
     * [
     *   'syncToken' => 'The current synctoken',
     *   'added'   => [
     *      'new.txt',
     *   ],
     *   'modified'   => [
     *      'updated.txt',
     *   ],
     *   'deleted' => [
     *      'foo.php.bak',
     *      'old.txt'
     *   ]
     * ];
     *
     * The returned syncToken property should reflect the *current* syncToken
     * of the addressbook, as reported in the {http://sabredav.org/ns}sync-token
     * property. This is needed here too, to ensure the operation is atomic.
     *
     * If the $syncToken argument is specified as null, this is an initial
     * sync, and all members should be reported.
     *
     * The modified property is an array of nodenames that have changed since
     * the last token.
     *
     * The deleted property is an array with nodenames, that have been deleted
     * from collection.
     *
     * The $syncLevel argument is basically the 'depth' of the report. If it's
     * 1, you only have to report changes that happened only directly in
     * immediate descendants. If it's 2, it should also include changes from
     * the nodes below the child collections. (grandchildren)
     *
     * The $limit argument allows a client to specify how many results should
     * be returned at most. If the limit is not specified, it should be treated
     * as infinite.
     *
     * If the limit (infinite or not) is higher than you're willing to return,
     * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
     *
     * If the syncToken is expired (due to data cleanup) or unknown, you must
     * return null.
     *
     * The limit is 'suggestive'. You are free to ignore it.
     *
     * @param mixed  $addressBookId
     * @param string $syncToken
     * @param int    $syncLevel
     * @param int    $limit
     *
     * @return array|null
     */
    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        // Current synctoken
        $stmt = $this->pdo->prepare('SELECT synctoken FROM '.$this->addressBooksTableName.' WHERE id = ?');
        $stmt->execute([$addressBookId]);
        $currentToken = $stmt->fetchColumn(0);

        if (is_null($currentToken)) {
            return null;
        }

        $result = [
            'syncToken' => $currentToken,
            'added' => [],
            'modified' => [],
            'deleted' => [],
        ];

        if ($syncToken) {
            $query = 'SELECT uri, operation FROM '.$this->addressBookChangesTableName.' WHERE synctoken >= ? AND synctoken < ? AND addressbookid = ? ORDER BY synctoken';
            if ($limit > 0) {
                $query .= ' LIMIT '.(int) $limit;
            }

            // Fetching all changes
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$syncToken, $currentToken, $addressBookId]);

            $changes = [];

            // This loop ensures that any duplicates are overwritten, only the
            // last change on a node is relevant.
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $changes[$row['uri']] = $row['operation'];
            }

            foreach ($changes as $uri => $operation) {
                switch ($operation) {
                    case 1:
                        $result['added'][] = $uri;
                        break;
                    case 2:
                        $result['modified'][] = $uri;
                        break;
                    case 3:
                        $result['deleted'][] = $uri;
                        break;
                }
            }
        } else {
            // No synctoken supplied, this is the initial sync.
            $query = 'SELECT uri FROM '.$this->cardsTableName.' WHERE addressbookid = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$addressBookId]);

            $result['added'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $result;
    }

    /**
     * Updates the list of shares.
     *
     * @param mixed                           $addressBookId
     * @param \Sabre\DAV\Xml\Element\Sharee[] $sharees
     */
    public function updateInvites($addressBookId, array $sharees)
    {
        if (!is_array($addressBookId)) {
            throw new \InvalidArgumentException('The value passed to $addressBookId is expected to be an array with an addressBookId and an instanceId');
        }
        $currentInvites = $this->getInvites($addressBookId);
        list($addressBookId, $instanceId) = $addressBookId;

        $removeStmt = $this->pdo->prepare('DELETE FROM '.$this->addressBookInstancesTableName.' WHERE addressbookid = ? AND share_href = ? AND access IN (2,3)');
        $updateStmt = $this->pdo->prepare('UPDATE '.$this->addressBookInstancesTableName.' SET access = ?, share_displayname = ?, share_invitestatus = ? WHERE addressbookid = ? AND share_href = ?');

        $insertStmt = $this->pdo->prepare('
INSERT INTO '.$this->addressBookInstancesTableName.'
    (
        addressbookid,
        principaluri,
        access,
        displayname,
        uri,
        description,
        share_href,
        share_displayname,
        share_invitestatus
    )
    SELECT
        ?,
        ?,
        ?,
        displayname,
        ?,
        description,
        ?,
        ?,
        ?
    FROM '.$this->addressBookInstancesTableName.' WHERE id = ?');

        foreach ($sharees as $sharee) {
            if (\Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS === $sharee->access) {
                // if access was set no NOACCESS, it means access for an
                // existing sharee was removed.
                $removeStmt->execute([$addressBookId, $sharee->href]);
                continue;
            }

            if (is_null($sharee->principal)) {
                // If the server could not determine the principal automatically,
                // we will mark the invite status as invalid.
                $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_INVALID;
            } else {
                // Because sabre/dav does not yet have an invitation system,
                // every invite is automatically accepted for now.
                $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED;
            }

            foreach ($currentInvites as $oldSharee) {
                if ($oldSharee->href === $sharee->href) {
                    // This is an update
                    $sharee->properties = array_merge(
                        $oldSharee->properties,
                        $sharee->properties
                    );
                    $updateStmt->execute([
                        $sharee->access,
                        isset($sharee->properties['{DAV:}displayname']) ? $sharee->properties['{DAV:}displayname'] : null,
                        $sharee->inviteStatus ?: $oldSharee->inviteStatus,
                        $addressBookId,
                        $sharee->href,
                    ]);
                    continue 2;
                }
            }
            // If we got here, it means it was a new sharee
            $insertStmt->execute([
                $addressBookId,
                $sharee->principal,
                $sharee->access,
                \Sabre\DAV\UUIDUtil::getUUID(),
                $sharee->href,
                isset($sharee->properties['{DAV:}displayname']) ? $sharee->properties['{DAV:}displayname'] : null,
                $sharee->inviteStatus ?: \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE,
                $instanceId,
            ]);
        }
    }

    /**
     * Returns the list of people whom this address book is shared with.
     *
     * Every item in the returned list must be a Sharee object with at
     * least the following properties set:
     *   $href
     *   $shareAccess
     *   $inviteStatus
     *
     * and optionally:
     *   $properties
     *
     * @param mixed $addressBookId
     *
     * @return \Sabre\DAV\Xml\Element\Sharee[]
     */
    public function getInvites($addressBookId)
    {
        if (!is_array($addressBookId)) {
            throw new \InvalidArgumentException('The value passed to getInvites() is expected to be an array with an addressBookId and an instanceId');
        }
        list($addressBookId, $instanceId) = $addressBookId;

        $query = <<<SQL
SELECT
    principaluri,
    access,
    share_href,
    share_displayname,
    share_invitestatus
FROM {$this->addressBookInstancesTableName}
WHERE
    addressbookid = ?
SQL;

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$addressBookId]);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = new Sharee([
                'href' => isset($row['share_href']) ? $row['share_href'] : \Sabre\HTTP\encodePath($row['principaluri']),
                'access' => (int) $row['access'],
                /// Everyone is always immediately accepted, for now.
                'inviteStatus' => (int) $row['share_invitestatus'],
                'properties' => !empty($row['share_displayname'])
                    ? ['{DAV:}displayname' => $row['share_displayname']]
                    : [],
                'principal' => $row['principaluri'],
            ]);
        }

        return $result;
    }

    /**
     * Adds a change record to the addressbookchanges table.
     *
     * @param mixed  $addressBookId
     * @param string $objectUri
     * @param int    $operation     1 = add, 2 = modify, 3 = delete
     */
    protected function addChange($addressBookId, $objectUri, $operation)
    {
        if (is_array($addressBookId)) {
            $addressBookId = $addressBookId[0];
        }

        $stmt = $this->pdo->prepare('INSERT INTO '.$this->addressBookChangesTableName.' (uri, synctoken, addressbookid, operation) SELECT ?, synctoken, ?, ? FROM '.$this->addressBooksTableName.' WHERE id = ?');
        $stmt->execute([
            $objectUri,
            $addressBookId,
            $operation,
            $addressBookId,
        ]);
        $stmt = $this->pdo->prepare('UPDATE '.$this->addressBooksTableName.' SET synctoken = synctoken + 1 WHERE id = ?');
        $stmt->execute([
            $addressBookId,
        ]);
    }
}