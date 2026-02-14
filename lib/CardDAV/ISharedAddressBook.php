<?php

declare(strict_types=1);

namespace Sabre\CardDAV;

use Sabre\DAV\Sharing\ISharedNode;

/**
 * This interface represents an Address Book that is shared by a different user.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface ISharedAddressBook extends ISharedNode
{
}